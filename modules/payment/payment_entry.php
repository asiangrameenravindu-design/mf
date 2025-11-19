<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// First, ensure the loan_payments table has the required columns
ensurePaymentTableColumns();

// Get CBOs for filter
$cbos = getAllCBOs();

// Initialize variables
$cbo_id = $_GET['cbo_id'] ?? '';
$group_id = $_GET['group_id'] ?? '';
$payment_date = $_GET['payment_date'] ?? date('Y-m-d');

// Get disbursed loans for payment with detailed balance calculation
$disbursed_loans = [];
$total_group_amount = 0;
$total_cbo_amount = 0;

if ($cbo_id) {
    $sql = "SELECT l.*, c.full_name, c.national_id, g.group_number, g.group_name,
                   cb.name as cbo_name,
                   (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND lp.reversal_status != 'reversal' AND lp.reversal_status != 'reversed' AND lp.amount > 0), 0)) as remaining_balance,
                   (SELECT COUNT(*) FROM loan_installments li 
                    WHERE li.loan_id = l.id AND li.status IN ('pending', 'partial')) as pending_installments,
                   COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND lp.reversal_status != 'reversal' AND lp.reversal_status != 'reversed' AND lp.amount > 0), 0) as total_paid_amount,
                   l.total_loan_amount,
                   l.weekly_installment,
                   l.balance as current_balance
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            LEFT JOIN group_members gm ON c.id = gm.customer_id
            LEFT JOIN groups g ON gm.group_id = g.id AND g.is_active = 1
            WHERE (l.status = 'disbursed' OR l.status = 'completed' OR l.status = 'active')
            AND l.cbo_id = ?";
    
    $params = [$cbo_id];
    $types = 'i';
    
    if ($group_id) {
        $sql .= " AND g.id = ?";
        $params[] = $group_id;
        $types .= 'i';
    }
    
    $sql .= " ORDER BY g.group_number, c.full_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($loan = $result->fetch_assoc()) {
        // Ensure all amount fields have values
        $loan['remaining_balance'] = floatval($loan['remaining_balance'] ?? 0);
        $loan['pending_installments'] = intval($loan['pending_installments'] ?? 0);
        $loan['amount'] = floatval($loan['amount'] ?? 0);
        $loan['weekly_installment'] = floatval($loan['weekly_installment'] ?? 0);
        $loan['total_paid_amount'] = floatval($loan['total_paid_amount'] ?? 0);
        $loan['total_loan_amount'] = floatval($loan['total_loan_amount'] ?? $loan['amount'] ?? 0);
        $loan['current_balance'] = floatval($loan['current_balance'] ?? $loan['remaining_balance'] ?? 0);
        
        // Only show loans that have remaining balance or are still disbursed
        if ($loan['remaining_balance'] > 0 || $loan['status'] === 'disbursed' || $loan['status'] === 'active') {
            $disbursed_loans[] = $loan;
            $total_cbo_amount += $loan['remaining_balance'];
            
            if ($group_id) {
                $total_group_amount += $loan['remaining_balance'];
            }
        }
    }
}

