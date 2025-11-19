<?php
// modules/payment/reverse_payment.php - COMPLETE FIXED VERSION
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    $_SESSION['error_message'] = "You don't have permission to access this page";
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Enhanced reversal function
function reversePaymentFinal($payment_id, $reversal_reason, $reversed_by_user_id) {
    global $conn;
    
    $conn->begin_transaction();
    
    try {
        // 1. Get original payment details
        $payment_sql = "SELECT * FROM loan_payments WHERE id = ?";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("i", $payment_id);
        $payment_stmt->execute();
        $original_payment = $payment_stmt->get_result()->fetch_assoc();
        
        if (!$original_payment) {
            throw new Exception("Payment not found");
        }
        
        if ($original_payment['reversal_status'] == 'reversed') {
            throw new Exception("This payment has already been reversed");
        }
        
        $loan_id = $original_payment['loan_id'];
        
        // 2. Create reversal payment
        $reversal_sql = "INSERT INTO loan_payments 
                        (loan_id, original_payment_id, amount, payment_date, actual_payment_date,
                         payment_method, payment_reference, reversal_status, reversal_notes, created_by) 
                        VALUES (?, ?, -?, NOW(), NOW(), ?, ?, 'reversal', ?, ?)";
        
        $reversal_stmt = $conn->prepare($reversal_sql);
        $reversal_reference = "REV-" . ($original_payment['payment_reference'] ?? 'PAY-' . $payment_id);
        $payment_method = $original_payment['payment_method'] ?? 'cash';
        
        $reversal_stmt->bind_param("iidsssi", 
            $loan_id,
            $payment_id,
            $original_payment['amount'],
            $payment_method,
            $reversal_reference,
            $reversal_reason,
            $reversed_by_user_id
        );
        
        if (!$reversal_stmt->execute()) {
            throw new Exception("Reversal payment creation failed: " . $conn->error);
        }
        
        // 3. Mark original as reversed
        $update_sql = "UPDATE loan_payments SET reversal_status = 'reversed' WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $payment_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to mark payment as reversed: " . $conn->error);
        }
        
        // 4. Update loan balance and installments
        updateLoanBalanceAfterReversal($loan_id);
        updateInstallmentsAfterReversal($loan_id);
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Reversal Error: " . $e->getMessage());
        return false;
    }
}

// Function to update loan balance after reversal
function updateLoanBalanceAfterReversal($loan_id) {
    global $conn;
    
    // Calculate total paid amount from non-reversal payments only
    $paid_sql = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                FROM loan_payments 
                WHERE loan_id = ? 
                AND reversal_status != 'reversal' 
                AND reversal_status != 'reversed'
                AND amount > 0";
    
    $paid_stmt = $conn->prepare($paid_sql);
    $paid_stmt->bind_param("i", $loan_id);
    $paid_stmt->execute();
    $paid_result = $paid_stmt->get_result();
    $paid_data = $paid_result->fetch_assoc();
    $total_paid = $paid_data['total_paid'];
    
    // Get loan total amount
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
    $update_sql = "UPDATE loans SET balance = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("di", $new_balance, $loan_id);
    $update_stmt->execute();
    
    return $new_balance;
}

