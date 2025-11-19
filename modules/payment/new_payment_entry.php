<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant','credit_officer', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// First, ensure the loan_payments table has the created_by column
ensurePaymentTableColumns();

// Get CBOs for filter
$cbos = getAllCBOs();

// Initialize variables
$cbo_id = $_GET['cbo_id'] ?? '';
$group_id = $_GET['group_id'] ?? '';
$payment_date = $_GET['payment_date'] ?? date('Y-m-d');

// Get active loans for payment with correct calculations
$active_loans = [];
$total_outstanding = 0;

if ($cbo_id) {
    $sql = "SELECT l.*, c.full_name, c.national_id, c.phone, g.group_number, g.group_name,
                   cb.name as cbo_name,
                   l.total_loan_amount as original_loan_amount,
                   COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND (lp.reversal_status IS NULL OR lp.reversal_status NOT IN ('reversal', 'reversed'))), 0) as total_paid_amount,
                   (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND (lp.reversal_status IS NULL OR lp.reversal_status NOT IN ('reversal', 'reversed'))), 0)) as remaining_balance,
                   l.weekly_installment
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            LEFT JOIN group_members gm ON c.id = gm.customer_id
            LEFT JOIN groups g ON gm.group_id = g.id AND g.is_active = 1
            WHERE l.status = 'disbursed'
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
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($loan = $result->fetch_assoc()) {
            // Ensure all amount fields have values
            $loan['original_loan_amount'] = floatval($loan['original_loan_amount'] ?? 0);
            $loan['total_paid_amount'] = floatval($loan['total_paid_amount'] ?? 0);
            $loan['remaining_balance'] = floatval($loan['remaining_balance'] ?? 0);
            $loan['weekly_installment'] = floatval($loan['weekly_installment'] ?? 0);
            
            $active_loans[] = $loan;
            $total_outstanding += $loan['remaining_balance'];
        }
    }
}

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $payment_data = $_POST['payment'] ?? [];
    $payment_date = $_POST['payment_date'];
    
    try {
        $conn->begin_transaction();
        
        $total_payment_amount = 0;
        $processed_loans = [];
        $sms_results = []; // Store SMS results
        
        foreach ($payment_data as $loan_id => $payment_info) {
            if (!empty($payment_info['amount']) && floatval($payment_info['amount']) > 0) {
                $payment_amount = floatval($payment_info['amount']);
                $payment_method = $payment_info['method'] ?? 'cash';
                $notes = $payment_info['notes'] ?? '';
                
                // Get current loan details to validate payment amount
                $loan_sql = "SELECT l.*, c.full_name, c.phone,
                                    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND (lp.reversal_status IS NULL OR lp.reversal_status NOT IN ('reversal', 'reversed'))), 0) as current_total_paid,
                                    (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND (lp.reversal_status IS NULL OR lp.reversal_status NOT IN ('reversal', 'reversed'))), 0)) as remaining_balance
                            FROM loans l 
                            JOIN customers c ON l.customer_id = c.id 
                            WHERE l.id = ? AND l.status = 'disbursed'";
                $loan_stmt = $conn->prepare($loan_sql);
                $loan_stmt->bind_param("i", $loan_id);
                $loan_stmt->execute();
                $loan_result = $loan_stmt->get_result();
                $loan = $loan_result->fetch_assoc();
                
                if ($loan) {
                    $current_total_paid = floatval($loan['current_total_paid'] ?? 0);
                    $remaining_balance = floatval($loan['remaining_balance'] ?? 0);
                    $total_loan_amount = floatval($loan['total_loan_amount'] ?? 0);
                    
                    // Validate payment amount doesn't exceed remaining balance
                    if ($payment_amount > $remaining_balance) {
                        throw new Exception("Payment amount (Rs. " . number_format($payment_amount, 2) . ") exceeds remaining balance (Rs. " . number_format($remaining_balance, 2) . ") for loan {$loan['loan_number']}");
                    }
                    
                    $total_payment_amount += $payment_amount;
                    
                    // Generate unique payment reference
                    $payment_reference = 'PAY' . date('YmdHis') . rand(100, 999);
                    
                    // Record payment in loan_payments table
                    $payment_sql = "INSERT INTO loan_payments 
                                   (loan_id, amount, payment_date, payment_method, received_by, payment_reference, notes, created_by, created_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($payment_sql);
                    $current_user_id = $_SESSION['user_id'] ?? 1;
                    $stmt->bind_param("idssissi", $loan_id, $payment_amount, $payment_date, $payment_method, $current_user_id, $payment_reference, $notes, $current_user_id);
                    
                    if ($stmt->execute()) {
                        // ✅ CRITICAL FIX: Update loan balance immediately after payment
                        updateLoanBalance($loan_id);
                        
                        // Update loan installments based on payment
                        updateLoanInstallments($loan_id, $payment_amount, $payment_date);
                        
                        $processed_loans[] = $loan['loan_number'];
                        
                        // ✅ SMS Integration - Send payment confirmation WITH CORRECT CALCULATIONS
                        if (SMS_ENABLED && !empty($loan['phone']) && $payment_amount > 0) {
                            // Calculate new remaining balance after this payment - CORRECTED
                            $new_total_paid = $current_total_paid + $payment_amount;
                            $new_remaining_balance = $total_loan_amount - $new_total_paid;
                            
                            $message = "Dear {$loan['full_name']}, payment of Rs. " . 
                                      number_format($payment_amount, 2) . " received for loan {$loan['loan_number']}. " .
                                      "Total paid: Rs. " . number_format($new_total_paid, 2) . ". " .
                                      "Remaining: Rs. " . number_format($new_remaining_balance, 2) . ". " .
                                      "Date: " . date('Y-m-d', strtotime($payment_date)) . " - Micro Finance";
                            
                            $sms_result = sendSMS($loan['phone'], $message);
                            $sms_results[] = [
                                'customer' => $loan['full_name'],
                                'phone' => $loan['phone'],
                                'amount' => $payment_amount,
                                'success' => $sms_result['success'],
                                'message' => $sms_result['message']
                            ];
                        }
                        
                        // Log activity
                        logActivity($_SESSION['user_id'], 'payment_received', 
                                  "Payment received from {$loan['full_name']} for loan {$loan['loan_number']} - Amount: Rs. " . number_format($payment_amount, 2) . " - Method: {$payment_method} - Reference: {$payment_reference}");
                        
                        // Check if loan is fully paid
                        $check_balance_sql = "SELECT (l.total_loan_amount - COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id AND (lp.reversal_status IS NULL OR lp.reversal_status NOT IN ('reversal', 'reversed'))), 0)) as remaining_balance 
                                             FROM loans l WHERE l.id = ?";
                        $check_balance_stmt = $conn->prepare($check_balance_sql);
                        $check_balance_stmt->bind_param("i", $loan_id);
                        $check_balance_stmt->execute();
                        $check_balance_result = $check_balance_stmt->get_result();
                        $remaining_balance_data = $check_balance_result->fetch_assoc();
                        $final_balance = floatval($remaining_balance_data['remaining_balance'] ?? 0);
                        
                        if ($final_balance <= 0) {
                            // Loan is fully paid - update loan status to completed
                            $update_loan_sql = "UPDATE loans SET status = 'completed', updated_at = NOW() WHERE id = ?";
                            $update_loan_stmt = $conn->prepare($update_loan_sql);
                            $update_loan_stmt->bind_param("i", $loan_id);
                            $update_loan_stmt->execute();
                            
                            logActivity($_SESSION['user_id'], 'loan_settled', 
                                      "Loan {$loan['loan_number']} fully settled for {$loan['full_name']}");
                            
                            // ✅ SMS Integration - Send loan settlement notification
                            if (SMS_ENABLED && !empty($loan['phone'])) {
                                $settlement_message = "Congratulations {$loan['full_name']}! Your loan {$loan['loan_number']} has been fully settled. " .
                                                    "Total paid: Rs. " . number_format($total_loan_amount, 2) . ". " .
                                                    "Thank you for your timely payments. - Micro Finance";
                                
                                $settlement_sms_result = sendSMS($loan['phone'], $settlement_message);
                                $sms_results[] = [
                                    'customer' => $loan['full_name'],
                                    'phone' => $loan['phone'],
                                    'amount' => 'SETTLEMENT',
                                    'success' => $settlement_sms_result['success'],
                                    'message' => $settlement_sms_result['message']
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        $conn->commit();
        
        // Prepare success message with SMS results
        $message = "Successfully processed payments! Total: Rs. " . number_format($total_payment_amount, 2);
        if (!empty($processed_loans)) {
            $message .= " - Processed loans: " . implode(', ', $processed_loans);
        }
        
        // Add SMS results to success message
        if (!empty($sms_results)) {
            $sms_success_count = 0;
            $sms_failed_count = 0;
            
            foreach ($sms_results as $sms) {
                if ($sms['success']) {
                    $sms_success_count++;
                } else {
                    $sms_failed_count++;
                }
            }
            
            $message .= " SMS notifications: {$sms_success_count} sent, {$sms_failed_count} failed.";
            
            // Show detailed SMS results if any failed
            if ($sms_failed_count > 0) {
                $failed_sms_details = "";
                foreach ($sms_results as $sms) {
                    if (!$sms['success']) {
                        $failed_sms_details .= "{$sms['customer']} ({$sms['phone']}): {$sms['message']}. ";
                    }
                }
                $message .= " Failed: " . $failed_sms_details;
            }
        }
        
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = "success";
        
        // Redirect to clear form
        header("Location: new_payment_entry.php?cbo_id=$cbo_id&group_id=$group_id&payment_date=$payment_date");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message'] = "Error processing payments: " . $e->getMessage();
        $_SESSION['flash_type'] = "danger";
    }
}

/**
 * ✅ CRITICAL FIX: Update loan balance immediately after payment
 */
function updateLoanBalance($loan_id) {
    global $conn;
    
    // Calculate total paid amount (exclude reversals)
    $paid_sql = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                FROM loan_payments 
                WHERE loan_id = ? 
                AND (reversal_status IS NULL OR reversal_status NOT IN ('reversal', 'reversed'))
                AND amount > 0";
    
    $paid_stmt = $conn->prepare($paid_sql);
    $paid_stmt->bind_param("i", $loan_id);
    $paid_stmt->execute();
    $paid_result = $paid_stmt->get_result();
    $paid_data = $paid_result->fetch_assoc();
    $total_paid = $paid_data['total_paid'];
    
    // Get total loan amount
    $loan_sql = "SELECT total_loan_amount FROM loans WHERE id = ?";
    $loan_stmt = $conn->prepare($loan_sql);
    $loan_stmt->bind_param("i", $loan_id);
    $loan_stmt->execute();
    $loan_result = $loan_stmt->get_result();
    $loan_data = $loan_result->fetch_assoc();
    $total_loan_amount = $loan_data['total_loan_amount'];
    
    // Calculate new balance
    $new_balance = $total_loan_amount - $total_paid;
    
    // Update loan balance
    $update_sql = "UPDATE loans SET balance = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("di", $new_balance, $loan_id);
    $update_stmt->execute();
    
    return $new_balance;
}

/**
 * Update loan installments based on payment amount
 */
function updateLoanInstallments($loan_id, $payment_amount, $payment_date) {
    global $conn;
    
    $remaining_payment = $payment_amount;
    
    // Get pending installments in order
    $installments_sql = "SELECT * FROM loan_installments 
                        WHERE loan_id = ? AND status IN ('pending', 'partial')
                        ORDER BY installment_number ASC";
    $installments_stmt = $conn->prepare($installments_sql);
    $installments_stmt->bind_param("i", $loan_id);
    $installments_stmt->execute();
    $installments_result = $installments_stmt->get_result();
    
    while (($installment = $installments_result->fetch_assoc()) !== null && $remaining_payment > 0) {
        $installment_amount = floatval($installment['amount'] ?? 0);
        $installment_id = intval($installment['id'] ?? 0);
        $paid_so_far = floatval($installment['paid_amount'] ?? 0);
        $remaining_installment = $installment_amount - $paid_so_far;
        
        if ($remaining_payment >= $remaining_installment) {
            // Pay the full remaining installment
            $paid_amount = $remaining_installment;
            $status = 'paid';
        } else {
            // Pay partial of the installment
            $paid_amount = $remaining_payment;
            $status = 'partial';
        }
        
        // Update installment
        $update_sql = "UPDATE loan_installments 
                      SET paid_amount = paid_amount + ?, 
                          payment_date = ?,
                          status = ?,
                          updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("dssi", $paid_amount, $payment_date, $status, $installment_id);
        $stmt->execute();
        
        $remaining_payment -= $paid_amount;
    }
}

/**
 * Ensure loan_payments table has required columns including created_by
 */
function ensurePaymentTableColumns() {
    global $conn;
    
    // Check and add created_by column if missing
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
    
    // Check and add other required columns if missing
    $check_installment_sql = "SHOW COLUMNS FROM loan_payments LIKE 'installment_id'";
    $installment_result = $conn->query($check_installment_sql);
    
    if ($installment_result->num_rows == 0) {
        // Add other missing columns
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
            width: 120px !important;
        }
        .method-select {
            width: 130px !important;
        }
        .notes-input {
            width: 200px !important;
        }
        .max-amount-info {
            font-size: 0.8em;
            color: #6c757d;
        }
        .sms-indicator {
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        .sms-enabled {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .sms-disabled {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .sms-test-mode {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .phone-info {
            font-size: 0.85em;
            color: #6c757d;
        }
        .real-time-update {
            background-color: #d4edda !important;
            transition: background-color 0.5s ease;
        }
        .btn-processing {
            position: relative;
            pointer-events: none;
        }
        .btn-processing::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            margin: -8px 0 0 -8px;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Flash Message -->
            <?php 
            $flash = getFlashMessage(); 
            if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show mt-3">
                    <?php echo $flash['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-credit-card"></i> New Payment Entry
                    <span class="sms-indicator <?php echo SMS_ENABLED ? (SMS_TEST_MODE ? 'sms-test-mode' : 'sms-enabled') : 'sms-disabled'; ?> ms-2">
                        <i class="bi bi-phone me-1"></i>
                        SMS: <?php echo SMS_ENABLED ? (SMS_TEST_MODE ? 'TEST MODE' : 'ENABLED') : 'DISABLED'; ?>
                    </span>
                </h1>
                <div class="text-success">
                    <i class="bi bi-arrow-repeat"></i> Real-time Balance Update: <strong>ENABLED</strong>
                </div>
            </div>

            <!-- SMS Notification Info -->
            <?php if (SMS_ENABLED): ?>
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-info-circle me-2 fs-4"></i>
                    <div>
                        <h6 class="alert-heading mb-1">Automatic SMS Notifications</h6>
                        <p class="mb-0">
                            Payment confirmations will be sent automatically to customers via SMS.
                            <?php if (SMS_TEST_MODE): ?>
                                <span class="badge bg-warning text-dark ms-2">TEST MODE - No actual SMS will be sent</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Real-time Update Info -->
            <div class="alert alert-success mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-lightning-charge me-2 fs-4"></i>
                    <div>
                        <h6 class="alert-heading mb-1">Real-time Balance Updates</h6>
                        <p class="mb-0">
                            Loan balances are now updated immediately after payment processing. 
                            No need to refresh the page to see updated balances.
                        </p>
                    </div>
                </div>
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
                                <?php 
                                if ($cbos && $cbos->num_rows > 0) {
                                    while ($cbo = $cbos->fetch_assoc()): ?>
                                        <option value="<?php echo $cbo['id']; ?>" 
                                            <?php echo $cbo_id == $cbo['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cbo['name']); ?>
                                        </option>
                                    <?php endwhile; 
                                }
                                ?>
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
                                <?php 
                                if ($groups && $groups->num_rows > 0) {
                                    while ($group = $groups->fetch_assoc()): ?>
                                        <option value="<?php echo $group['id']; ?>" 
                                            <?php echo $group_id == $group['id'] ? 'selected' : ''; ?>>
                                            Group <?php echo htmlspecialchars($group['group_number']); ?> - <?php echo htmlspecialchars($group['group_name']); ?>
                                        </option>
                                    <?php endwhile; 
                                }
                                ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-4">
                            <label for="payment_date" class="form-label">Payment Date</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                   value="<?php echo htmlspecialchars($payment_date); ?>" required>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Search
                            </button>
                            <a href="new_payment_entry.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payment Form -->
            <?php if (!empty($active_loans)): ?>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="payment_date" value="<?php echo htmlspecialchars($payment_date); ?>">
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list-check"></i> Payment Collection
                            - Total Outstanding: <span class="text-danger">Rs. <?php echo number_format($total_outstanding, 2); ?></span>
                        </h5>
                        <div>
                            <span class="me-3 text-success" id="totalPaymentDisplay">
                                Total Payment: <strong>Rs. 0.00</strong>
                            </span>
                            <button type="button" id="processPaymentBtn" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Process Payments
                                <?php if (SMS_ENABLED): ?>
                                    <br><small class="text-white-50">(SMS will be sent automatically)</small>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped payment-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Group</th>
                                        <th>Customer Name</th>
                                        <th>Contact</th>
                                        <th>Loan Number</th>
                                        <th>Original Loan</th>
                                        <th>Paid Amount</th>
                                        <th>Remaining Balance</th>
                                        <th>Weekly Installment</th>
                                        <th>Payment Amount</th>
                                        <th>Payment Method</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $current_group = null;
                                    $counter = 0;
                                    
                                    foreach ($active_loans as $loan):
                                        $counter++;
                                        $group_key = $loan['group_number'] ? 'Group ' . $loan['group_number'] : 'No Group';
                                        $group_name = $loan['group_name'] ?? '';
                                        $original_loan = $loan['original_loan_amount'] ?? 0;
                                        $total_paid = $loan['total_paid_amount'] ?? 0;
                                        $remaining_balance = $loan['remaining_balance'] ?? 0;
                                        $weekly_installment = $loan['weekly_installment'] ?? 0;
                                        $customer_phone = $loan['phone'] ?? '';
                                        
                                        if ($current_group !== $group_key):
                                            if ($current_group !== null):
                                                // Show group total
                                                ?>
                                                <tr class="group-header">
                                                    <td colspan="13" class="text-end"><strong>Group Total:</strong></td>
                                                    <td class="amount-paid"><strong id="groupTotalPayment-<?php echo $current_group; ?>">Rs. 0.00</strong></td>
                                                    <td></td>
                                                </tr>
                                                <?php
                                            endif;
                                            ?>
                                            <tr class="group-header">
                                                <td colspan="15">
                                                    <strong><?php echo htmlspecialchars($group_key); ?></strong>
                                                    <?php if ($group_name): ?>
                                                        - <?php echo htmlspecialchars($group_name); ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                            $current_group = $group_key;
                                        endif;
                                    ?>
                                    <tr class="customer-row">
                                        <td><?php echo $counter; ?></td>
                                        <td>
                                            <?php if ($loan['group_number']): ?>
                                                <?php echo htmlspecialchars($loan['group_number']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($loan['full_name']); ?>
                                            <?php if (SMS_ENABLED && !empty($customer_phone)): ?>
                                                <span class="sms-indicator sms-enabled ms-1">
                                                    <i class="bi bi-check-lg"></i>SMS
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="phone-info">
                                            <?php if (!empty($customer_phone)): ?>
                                                <i class="bi bi-phone"></i> <?php echo htmlspecialchars($customer_phone); ?>
                                            <?php else: ?>
                                                <span class="text-muted">No phone</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                        <td class="text-primary">Rs. <?php echo number_format($original_loan, 2); ?></td>
                                        <td class="text-success">Rs. <?php echo number_format($total_paid, 2); ?></td>
                                        <td class="amount-due">Rs. <?php echo number_format($remaining_balance, 2); ?></td>
                                        <td class="text-info">Rs. <?php echo number_format($weekly_installment, 2); ?></td>
                                        <td>
                                            <input type="number" name="payment[<?php echo $loan['id']; ?>][amount]" 
                                                   class="form-control form-control-sm payment-amount payment-input" 
                                                   data-loan-id="<?php echo $loan['id']; ?>"
                                                   data-group="<?php echo htmlspecialchars($current_group); ?>"
                                                   data-max-amount="<?php echo $remaining_balance; ?>"
                                                   placeholder="0.00" min="0" max="<?php echo $remaining_balance; ?>" 
                                                   step="0.01" value="0">
                                            <div class="max-amount-info">Max: Rs. <?php echo number_format($remaining_balance, 2); ?></div>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm method-select" name="payment[<?php echo $loan['id']; ?>][method]">
                                                <option value="cash">Cash</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm notes-input" 
                                                   name="payment[<?php echo $loan['id']; ?>][notes]" 
                                                   placeholder="Notes...">
                                        </td>
                                        <td>
                                            <div class="btn-group-vertical">
                                                <button type="button" class="btn btn-sm btn-outline-primary pay-installment" 
                                                        data-amount="<?php echo $weekly_installment; ?>"
                                                        data-target="<?php echo $loan['id']; ?>">
                                                    <i class="bi bi-currency-dollar"></i> Weekly
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-success pay-full mt-1" 
                                                        data-amount="<?php echo $remaining_balance; ?>"
                                                        data-target="<?php echo $loan['id']; ?>">
                                                    <i class="bi bi-check-all"></i> Full
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Final group total -->
                                    <?php if ($current_group !== null): ?>
                                    <tr class="group-header">
                                        <td colspan="13" class="text-end"><strong>Group Total:</strong></td>
                                        <td class="amount-paid"><strong id="groupTotalPayment-<?php echo $current_group; ?>">Rs. 0.00</strong></td>
                                        <td></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Grand Total -->
                                    <tr class="payment-total">
                                        <td colspan="13" class="text-end"><strong>GRAND TOTAL PAYMENT:</strong></td>
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
                    <i class="bi bi-info-circle"></i> No active loans found for the selected criteria.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Please select a CBO to view loans.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
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
                
                if (amount > 0) {
                    grandTotal += amount;
                    
                    if (groupTotals[group] !== undefined) {
                        groupTotals[group] += amount;
                    }
                    
                    // Validate amount doesn't exceed maximum
                    if (amount > maxAmount) {
                        input.classList.add('is-invalid');
                    } else {
                        input.classList.remove('is-invalid');
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
                    input.value = amount.toFixed(2);
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
        
        // ✅ FIXED: Process payment with proper confirmation handling
        document.getElementById('processPaymentBtn')?.addEventListener('click', function() {
            const grandTotal = calculateTotals();
            
            if (grandTotal <= 0) {
                alert('Please enter at least one payment amount.');
                return false;
            }
            
            // Check for invalid amounts
            const invalidInputs = document.querySelectorAll('.payment-amount.is-invalid');
            if (invalidInputs.length > 0) {
                alert('Some payment amounts exceed the maximum allowed amount. Please check the amounts.');
                return false;
            }
            
            let confirmMessage = 'Are you sure you want to process these payments? Total: Rs. ' + grandTotal.toFixed(2);
            <?php if (SMS_ENABLED): ?>
                confirmMessage += '\n\nSMS notifications will be sent to customers automatically.';
                <?php if (SMS_TEST_MODE): ?>
                    confirmMessage += '\n\n⚠️ TEST MODE: No actual SMS will be sent.';
                <?php endif; ?>
            <?php endif; ?>
            
            confirmMessage += '\n\n✅ Loan balances will be updated immediately.';
            
            // ✅ FIXED: Use proper confirmation handling
            if (confirm(confirmMessage)) {
                // User clicked OK - proceed with form submission
                const submitButton = document.createElement('button');
                submitButton.type = 'submit';
                submitButton.name = 'process_payment';
                submitButton.style.display = 'none';
                document.getElementById('paymentForm').appendChild(submitButton);
                
                // Show processing state
                const processBtn = document.getElementById('processPaymentBtn');
                processBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Processing...';
                processBtn.classList.add('btn-processing');
                processBtn.disabled = true;
                
                // Submit the form
                submitButton.click();
            } else {
                // User clicked Cancel - do nothing
                console.log('Payment processing cancelled by user.');
            }
        });
        
        // Initial calculation
        calculateTotals();
        
        // Highlight real-time update feature
        document.addEventListener('DOMContentLoaded', function() {
            const realTimeElement = document.querySelector('.text-success i.bi-arrow-repeat').parentElement;
            realTimeElement.classList.add('real-time-update');
            
            setTimeout(() => {
                realTimeElement.classList.remove('real-time-update');
            }, 3000);
        });
    </script>
</body>
</html>