// Process payment - INSTALLMENT-BASED PAYMENT PROCESSING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $payment_data = $_POST['payment'] ?? [];
    $payment_date = $_POST['payment_date'];
    
    try {
        $conn->begin_transaction();
        
        $processed_payments = 0;
        $total_payment_amount = 0;
        $settled_loans = [];
        
        foreach ($payment_data as $loan_id => $amount) {
            // Validate amount - ensure it's not empty and greater than 0
            if (!empty($amount) && floatval($amount) > 0) {
                $payment_amount = floatval($amount);
                $total_payment_amount += $payment_amount;
                
                // Get loan details
                $loan_sql = "SELECT l.*, c.full_name, l.weekly_installment, l.total_loan_amount,
                            (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND lp.reversal_status != 'reversal' AND lp.reversal_status != 'reversed' AND lp.amount > 0), 0)) as current_balance
                            FROM loans l 
                            JOIN customers c ON l.customer_id = c.id 
                            WHERE l.id = ? AND (l.status = 'disbursed' OR l.status = 'completed' OR l.status = 'active')";
                $loan_stmt = $conn->prepare($loan_sql);
                $loan_stmt->bind_param("i", $loan_id);
                $loan_stmt->execute();
                $loan_result = $loan_stmt->get_result();
                $loan = $loan_result->fetch_assoc();
                
                if ($loan && $payment_amount > 0) {
                    $remaining_payment = $payment_amount;
                    
                    // Generate unique payment reference
                    $payment_reference = 'PAY' . date('YmdHis') . rand(100, 999);
                    
                    // Get ALL pending and partial installments in order (oldest first)
                    $installments_sql = "SELECT * FROM loan_installments 
                                       WHERE loan_id = ? AND status IN ('pending', 'partial')
                                       ORDER BY installment_number ASC";
                    $installments_stmt = $conn->prepare($installments_sql);
                    $installments_stmt->bind_param("i", $loan_id);
                    $installments_stmt->execute();
                    $installments_result = $installments_stmt->get_result();
                    
                    $installment_payments = [];
                    
                    // Process payment across multiple installments
                    while (($installment = $installments_result->fetch_assoc()) !== null && $remaining_payment > 0) {
                        $installment_amount = floatval($installment['amount'] ?? 0);
                        $installment_id = intval($installment['id'] ?? 0);
                        $paid_so_far = floatval($installment['paid_amount'] ?? 0);
                        $remaining_installment = $installment_amount - $paid_so_far;
                        
                        if ($remaining_payment >= $remaining_installment) {
                            // Pay the full remaining installment and mark as paid
                            $paid_amount = $remaining_installment;
                            $status = 'paid';
                        } else {
                            // Pay partial of the installment
                            $paid_amount = $remaining_payment;
                            $status = 'partial';
                        }
                        
                        // Ensure paid_amount is valid
                        if ($paid_amount > 0 && $installment_id > 0) {
                            $new_paid_amount = $paid_so_far + $paid_amount;
                            
                            // Update installment
                            $update_sql = "UPDATE loan_installments 
                                          SET paid_amount = ?, 
                                              payment_date = ?,
                                              status = ?,
                                              updated_at = CURRENT_TIMESTAMP
                                          WHERE id = ?";
                            $stmt = $conn->prepare($update_sql);
                            $stmt->bind_param("dssi", $new_paid_amount, $payment_date, $status, $installment_id);
                            
                            if ($stmt->execute()) {
                                // Record payment - FIXED: Add created_by column
                                $check_column_sql = "SHOW COLUMNS FROM loan_payments LIKE 'installment_id'";
                                $check_result = $conn->query($check_column_sql);
                                
                                if ($check_result->num_rows > 0) {
                                    // Column exists, use new structure with created_by
                                    $payment_sql = "INSERT INTO loan_payments 
                                                   (loan_id, installment_id, amount, payment_date, payment_method, received_by, payment_reference, created_by, created_at)
                                                   VALUES (?, ?, ?, ?, 'cash', ?, ?, ?, NOW())";
                                    $stmt = $conn->prepare($payment_sql);
                                    $current_user_id = $_SESSION['user_id'] ?? 1;
                                    $stmt->bind_param("iidsisi", $loan_id, $installment_id, $paid_amount, $payment_date, $current_user_id, $payment_reference, $current_user_id);
                                } else {
                                    // Column doesn't exist, use old structure with created_by
                                    $payment_sql = "INSERT INTO loan_payments 
                                                   (loan_id, amount, payment_date, created_by, created_at)
                                                   VALUES (?, ?, ?, ?, NOW())";
                                    $stmt = $conn->prepare($payment_sql);
                                    $current_user_id = $_SESSION['user_id'] ?? 1;
                                    $stmt->bind_param("idsi", $loan_id, $paid_amount, $payment_date, $current_user_id);
                                }
                                
                                if ($stmt->execute()) {
                                    $installment_payments[] = [
                                        'installment_id' => $installment_id,
                                        'amount' => $paid_amount,
                                        'installment_number' => $installment['installment_number']
                                    ];
                                    
                                    $remaining_payment -= $paid_amount;
                                    $processed_payments++;
                                    
                                    // Log activity for each installment payment
                                    logActivity($_SESSION['user_id'], 'installment_paid', 
                                              "Installment #{$installment['installment_number']} paid for {$loan['full_name']} - Loan: {$loan['loan_number']} - Amount: Rs. " . number_format($paid_amount, 2));
                                }
                            }
                        }
                        
                        // Break if no remaining payment
                        if ($remaining_payment <= 0) {
                            break;
                        }
                    }
                    
                    // UPDATE LOAN BALANCE IN LOANS TABLE - THIS IS THE KEY FIX
                    $update_balance_sql = "UPDATE loans SET 
                                          balance = balance - ?,
                                          updated_at = CURRENT_TIMESTAMP
                                          WHERE id = ?";
                    $update_balance_stmt = $conn->prepare($update_balance_sql);
                    $update_balance_stmt->bind_param("di", $payment_amount, $loan_id);
                    $update_balance_stmt->execute();
                    
                    // Log main payment activity with all details
                    $payment_details = "";
                    foreach ($installment_payments as $ip) {
                        $payment_details .= "Installment #{$ip['installment_number']}: Rs. " . number_format($ip['amount'], 2) . ", ";
                    }
                    $payment_details = rtrim($payment_details, ", ");
                    
                    logActivity($_SESSION['user_id'], 'payment_received', 
                              "Payment received from {$loan['full_name']} for loan {$loan['loan_number']} - Total: Rs. " . number_format($payment_amount, 2) . " - Details: {$payment_details} - Reference: {$payment_reference}");
                    
                    // Check if loan is fully paid using total_loan_amount
                    $check_balance_sql = "SELECT (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND lp.reversal_status != 'reversal' AND lp.reversal_status != 'reversed' AND lp.amount > 0), 0)) as remaining_balance 
                                         FROM loans l WHERE l.id = ?";
                    $check_balance_stmt = $conn->prepare($check_balance_sql);
                    $check_balance_stmt->bind_param("i", $loan_id);
                    $check_balance_stmt->execute();
                    $check_balance_result = $check_balance_stmt->get_result();
                    $remaining_balance_data = $check_balance_result->fetch_assoc();
                    $final_balance = floatval($remaining_balance_data['remaining_balance'] ?? 0);
                    
                    if ($final_balance <= 0) {
                        // Loan is fully paid - update loan status to completed
                        $update_loan_sql = "UPDATE loans SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                        $update_loan_stmt = $conn->prepare($update_loan_sql);
                        $update_loan_stmt->bind_param("i", $loan_id);
                        
                        if ($update_loan_stmt->execute()) {
                            $settled_loans[] = $loan['loan_number'];
                            logActivity($_SESSION['user_id'], 'loan_settled', 
                                      "Loan {$loan['loan_number']} fully settled for {$loan['full_name']}");
                        }
                    }
                }
            }
        }
        
        $conn->commit();
        
        // Prepare success message
        $message = "Successfully processed payments! Total: Rs. " . number_format($total_payment_amount, 2);
        if (!empty($settled_loans)) {
            $message .= " - Settled loans: " . implode(', ', $settled_loans);
        }
        
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = "success";
        
        // Clear output buffer before redirect
        if (ob_get_length()) {
            ob_end_clean();
        }
        header("Location: payment_entry.php?cbo_id=$cbo_id&group_id=$group_id&payment_date=$payment_date");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message'] = "Error processing payments: " . $e->getMessage();
        $_SESSION['flash_type'] = "danger";
    }
}