// Function to update installments after reversal
function updateInstallmentsAfterReversal($loan_id) {
    global $conn;
    
    // Reset all installment paid amounts
    $reset_sql = "UPDATE loan_installments SET paid_amount = 0, status = 'pending' WHERE loan_id = ?";
    $reset_stmt = $conn->prepare($reset_sql);
    $reset_stmt->bind_param("i", $loan_id);
    $reset_stmt->execute();
    
    // Get all valid payments (non-reversal, non-reversed, positive amounts)
    $payments_sql = "SELECT amount, installment_id, actual_payment_date, payment_date 
                    FROM loan_payments 
                    WHERE loan_id = ? 
                    AND reversal_status != 'reversal' 
                    AND reversal_status != 'reversed'
                    AND amount > 0
                    ORDER BY COALESCE(actual_payment_date, payment_date) ASC, id ASC";
    
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->bind_param("i", $loan_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    
    $installments_sql = "SELECT id, installment_number, amount FROM loan_installments 
                        WHERE loan_id = ? ORDER BY installment_number ASC";
    $installments_stmt = $conn->prepare($installments_sql);
    $installments_stmt->bind_param("i", $loan_id);
    $installments_stmt->execute();
    $installments_data = $installments_stmt->get_result();
    
    $installments_array = [];
    while ($row = $installments_data->fetch_assoc()) {
        $installments_array[] = $row;
    }
    
    $current_installment_index = 0;
    $remaining_payment_amount = 0;
    
    while ($payment = $payments_result->fetch_assoc()) {
        $payment_amount = $payment['amount'];
        $remaining_payment_amount += $payment_amount;
        
        while ($remaining_payment_amount > 0 && $current_installment_index < count($installments_array)) {
            $current_installment = $installments_array[$current_installment_index];
            $installment_id = $current_installment['id'];
            $installment_amount = $current_installment['amount'];
            
            $current_paid_sql = "SELECT paid_amount FROM loan_installments WHERE id = ?";
            $current_paid_stmt = $conn->prepare($current_paid_sql);
            $current_paid_stmt->bind_param("i", $installment_id);
            $current_paid_stmt->execute();
            $current_paid_result = $current_paid_stmt->get_result();
            $current_paid_data = $current_paid_result->fetch_assoc();
            $current_paid = $current_paid_data['paid_amount'];
            
            $remaining_installment_amount = $installment_amount - $current_paid;
            
            if ($remaining_payment_amount >= $remaining_installment_amount) {
                $new_paid_amount = $current_paid + $remaining_installment_amount;
                $remaining_payment_amount -= $remaining_installment_amount;
                
                $update_sql = "UPDATE loan_installments SET paid_amount = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("di", $new_paid_amount, $installment_id);
                $update_stmt->execute();
                
                $current_installment_index++;
            } else {
                $new_paid_amount = $current_paid + $remaining_payment_amount;
                $update_sql = "UPDATE loan_installments SET paid_amount = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("di", $new_paid_amount, $installment_id);
                $update_stmt->execute();
                
                $remaining_payment_amount = 0;
            }
        }
    }
    
    // Update installment statuses
    updateAllInstallmentStatuses($loan_id);
}

// Function to update all installment statuses
function updateAllInstallmentStatuses($loan_id) {
    global $conn;
    
    $installments_sql = "SELECT id, amount, paid_amount FROM loan_installments WHERE loan_id = ?";
    $installments_stmt = $conn->prepare($installments_sql);
    $installments_stmt->bind_param("i", $loan_id);
    $installments_stmt->execute();
    $installments_result = $installments_stmt->get_result();
    
    while ($installment = $installments_result->fetch_assoc()) {
        $installment_id = $installment['id'];
        $amount = $installment['amount'];
        $paid_amount = $installment['paid_amount'];
        
        if ($paid_amount >= $amount) {
            $status = 'paid';
        } elseif ($paid_amount > 0) {
            $status = 'partial';
        } else {
            $status = 'pending';
        }
        
        $update_sql = "UPDATE loan_installments SET status = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $status, $installment_id);
        $update_stmt->execute();
    }
}

// Initialize variables
$payment = null;
$error_message = '';
$success_message = '';

// Get payment details
if (isset($_GET['payment_id'])) {
    $payment_id = intval($_GET['payment_id']);
    
    if ($payment_id <= 0) {
        die("Invalid payment ID");
    }
    
    // Get payment details with current balance calculation
    $payment_sql = "SELECT lp.*, l.loan_number, l.customer_id, l.total_loan_amount, l.balance, l.id as loan_id,
                           c.full_name as customer_name,
                           (SELECT COALESCE(SUM(amount), 0) 
                            FROM loan_payments 
                            WHERE loan_id = l.id 
                            AND reversal_status != 'reversal' 
                            AND reversal_status != 'reversed'
                            AND amount > 0) as current_paid_amount
                   FROM loan_payments lp 
                   JOIN loans l ON lp.loan_id = l.id 
                   JOIN customers c ON l.customer_id = c.id 
                   WHERE lp.id = ?";
    
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bind_param("i", $payment_id);
    $payment_stmt->execute();
    $payment_result = $payment_stmt->get_result();
    $payment = $payment_result->fetch_assoc();
    
    if (!$payment) {
        die("Payment not found");
    }
    
    // Calculate current values
    $payment['current_paid_amount'] = $payment['current_paid_amount'] ?? 0;
    $payment['current_balance'] = $payment['balance'];
    $payment['loan_id'] = $payment['loan_id']; // Ensure loan_id is available
    
    // Check if payment is already reversed
    if ($payment['reversal_status'] == 'reversed') {
        $error_message = "This payment has already been reversed.";
    }
} else {
    die("Payment ID not specified");
}

// Process reversal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reverse_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $reversal_reason = trim($_POST['reversal_reason']);
    $reversed_by = $_SESSION['user_id'];
    
    if (empty($reversal_reason)) {
        $error_message = "Please enter a reason for reversal.";
    } elseif (strlen($reversal_reason) < 10) {
        $error_message = "Please provide a more detailed reason (at least 10 characters).";
    } else {
        if (reversePaymentFinal($payment_id, $reversal_reason, $reversed_by)) {
            $_SESSION['success_message'] = "Payment reversed successfully! Loan balance and installments updated.";
            // Redirect to loan view page with payment history tab
            header("Location: " . BASE_URL . "/modules/loans/view.php?loan_id=" . $payment['loan_id'] . "&tab=payments");
            exit();
        } else {
            $error_message = "Failed to reverse payment. Please try again or contact system administrator.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reverse Payment - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --danger: #e63946;
            --warning: #f72585;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        .info-card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
            border: none;
        }
        
        .btn-danger-custom {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-danger-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(230, 57, 70, 0.4);
            color: white;
        }
        
        .payment-details-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .reversal-warning {
            border-left: 4px solid var(--danger);
            background: linear-gradient(135deg, #fff5f5, #ffe6e6);
        }
        
        .amount-highlight {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            text-decoration: none;
            color: var(--primary);
        }
        
        .breadcrumb-item.active {
            color: #6c757d;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/">Loans</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $payment['loan_id']; ?>">Loan Details</a></li>
                    <li class="breadcrumb-item active">Reverse Payment</li>
                </ol>
            </nav>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 fw-bold text-dark">
                        <i class="bi bi-arrow-counterclockwise text-danger me-2"></i>Reverse Payment
                    </h1>
                    <p class="text-muted mb-0">Cancel a payment entry and update loan records</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $payment['loan_id']; ?>&tab=payments" 
                   class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Loan
                </a>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div>
                            <strong>Error:</strong> <?php echo $error_message; ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Payment Details Card -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-receipt me-2"></i>Payment Details
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-bordered payment-details-table">
                                <tr>
                                    <th width="40%">Loan Number</th>
                                    <td><?php echo htmlspecialchars($payment['loan_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Customer Name</th>
                                    <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Payment Amount</th>
                                    <td class="fw-bold text-success amount-highlight">
                                        Rs. <?php echo number_format($payment['amount'], 2); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Payment Date</th>
                                    <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-bordered payment-details-table">
                                <tr>
                                    <th width="40%">Loan Amount</th>
                                    <td>Rs. <?php echo number_format($payment['total_loan_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>Current Paid Amount</th>
                                    <td>Rs. <?php echo number_format($payment['current_paid_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>Current Balance</th>
                                    <td>Rs. <?php echo number_format($payment['current_balance'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>After Reversal</th>
                                    <td class="fw-bold text-danger amount-highlight">
                                        Rs. <?php echo number_format($payment['current_paid_amount'] - $payment['amount'], 2); ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Reversal Form -->
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="payment_id" value="<?php echo $payment_id; ?>">
                        
                        <div class="mb-4">
                            <label for="reversal_reason" class="form-label fw-bold">
                                <i class="bi bi-chat-left-text me-2"></i>Reason for Reversal *
                            </label>
                            <textarea name="reversal_reason" id="reversal_reason" class="form-control" rows="4" required 
                                      placeholder="Provide detailed reason for reversing this payment (minimum 10 characters)..."
                                      minlength="10"><?php echo htmlspecialchars($_POST['reversal_reason'] ?? ''); ?></textarea>
                            <div class="form-text">Please be specific about why this payment needs to be reversed.</div>
                        </div>

                        <!-- Warning Section -->
                        <div class="alert reversal-warning">
                            <div class="d-flex">
                                <i class="bi bi-exclamation-triangle-fill text-danger me-3 fs-4"></i>
                                <div>
                                    <h6 class="alert-heading mb-2">This action will:</h6>
                                    <ul class="mb-0">
                                        <li>Reduce the total paid amount by <strong>Rs. <?php echo number_format($payment['amount'], 2); ?></strong></li>
                                        <li>Increase the loan balance by <strong>Rs. <?php echo number_format($payment['amount'], 2); ?></strong></li>
                                        <li>Update all installment allocations</li>
                                        <li>Recalculate installment statuses</li>
                                        <li>Create a permanent reversal record in payment history</li>
                                        <li>This action cannot be undone</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex gap-3 mt-4">
                            <button type="submit" name="reverse_payment" class="btn btn-danger-custom"
                                    <?php echo ($payment['reversal_status'] == 'reversed') ? 'disabled' : ''; ?>>
                                <i class="bi bi-arrow-counterclockwise me-2"></i>
                                <?php echo ($payment['reversal_status'] == 'reversed') ? 'Already Reversed' : 'Confirm Reversal'; ?>
                            </button>
                            
                            <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $payment['loan_id']; ?>&tab=payments" 
                               class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const reasonTextarea = document.getElementById('reversal_reason');
            
            form.addEventListener('submit', function(e) {
                const reason = reasonTextarea.value.trim();
                
                if (reason.length < 10) {
                    e.preventDefault();
                    alert('Please provide a detailed reason for reversal (at least 10 characters).');
                    reasonTextarea.focus();
                }
            });
            
            // Character count
            reasonTextarea.addEventListener('input', function() {
                const count = this.value.length;
                const minLength = 10;
                
                if (count < minLength) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                } else {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                }
            });
        });
    </script>
</body>
</html>