/**
 * Ensure loan_payments table has required columns
 */
function ensurePaymentTableColumns() {
    global $conn;
    
    // Check and add installment_id column if missing
    $check_sql = "SHOW COLUMNS FROM loan_payments LIKE 'installment_id'";
    $result = $conn->query($check_sql);
    
    if ($result->num_rows == 0) {
        // Add missing columns
        $alter_sql = "ALTER TABLE loan_payments 
                     ADD COLUMN installment_id INT NULL AFTER loan_id,
                     ADD COLUMN payment_method ENUM('cash', 'cheque', 'bank_transfer') DEFAULT 'cash',
                     ADD COLUMN received_by INT NULL,
                     ADD COLUMN payment_reference VARCHAR(100) NULL,
                     ADD COLUMN notes TEXT NULL";
        
        try {
            if ($conn->query($alter_sql) === TRUE) {
                error_log("Added missing columns to loan_payments table");
            }
        } catch (Exception $e) {
            error_log("Error adding columns to loan_payments table: " . $e->getMessage());
        }
    }
    
    // Also ensure created_by column exists
    $check_created_by_sql = "SHOW COLUMNS FROM loan_payments LIKE 'created_by'";
    $created_by_result = $conn->query($check_created_by_sql);
    
    if ($created_by_result->num_rows == 0) {
        // Add created_by column
        $alter_created_by_sql = "ALTER TABLE loan_payments ADD COLUMN created_by INT NULL AFTER payment_reference";
        try {
            if ($conn->query($alter_created_by_sql) === TRUE) {
                error_log("Added created_by column to loan_payments table");
            }
        } catch (Exception $e) {
            error_log("Error adding created_by column to loan_payments table: " . $e->getMessage());
        }
    }
    
    // Also ensure reversal_status column exists
    $check_reversal_sql = "SHOW COLUMNS FROM loan_payments LIKE 'reversal_status'";
    $reversal_result = $conn->query($check_reversal_sql);
    
    if ($reversal_result->num_rows == 0) {
        // Add reversal_status column
        $alter_reversal_sql = "ALTER TABLE loan_payments 
                              ADD COLUMN reversal_status ENUM('original', 'reversal', 'reversed') DEFAULT 'original' AFTER payment_reference";
        try {
            if ($conn->query($alter_reversal_sql) === TRUE) {
                error_log("Added reversal_status column to loan_payments table");
            }
        } catch (Exception $e) {
            error_log("Error adding reversal_status column to loan_payments table: " . $e->getMessage());
        }
    }
    
    // Also ensure loans table has 'completed' status
    $check_loan_status = "SHOW COLUMNS FROM loans LIKE 'status'";
    $status_result = $conn->query($check_loan_status);
    if ($status_result->num_rows > 0) {
        // Check if 'completed' is in the enum
        $check_enum = "SELECT COLUMN_TYPE FROM information_schema.COLUMNS 
                      WHERE TABLE_NAME = 'loans' AND COLUMN_NAME = 'status'";
        $enum_result = $conn->query($check_enum);
        if ($enum_row = $enum_result->fetch_assoc()) {
            if (strpos($enum_row['COLUMN_TYPE'], 'completed') === false) {
                // Add completed status
                $alter_loan_sql = "ALTER TABLE loans 
                                 MODIFY COLUMN status ENUM('pending','approved','disbursed','rejected','completed','active') DEFAULT 'pending'";
                $conn->query($alter_loan_sql);
            }
        }
    }
    
    // Ensure loans table has balance column
    $check_balance_sql = "SHOW COLUMNS FROM loans LIKE 'balance'";
    $balance_result = $conn->query($check_balance_sql);
    if ($balance_result->num_rows == 0) {
        // Add balance column
        $alter_balance_sql = "ALTER TABLE loans ADD COLUMN balance DECIMAL(12,2) DEFAULT 0.00 AFTER total_loan_amount";
        try {
            if ($conn->query($alter_balance_sql) === TRUE) {
                error_log("Added balance column to loans table");
                
                // Initialize balance for existing loans
                $init_balance_sql = "UPDATE loans SET balance = total_loan_amount WHERE balance = 0 OR balance IS NULL";
                $conn->query($init_balance_sql);
            }
        } catch (Exception $e) {
            error_log("Error adding balance column to loans table: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Entry - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .payment-table th {
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
        }
        .group-header {
            background-color: #e3f2fd !important;
            font-weight: bold;
        }
        .amount-due {
            color: #dc3545;
            font-weight: bold;
        }
        .amount-paid {
            color: #28a745;
            font-weight: bold;
        }
        .customer-row:hover {
            background-color: #f8f9fa;
        }
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .payment-total {
            background-color: #d1ecf1 !important;
            font-weight: bold;
        }
        .payment-input {
            width: 130px !important;
        }
        .loan-settled {
            background-color: #d4edda !important;
        }
        .balance-info {
            font-size: 0.85em;
            color: #6c757d;
        }
        .payment-amount:disabled {
            background-color: #e9ecef;
            opacity: 1;
        }
        .payment-history {
            font-size: 0.8em;
        }
        .real-time-update {
            background-color: #fff3cd !important;
            transition: background-color 0.5s ease;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Flash Message -->
            <?php $flash = getFlashMessage(); ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show mt-3">
                    <?php echo $flash['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-credit-card"></i> Payment Entry
                </h1>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i> Filter Customers
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="cbo_id" class="form-label">CBO</label>
                            <select class="form-select" id="cbo_id" name="cbo_id" required>
                                <option value="">Select CBO</option>
                                <?php while ($cbo = $cbos->fetch_assoc()): ?>
                                    <option value="<?php echo $cbo['id']; ?>" 
                                        <?php echo $cbo_id == $cbo['id'] ? 'selected' : ''; ?>>
                                        <?php echo $cbo['name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <?php if ($cbo_id): ?>
                        <div class="col-md-4">
                            <label for="group_id" class="form-label">Group</label>
                            <?php
                            $groups_sql = "SELECT * FROM groups WHERE cbo_id = ? AND is_active = 1 ORDER BY group_number";
                            $groups_stmt = $conn->prepare($groups_sql);
                            $groups_stmt->bind_param("i", $cbo_id);
                            $groups_stmt->execute();
                            $groups = $groups_stmt->get_result();
                            ?>
                            <select class="form-select" id="group_id" name="group_id">
                                <option value="">All Groups</option>
                                <?php while ($group = $groups->fetch_assoc()): ?>
                                    <option value="<?php echo $group['id']; ?>" 
                                        <?php echo $group_id == $group['id'] ? 'selected' : ''; ?>>
                                        Group <?php echo $group['group_number']; ?> - <?php echo $group['group_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-4">
                            <label for="payment_date" class="form-label">Payment Date</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                   value="<?php echo $payment_date; ?>" required>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Search
                            </button>
                            <a href="payment_entry.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payment Form -->
            <?php if (!empty($disbursed_loans)): ?>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="payment_date" value="<?php echo $payment_date; ?>">
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list-check"></i> Payment Collection
                            <?php if ($group_id): ?>
                                - Total Balance Due: <span class="text-danger">Rs. <?php echo number_format($total_group_amount ?? 0, 2); ?></span>
                            <?php else: ?>
                                - Total Balance Due: <span class="text-danger">Rs. <?php echo number_format($total_cbo_amount ?? 0, 2); ?></span>
                            <?php endif; ?>
                        </h5>
                        <div>
                            <span class="me-3 text-success" id="totalPaymentDisplay">
                                Total Payment: <strong>Rs. 0.00</strong>
                            </span>
                            <button type="submit" name="process_payment" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Process Payments
                            </button>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped payment-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Group</th>
                                        <th>Customer Name</th>
                                        <th>NIC</th>
                                        <th>Loan Number</th>
                                        <th>Loan Amount</th>
                                        <th>Paid Amount</th>
                                        <th>Balance Due</th>
                                        <th>Payment Amount</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $current_group = null;
                                    $group_total_balance = 0;
                                    
                                    foreach ($disbursed_loans as $loan):
                                        $group_key = $loan['group_number'] ? 'Group ' . $loan['group_number'] : 'No Group';
                                        $group_name = $loan['group_name'] ?? '';
                                        $remaining_balance = $loan['remaining_balance'] ?? 0;
                                        $total_paid = $loan['total_paid_amount'] ?? 0;
                                        $total_loan_amount = $loan['total_loan_amount'] ?? $loan['amount'] ?? 0;
                                        $current_balance = $loan['current_balance'] ?? $remaining_balance;
                                        
                                        if ($current_group !== $group_key):
                                            if ($current_group !== null):
                                                // Show group total
                                                ?>
                                                <tr class="group-header">
                                                    <td colspan="6" class="text-end"><strong>Group Total:</strong></td>
                                                    <td class="amount-due"><strong>Rs. <?php echo number_format($group_total_balance ?? 0, 2); ?></strong></td>
                                                    <td class="amount-paid"><strong id="groupTotalPayment-<?php echo $current_group; ?>">Rs. 0.00</strong></td>
                                                    <td></td>
                                                </tr>
                                                <?php
                                                $group_total_balance = 0;
                                            endif;
                                            ?>
                                            <tr class="group-header">
                                                <td colspan="9">
                                                    <strong><?php echo $group_key; ?></strong>
                                                    <?php if ($group_name): ?>
                                                        - <?php echo $group_name; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                            $current_group = $group_key;
                                        endif;
                                        
                                        $group_total_balance += $remaining_balance;
                                    ?>
                                    <tr class="customer-row" id="loan-row-<?php echo $loan['id']; ?>">
                                        <td>
                                            <?php if ($loan['group_number']): ?>
                                                <?php echo $loan['group_number']; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($loan['full_name']); ?>
                                            <br>
                                            <small class="balance-info">
                                                Pending Installments: <?php echo $loan['pending_installments'] ?? 0; ?>
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['national_id']); ?></td>
                                        <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                        <td>Rs. <?php echo number_format($total_loan_amount, 2); ?></td>
                                        <td class="text-success">Rs. <?php echo number_format($total_paid, 2); ?></td>
                                        <td class="amount-due" id="balance-<?php echo $loan['id']; ?>">
                                            Rs. <?php echo number_format($remaining_balance, 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($remaining_balance > 0): ?>
                                            <input type="number" name="payment[<?php echo $loan['id']; ?>]" 
                                                   class="form-control form-control-sm payment-amount payment-input" 
                                                   data-loan-id="<?php echo $loan['id']; ?>"
                                                   data-group="<?php echo $current_group; ?>"
                                                   data-max-amount="<?php echo $remaining_balance; ?>"
                                                   placeholder="0.00" min="0" max="<?php echo $remaining_balance; ?>" 
                                                   step="0.01" value="0">
                                            <?php else: ?>
                                                <span class="text-success"><i class="bi bi-check-circle"></i> Fully Paid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($remaining_balance > 0): ?>
                                            <div class="btn-group-vertical">
                                                <button type="button" class="btn btn-sm btn-outline-primary pay-installment" 
                                                        data-amount="<?php echo $loan['weekly_installment'] ?? 0; ?>"
                                                        data-target="<?php echo $loan['id']; ?>">
                                                    <i class="bi bi-currency-dollar"></i> Weekly Amount
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-success pay-full mt-1" 
                                                        data-amount="<?php echo $remaining_balance; ?>"
                                                        data-target="<?php echo $loan['id']; ?>">
                                                    <i class="bi bi-check-all"></i> Full Balance
                                                </button>
                                            </div>
                                            <?php else: ?>
                                                <span class="text-success">Loan Settled</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Final group total -->
                                    <?php if ($current_group !== null): ?>
                                    <tr class="group-header">
                                        <td colspan="6" class="text-end"><strong>Group Total:</strong></td>
                                        <td class="amount-due"><strong>Rs. <?php echo number_format($group_total_balance ?? 0, 2); ?></strong></td>
                                        <td class="amount-paid"><strong id="groupTotalPayment-<?php echo $current_group; ?>">Rs. 0.00</strong></td>
                                        <td></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Grand Total -->
                                    <tr class="payment-total">
                                        <td colspan="6" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                        <td class="amount-due"><strong>Rs. <?php echo number_format(($group_id ? $total_group_amount : $total_cbo_amount) ?? 0, 2); ?></strong></td>
                                        <td class="amount-paid"><strong id="grandTotalPayment">Rs. 0.00</strong></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
            <?php elseif ($cbo_id): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No disbursed loans found for the selected criteria.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script>
        // Calculate totals
        function calculateTotals() {
            let grandTotal = 0;
            const groupTotals = {};
            
            // Reset all group totals
            document.querySelectorAll('[id^="groupTotalPayment-"]').forEach(el => {
                const group = el.id.replace('groupTotalPayment-', '');
                groupTotals[group] = 0;
            });
            
            // Calculate payment amounts
            document.querySelectorAll('.payment-amount').forEach(input => {
                const amount = parseFloat(input.value) || 0;
                const group = input.getAttribute('data-group');
                const maxAmount = parseFloat(input.getAttribute('data-max-amount')) || 0;
                const loanId = input.getAttribute('data-loan-id');
                
                if (amount > 0) {
                    grandTotal += amount;
                    
                    if (groupTotals[group] !== undefined) {
                        groupTotals[group] += amount;
                    }
                    
                    // Validate amount
                    if (amount > maxAmount) {
                        input.classList.add('is-invalid');
                    } else {
                        input.classList.remove('is-invalid');
                    }
                    
                    // Update balance display in real-time (visual feedback)
                    const balanceElement = document.getElementById('balance-' + loanId);
                    if (balanceElement) {
                        const currentBalance = parseFloat(balanceElement.textContent.replace('Rs. ', '').replace(/,/g, '')) || 0;
                        const newBalance = currentBalance - amount;
                        
                        // Visual feedback for balance update
                        balanceElement.classList.add('real-time-update');
                        setTimeout(() => {
                            balanceElement.classList.remove('real-time-update');
                        }, 1000);
                    }
                }
            });
            
            // Update group totals display
            Object.keys(groupTotals).forEach(group => {
                const groupElement = document.getElementById('groupTotalPayment-' + group);
                if (groupElement) {
                    groupElement.textContent = 'Rs. ' + groupTotals[group].toFixed(2);
                }
            });
            
            // Update grand total display
            document.getElementById('grandTotalPayment').textContent = 'Rs. ' + grandTotal.toFixed(2);
            document.getElementById('totalPaymentDisplay').innerHTML = 
                'Total Payment: <strong>Rs. ' + grandTotal.toFixed(2) + '</strong>';
            
            return grandTotal;
        }
        
        // Auto-fill payment amounts
        document.querySelectorAll('.pay-installment').forEach(button => {
            button.addEventListener('click', function() {
                const amount = parseFloat(this.getAttribute('data-amount'));
                const targetId = this.getAttribute('data-target');
                const input = document.querySelector(`.payment-amount[data-loan-id="${targetId}"]`);
                if (input) {
                    const currentAmount = parseFloat(input.value) || 0;
                    input.value = (currentAmount + amount).toFixed(2);
                    calculateTotals();
                    input.focus();
                }
            });
        });
        
        document.querySelectorAll('.pay-full').forEach(button => {
            button.addEventListener('click', function() {
                const amount = parseFloat(this.getAttribute('data-amount'));
                const targetId = this.getAttribute('data-target');
                const input = document.querySelector(`.payment-amount[data-loan-id="${targetId}"]`);
                if (input) {
                    input.value = amount.toFixed(2);
                    calculateTotals();
                    input.focus();
                }
            });
        });
        
        // Real-time calculation
        document.querySelectorAll('.payment-amount').forEach(input => {
            input.addEventListener('input', calculateTotals);
        });
        
        // Validate payment amounts on form submit
        document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
            const grandTotal = calculateTotals();
            
            if (grandTotal <= 0) {
                e.preventDefault();
                alert('Please enter at least one payment amount.');
                return false;
            }
            
            // Check for invalid amounts
            const invalidInputs = document.querySelectorAll('.payment-amount.is-invalid');
            if (invalidInputs.length > 0) {
                e.preventDefault();
                alert('Some payment amounts exceed the maximum allowed amount. Please check the amounts.');
                return false;
            }
            
            return confirm('Are you sure you want to process these payments? Total: Rs. ' + grandTotal.toFixed(2));
        });
        
        // Initial calculation
        calculateTotals();
    </script>
</body>
</html>