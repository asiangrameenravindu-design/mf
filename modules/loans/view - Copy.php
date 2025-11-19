<?php
// modules/loans/view.php - COMPLETE FIXED VERSION
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

$loan_details = null;
$installments = [];
$payment_history = [];
$arrears_details = [];

// Enhanced table structure check
function checkAndUpdateTableStructure() {
    global $conn;
    
    $columns_to_check = [
        'loan_payments' => [
            'actual_payment_date' => "ALTER TABLE loan_payments ADD COLUMN actual_payment_date DATE NULL AFTER payment_date",
            'created_by' => "ALTER TABLE loan_payments ADD COLUMN created_by INT NULL AFTER payment_reference",
            'reversal_status' => "ALTER TABLE loan_payments ADD COLUMN reversal_status ENUM('original', 'reversal', 'reversed') DEFAULT 'original' AFTER payment_reference",
            'original_payment_id' => "ALTER TABLE loan_payments ADD COLUMN original_payment_id INT NULL AFTER loan_id",
            'reversal_notes' => "ALTER TABLE loan_payments ADD COLUMN reversal_notes TEXT NULL AFTER payment_reference"
        ],
        'loan_installments' => [
            'completion_date' => "ALTER TABLE loan_installments ADD COLUMN completion_date DATE NULL AFTER due_date"
        ]
    ];

    foreach ($columns_to_check as $table => $columns) {
        foreach ($columns as $column_name => $alter_sql) {
            $check_sql = "SHOW COLUMNS FROM $table LIKE '$column_name'";
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows == 0) {
                if ($conn->query($alter_sql)) {
                    error_log("Added column $column_name to $table");
                    
                    // Set default values for existing records
                    if ($column_name === 'actual_payment_date') {
                        $update_sql = "UPDATE loan_payments SET actual_payment_date = payment_date WHERE actual_payment_date IS NULL";
                        $conn->query($update_sql);
                    }
                }
            }
        }
    }
}

// Calculate arrears function
function calculateArrears($installments, $loan_status, $disbursed_date) {
    $arrears_details = [];
    $total_arrears = 0;
    $today = new DateTime();
    
    $eligible_statuses = ['active', 'disbursed', 'approved'];
    
    if (!in_array($loan_status, $eligible_statuses)) {
        return [
            'details' => [],
            'total_arrears' => 0
        ];
    }
    
    foreach ($installments as $installment) {
        if (empty($installment['due_date']) || $installment['due_date'] == '0000-00-00') {
            continue;
        }
        
        $due_date = new DateTime($installment['due_date']);
        $amount_due = $installment['amount'];
        $paid_amount = $installment['paid_amount'];
        $remaining_amount = $amount_due - $paid_amount;
        
        if ($today > $due_date && $remaining_amount > 0) {
            $arrears_amount = $remaining_amount;
            $days_overdue = $today->diff($due_date)->days;
            
            $arrears_details[] = [
                'installment_number' => $installment['installment_number'],
                'due_date' => $installment['due_date'],
                'amount_due' => $amount_due,
                'paid_amount' => $paid_amount,
                'remaining_amount' => $remaining_amount,
                'arrears_amount' => $arrears_amount,
                'days_overdue' => $days_overdue
            ];
            $total_arrears += $arrears_amount;
        }
    }
    
    return [
        'details' => $arrears_details,
        'total_arrears' => $total_arrears
    ];
}

// Calculate weekly due date function
function calculateWeeklyDueDate($disbursed_date, $meeting_day, $installment_number) {
    $day_map = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6
    ];
    
    $meeting_day_num = $day_map[strtolower($meeting_day)] ?? 2;
    
    $due_date = new DateTime($disbursed_date);
    
    if ($installment_number == 1) {
        $current_day_num = (int)$due_date->format('w');
        $days_to_add = ($meeting_day_num - $current_day_num + 7) % 7;
        
        if ($days_to_add == 0) {
            $days_to_add = 0;
        }
        
        $due_date->modify("+$days_to_add days");
    } else {
        $weeks_to_add = $installment_number - 1;
        $due_date->modify("+$weeks_to_add weeks");
        
        $current_day_num = (int)$due_date->format('w');
        $days_to_add = ($meeting_day_num - $current_day_num + 7) % 7;
        $due_date->modify("+$days_to_add days");
    }
    
    return $due_date->format('Y-m-d');
}

// Payment reversal function
function reversePayment($payment_id, $reversal_reason, $reversed_by_user_id) {
    global $conn;
    
    $conn->begin_transaction();
    
    try {
        $payment_sql = "SELECT * FROM loan_payments WHERE id = ?";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("i", $payment_id);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        $original_payment = $payment_result->fetch_assoc();
        
        if (!$original_payment) {
            throw new Exception("Payment not found");
        }
        
        if ($original_payment['reversal_status'] == 'reversed') {
            throw new Exception("Payment already reversed");
        }
        
        $reversal_sql = "INSERT INTO loan_payments 
                        (loan_id, original_payment_id, amount, payment_date, actual_payment_date, 
                         payment_method, payment_reference, reversal_status, reversal_notes, created_by) 
                        VALUES (?, ?, -?, NOW(), NOW(), 'reversal', ?, 'reversal', ?, ?)";
        
        $reversal_stmt = $conn->prepare($reversal_sql);
        $reversal_amount = $original_payment['amount'];
        $reversal_reference = "REV-" . ($original_payment['payment_reference'] ?? 'PAY-' . $payment_id);
        
        $reversal_stmt->bind_param("iidsssi", 
            $original_payment['loan_id'],
            $payment_id,
            $reversal_amount,
            $reversal_reference,
            $reversal_reason,
            $reversed_by_user_id
        );
        
        if (!$reversal_stmt->execute()) {
            throw new Exception("Failed to create reversal entry: " . $conn->error);
        }
        
        $update_sql = "UPDATE loan_payments SET reversal_status = 'reversed' WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $payment_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to mark payment as reversed: " . $conn->error);
        }
        
        if (!empty($original_payment['installment_id'])) {
            $installment_sql = "UPDATE loan_installments 
                               SET paid_amount = GREATEST(0, paid_amount - ?) 
                               WHERE id = ?";
            $installment_stmt = $conn->prepare($installment_sql);
            $installment_stmt->bind_param("di", $original_payment['amount'], $original_payment['installment_id']);
            $installment_stmt->execute();
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Reversal Error: " . $e->getMessage());
        return false;
    }
}

// Installment status update
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

// Overpayment handling
function autoFixOverpaymentsForInstallments($loan_id) {
    global $conn;
    
    $check_completion_sql = "SHOW COLUMNS FROM loan_installments LIKE 'completion_date'";
    $check_completion_result = $conn->query($check_completion_sql);
    $has_completion_date = $check_completion_result->num_rows > 0;
    
    $check_actual_date_sql = "SHOW COLUMNS FROM loan_payments LIKE 'actual_payment_date'";
    $check_actual_date_result = $conn->query($check_actual_date_sql);
    $has_actual_date = $check_actual_date_result->num_rows > 0;
    
    if ($has_completion_date) {
        $reset_sql = "UPDATE loan_installments SET paid_amount = 0, status = 'pending', completion_date = NULL WHERE loan_id = ?";
    } else {
        $reset_sql = "UPDATE loan_installments SET paid_amount = 0, status = 'pending' WHERE loan_id = ?";
    }
    $reset_stmt = $conn->prepare($reset_sql);
    $reset_stmt->bind_param("i", $loan_id);
    $reset_stmt->execute();
    
    $payments_fields = "id, amount, installment_id, payment_date, reversal_status";
    if ($has_actual_date) {
        $payments_fields .= ", actual_payment_date";
    }
    
    $payments_sql = "SELECT $payments_fields 
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
    
    $installments_sql = "SELECT id, installment_number, amount FROM loan_installments WHERE loan_id = ? ORDER BY installment_number ASC";
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
        $payment_date = $payment['payment_date'];
        $actual_payment_date = $has_actual_date ? ($payment['actual_payment_date'] ?? null) : null;
        
        $effective_payment_date = $has_actual_date && !empty($actual_payment_date) ? $actual_payment_date : $payment_date;
        
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
                
                if ($new_paid_amount >= $installment_amount && $has_completion_date) {
                    $completion_sql = "UPDATE loan_installments SET completion_date = ? WHERE id = ?";
                    $completion_stmt = $conn->prepare($completion_sql);
                    $completion_stmt->bind_param("si", $effective_payment_date, $installment_id);
                    $completion_stmt->execute();
                }
                
                $current_installment_index++;
                
            } else {
                $new_paid_amount = $current_paid + $remaining_payment_amount;
                $update_sql = "UPDATE loan_installments SET paid_amount = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("di", $new_paid_amount, $installment_id);
                $update_stmt->execute();
                
                $remaining_payment_amount = 0;
                
                if ($new_paid_amount >= $installment_amount && $has_completion_date) {
                    $completion_sql = "UPDATE loan_installments SET completion_date = ? WHERE id = ?";
                    $completion_stmt = $conn->prepare($completion_sql);
                    $completion_stmt->bind_param("si", $effective_payment_date, $installment_id);
                    $completion_stmt->execute();
                    
                    $current_installment_index++;
                }
            }
        }
    }
    
    updateAllInstallmentStatuses($loan_id);
    updateLoanBalanceInView($loan_id);
}

// Loan balance update - FIXED VERSION
function updateLoanBalanceInView($loan_id) {
    global $conn;
    
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
    
    $loan_sql = "SELECT total_loan_amount FROM loans WHERE id = ?";
    $loan_stmt = $conn->prepare($loan_sql);
    $loan_stmt->bind_param("i", $loan_id);
    $loan_stmt->execute();
    $loan_result = $loan_stmt->get_result();
    $loan_data = $loan_result->fetch_assoc();
    $total_loan_amount = $loan_data['total_loan_amount'];
    
    $new_balance = $total_loan_amount - $total_paid;
    
    $update_sql = "UPDATE loans SET balance = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("di", $new_balance, $loan_id);
    $update_stmt->execute();
    
    return $new_balance;
}

// Payment history with reversal detection
function getPaymentHistoryWithReversals($loan_id) {
    global $conn;
    
    $check_actual_date_sql = "SHOW COLUMNS FROM loan_payments LIKE 'actual_payment_date'";
    $check_actual_date_result = $conn->query($check_actual_date_sql);
    $has_actual_date = $check_actual_date_result->num_rows > 0;
    
    $check_reversal_status_sql = "SHOW COLUMNS FROM loan_payments LIKE 'reversal_status'";
    $check_reversal_status_result = $conn->query($check_reversal_status_sql);
    $has_reversal_status = $check_reversal_status_result->num_rows > 0;
    
    $payments_fields = "lp.id, lp.amount, lp.payment_date, lp.payment_method, 
                       lp.payment_reference, lp.created_by, lp.created_at, lp.installment_id";
    
    if ($has_actual_date) {
        $payments_fields .= ", lp.actual_payment_date";
    }
    if ($has_reversal_status) {
        $payments_fields .= ", lp.reversal_status, lp.reversal_notes, lp.original_payment_id";
    }
    
    $payments_sql = "SELECT $payments_fields 
                    FROM loan_payments lp
                    WHERE lp.loan_id = ? 
                    ORDER BY COALESCE(lp.actual_payment_date, lp.payment_date) DESC, lp.created_at DESC";
    
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->bind_param("i", $loan_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    
    $all_payments = [];
    while ($row = $payments_result->fetch_assoc()) {
        $is_reversal = false;
        $is_reversed = false;
        $reversal_notes = '';
        
        if ($has_reversal_status) {
            if ($row['reversal_status'] == 'reversal') {
                $is_reversal = true;
                $reversal_notes = $row['reversal_notes'] ?? 'Payment Reversal';
            } elseif ($row['reversal_status'] == 'reversed') {
                $is_reversed = true;
                $reversal_notes = 'Original payment was reversed';
            }
        }
        
        if (!$is_reversal && $row['amount'] < 0) {
            $is_reversal = true;
            $reversal_notes = 'Negative amount reversal';
        }
        
        if (!$is_reversal && !$is_reversed) {
            $reference_lower = strtolower($row['payment_reference'] ?? '');
            if (strpos($reference_lower, 'reversal') !== false || 
                strpos($reference_lower, 'reverse') !== false ||
                strpos($reference_lower, 'rev-') !== false) {
                $is_reversal = true;
                $reversal_notes = 'Reversal detected from reference';
            }
        }
        
        $user_id = $row['created_by'];
        $user_name = 'System';
        
        if (!empty($user_id)) {
            $user_sql = "SELECT username, full_name FROM users WHERE id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            
            if ($user_data = $user_result->fetch_assoc()) {
                $user_name = !empty($user_data['full_name']) ? $user_data['full_name'] : $user_data['username'];
            }
        }
        
        $row['entered_by_name'] = $user_name;
        $row['is_reversal'] = $is_reversal;
        $row['is_reversed'] = $is_reversed;
        $row['reversal_notes'] = $reversal_notes;
        
        $all_payments[] = $row;
    }
    
    return $all_payments;
}

// Call the function to check and update table structure
checkAndUpdateTableStructure();

// Main loan processing
if (isset($_GET['loan_id'])) {
    $loan_id = intval($_GET['loan_id']);
    $loan_details = getLoanById($loan_id);
    
    if ($loan_details) {
        // Get CBO meeting day
        $cbo_sql = "SELECT meeting_day, name FROM cbo WHERE id = ?";
        $cbo_stmt = $conn->prepare($cbo_sql);
        $cbo_stmt->bind_param("i", $loan_details['cbo_id']);
        $cbo_stmt->execute();
        $cbo_result = $cbo_stmt->get_result();
        $cbo_data = $cbo_result->fetch_assoc();
        
        $meeting_day = $cbo_data['meeting_day'] ?? 'tuesday';
        $cbo_name = $cbo_data['name'] ?? 'Unknown';
        
        // AUTO-FIX OVERPAYMENTS FOR INSTALLMENTS ONLY
        autoFixOverpaymentsForInstallments($loan_id);
        
        // Get loan installments
        $installments_sql = "SELECT *, 
                            CASE 
                                WHEN completion_date IS NOT NULL THEN completion_date 
                                WHEN paid_amount >= amount THEN due_date 
                                ELSE NULL 
                            END as effective_completion_date
                            FROM loan_installments WHERE loan_id = ? ORDER BY installment_number";
        $installments_stmt = $conn->prepare($installments_sql);
        $installments_stmt->bind_param("i", $loan_id);
        $installments_stmt->execute();
        $installments_result = $installments_stmt->get_result();
        
        while ($row = $installments_result->fetch_assoc()) {
            $installments[] = $row;
        }
        
        // Calculate arrears
        $arrears_data = calculateArrears($installments, $loan_details['status'], $loan_details['disbursed_date'] ?? null);
        $arrears_details = $arrears_data['details'];
        $total_arrears = $arrears_data['total_arrears'];
        
        // Calculate advance payments
        $total_advance_payments = 0;
        $advance_payment_details = [];
        $today = new DateTime();
        
        foreach ($installments as $installment) {
            if (empty($installment['due_date']) || $installment['due_date'] == '0000-00-00') {
                continue;
            }
            
            $due_date = new DateTime($installment['due_date']);
            $paid_amount = $installment['paid_amount'];
            
            if ($paid_amount > 0 && $today < $due_date) {
                $advance_payment_details[] = [
                    'installment_number' => $installment['installment_number'],
                    'due_date' => $installment['due_date'],
                    'paid_amount' => $paid_amount,
                    'days_advanced' => $due_date->diff($today)->days,
                    'status' => $installment['status']
                ];
                $total_advance_payments += $paid_amount;
            }
        }
        
        // Use FIXED function to get payment history
        $original_payments = getPaymentHistoryWithReversals($loan_id);
        
        // Calculate totals
        $total_loan_amount = $loan_details['loan_amount'] ?? 0;
        
        if ($total_loan_amount == 0) {
            $installment_sum_sql = "SELECT SUM(amount) as total_installment_amount FROM loan_installments WHERE loan_id = ?";
            $installment_sum_stmt = $conn->prepare($installment_sum_sql);
            $installment_sum_stmt->bind_param("i", $loan_id);
            $installment_sum_stmt->execute();
            $installment_sum_result = $installment_sum_stmt->get_result();
            $installment_sum_data = $installment_sum_result->fetch_assoc();
            $total_loan_amount = $installment_sum_data['total_installment_amount'] ?? 0;
        }
        
        $total_installment_amount = 0;
        $total_paid_amount = 0;
        $total_remaining_amount = 0;
        
        foreach ($installments as $installment) {
            $total_installment_amount += $installment['amount'];
            $total_paid_amount += $installment['paid_amount'];
            $total_remaining_amount += ($installment['amount'] - $installment['paid_amount']);
        }
        
        // MARK LOAN AS SETTLED functionality
        if (isset($_POST['mark_settled'])) {
            $settlement_date = $_POST['settlement_date'];
            
            // Update loan status to settled
            $update_loan_sql = "UPDATE loans SET status = 'settled', settlement_date = ? WHERE id = ?";
            $update_loan_stmt = $conn->prepare($update_loan_sql);
            $update_loan_stmt->bind_param("si", $settlement_date, $loan_id);
            
            if ($update_loan_stmt->execute()) {
                echo "<div class='alert alert-success'>Loan marked as settled successfully!</div>";
                // Refresh loan details
                $loan_details = getLoanById($loan_id);
            } else {
                echo "<div class='alert alert-danger'>Error marking loan as settled: " . $conn->error . "</div>";
            }
        }
        
        // Check column existence for display
        $check_actual_date_sql = "SHOW COLUMNS FROM loan_payments LIKE 'actual_payment_date'";
        $check_actual_date_result = $conn->query($check_actual_date_sql);
        $has_actual_date = $check_actual_date_result->num_rows > 0;
        
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
            --dark: #1d3557;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
            color: #333;
            overflow-x: hidden;
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
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .info-card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 25px;
        }
        
        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
            border: none;
        }
        
        .customer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 15px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 2rem;
            margin-right: 20px;
        }
        
        .stat-card {
            background: white;
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            text-align: center;
            padding: 1.5rem;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-outline-custom {
            border: 2px solid var(--primary);
            color: var(--primary);
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 15px 25px;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            transform: translateY(-2px);
        }
        
        .nav-tabs .nav-link:hover {
            background: #f8f9fa;
            color: var(--primary);
        }
        
        .tab-content {
            padding: 2rem;
            background: white;
            border-radius: 0 0 15px 15px;
        }
        
        .detail-row {
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .amount {
            font-weight: 600;
            color: var(--primary);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .table th {
            border-top: none;
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
            white-space: nowrap;
            padding: 15px;
        }
        
        .table td {
            padding: 15px;
            vertical-align: middle;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, var(--warning), #ff6b6b);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-edit:hover {
            background: linear-gradient(135deg, #e8590c, #e03131);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(247, 37, 133, 0.3);
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
        }
        
        .badge-completed {
            background-color: #28a745;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .badge-partial {
            background-color: #ffc107;
            color: black;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .badge-pending {
            background-color: #dc3545;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .overpayment {
            background-color: #fff3cd !important;
        }
        
        .reversal-payment {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545 !important;
        }
        
        .reversed-payment {
            background-color: #e2e3e5 !important;
            text-decoration: line-through;
            color: #6c757d !important;
        }
        
        .customer-name-link {
            color: #4361ee;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .customer-name-link:hover {
            color: #3f37c9;
            text-decoration: underline;
        }
        
        .arrears-badge {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .advance-badge {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
            color: white;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .arrears-card {
            border-left: 4px solid #dc3545 !important;
        }
        
        .advance-card {
            border-left: 4px solid #28a745 !important;
        }
        
        .no-arrears-info {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            font-weight: 600;
            text-align: center;
        }
        
        .customer-info-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .loan-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 4px solid var(--primary);
        }
        
        .summary-card.success {
            border-left-color: #28a745;
        }
        
        .summary-card.warning {
            border-left-color: #ffc107;
        }
        
        .summary-card.danger {
            border-left-color: #dc3545;
        }
        
        .summary-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .reversal-badge {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .reversed-badge {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/" class="text-decoration-none">Loans</a></li>
                                <li class="breadcrumb-item active">Loan Details</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Loan Details</h1>
                        <p class="text-muted mb-0">Loan No: <?php echo htmlspecialchars($loan_details['loan_number']); ?></p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="action-buttons">
                            <a href="<?php echo BASE_URL; ?>/modules/loans/edit.php?loan_id=<?php echo $loan_id; ?>" 
                               class="btn-edit">
                                <i class="bi bi-pencil-square me-1"></i>Edit Loan
                            </a>
                            <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-outline-custom">
                                <i class="bi bi-arrow-left me-2"></i>Back to Loans
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Tabs Section -->
            <div class="info-card">
                <div class="card-body p-0">
                    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="customer-tab" data-bs-toggle="tab" data-bs-target="#customer" type="button" role="tab" aria-controls="customer" aria-selected="true">
                                <i class="bi bi-person me-2"></i>Customer Information
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="loan-tab" data-bs-toggle="tab" data-bs-target="#loan" type="button" role="tab" aria-controls="loan" aria-selected="false">
                                <i class="bi bi-folder me-2"></i>Loan Management
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content" id="mainTabsContent">
                        
                        <!-- Customer Information Tab -->
                        <div class="tab-pane fade show active" id="customer" role="tabpanel" aria-labelledby="customer-tab">
                            <!-- Customer Information -->
                            <div class="customer-info-section">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center">
                                            <div class="customer-avatar">
                                                <?php echo substr($loan_details['customer_name'], 0, 1); ?>
                                            </div>
                                            <div>
                                                <h3 class="mb-2"><?php echo htmlspecialchars($loan_details['customer_name']); ?></h3>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><i class="bi bi-person-badge me-2"></i>NIC: <?php echo htmlspecialchars($loan_details['national_id']); ?></p>
                                                        <p class="mb-1"><i class="bi bi-telephone me-2"></i>Phone: <?php echo htmlspecialchars($loan_details['phone']); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p class="mb-1"><i class="bi bi-building me-2"></i>CBO: <?php echo htmlspecialchars($cbo_name); ?></p>
                                                        <p class="mb-0"><i class="bi bi-calendar-event me-2"></i>Meeting Day: <?php echo ucfirst($meeting_day); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-md-end">
                                        <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $loan_details['customer_id']; ?>" 
                                           class="btn btn-light btn-lg">
                                            <i class="bi bi-eye me-2"></i>View Full Profile
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial Summary -->
                            <div class="loan-summary-grid">
                                <div class="summary-card">
                                    <div class="summary-number text-primary">Rs. <?php echo number_format($total_loan_amount, 2); ?></div>
                                    <div class="summary-label">Total Loan Amount</div>
                                    <i class="bi bi-cash-coin text-primary mt-2 fs-1"></i>
                                </div>
                                <div class="summary-card success">
                                    <div class="summary-number text-success">Rs. <?php echo number_format($total_paid_amount, 2); ?></div>
                                    <div class="summary-label">Paid Amount</div>
                                    <i class="bi bi-check-circle text-success mt-2 fs-1"></i>
                                </div>
                                <div class="summary-card warning">
                                    <div class="summary-number text-warning">Rs. <?php echo number_format($total_remaining_amount, 2); ?></div>
                                    <div class="summary-label">Remaining Balance</div>
                                    <i class="bi bi-clock text-warning mt-2 fs-1"></i>
                                </div>
                                <div class="summary-card">
                                    <?php
                                    $completed_count = 0;
                                    foreach ($installments as $installment) {
                                        if ($installment['paid_amount'] >= $installment['amount']) {
                                            $completed_count++;
                                        }
                                    }
                                    $progress_percentage = count($installments) > 0 ? ($completed_count / count($installments)) * 100 : 0;
                                    ?>
                                    <div class="summary-number text-info"><?php echo number_format($progress_percentage, 1); ?>%</div>
                                    <div class="summary-label">Repayment Progress</div>
                                    <i class="bi bi-graph-up text-info mt-2 fs-1"></i>
                                </div>
                            </div>

                            <!-- Arrears and Advance Payments Section -->
                            <?php if (in_array($loan_details['status'], ['active', 'disbursed', 'approved'])): ?>
                            <div class="row mb-4">
                                <!-- Arrears Card -->
                                <div class="col-md-6">
                                    <?php if ($total_arrears > 0): ?>
                                    <div class="info-card arrears-card">
                                        <div class="card-header-custom" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="card-title mb-0">
                                                    <i class="bi bi-exclamation-triangle me-2"></i>Arrears Summary
                                                </h5>
                                                <span class="arrears-badge">
                                                    <i class="bi bi-clock-history me-2"></i>
                                                    Total Arrears: Rs. <?php echo number_format($total_arrears, 2); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered">
                                                    <thead class="table-danger">
                                                        <tr>
                                                            <th>Week #</th>
                                                            <th>Due Date</th>
                                                            <th>Amount Due</th>
                                                            <th>Paid Amount</th>
                                                            <th>Remaining</th>
                                                            <th>Days Overdue</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($arrears_details as $arrear): ?>
                                                        <tr>
                                                            <td><?php echo $arrear['installment_number']; ?></td>
                                                            <td><?php echo $arrear['due_date']; ?></td>
                                                            <td>Rs. <?php echo number_format($arrear['amount_due'], 2); ?></td>
                                                            <td>Rs. <?php echo number_format($arrear['paid_amount'], 2); ?></td>
                                                            <td class="text-danger fw-bold">Rs. <?php echo number_format($arrear['remaining_amount'], 2); ?></td>
                                                            <td><span class="badge bg-danger"><?php echo $arrear['days_overdue']; ?> days</span></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="info-card">
                                        <div class="card-body">
                                            <div class="no-arrears-info">
                                                <i class="bi bi-check-circle-fill me-2"></i>
                                                <strong>No Arrears!</strong> All installments are up to date.
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Advance Payments Card -->
                                <div class="col-md-6">
                                    <?php if ($total_advance_payments > 0): ?>
                                    <div class="info-card advance-card">
                                        <div class="card-header-custom" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="card-title mb-0">
                                                    <i class="bi bi-arrow-up-circle me-2"></i>Advance Payments
                                                </h5>
                                                <span class="advance-badge">
                                                    <i class="bi bi-cash-coin me-2"></i>
                                                    Total Advance: Rs. <?php echo number_format($total_advance_payments, 2); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered">
                                                    <thead class="table-success">
                                                        <tr>
                                                            <th>Week #</th>
                                                            <th>Due Date</th>
                                                            <th>Paid Amount</th>
                                                            <th>Days Advanced</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($advance_payment_details as $advance): ?>
                                                        <tr>
                                                            <td><?php echo $advance['installment_number']; ?></td>
                                                            <td><?php echo $advance['due_date']; ?></td>
                                                            <td class="text-success fw-bold">Rs. <?php echo number_format($advance['paid_amount'], 2); ?></td>
                                                            <td><span class="badge bg-success"><?php echo $advance['days_advanced']; ?> days</span></td>
                                                            <td>
                                                                <span class="badge <?php 
                                                                    echo $advance['status'] == 'paid' ? 'badge-completed' : 
                                                                         ($advance['status'] == 'partial' ? 'badge-partial' : 'badge-pending'); 
                                                                ?>">
                                                                    <?php echo ucfirst($advance['status']); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="info-card">
                                        <div class="card-body">
                                            <div class="alert alert-info text-center mb-0">
                                                <i class="bi bi-info-circle me-2"></i>
                                                <strong>No Advance Payments</strong> - All payments are made on or after due dates.
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Progress Bar -->
                            <div class="info-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">Sequential Payment Progress</h6>
                                        <span class="text-muted"><?php echo $completed_count; ?> of <?php echo count($installments); ?> installments completed</span>
                                    </div>
                                    <div class="progress mb-3" style="height: 12px;">
                                        <div class="progress-bar bg-primary" style="width: <?php echo $progress_percentage; ?>%"></div>
                                    </div>
                                    <div class="row text-center small">
                                        <div class="col-4">
                                            <div class="text-primary fw-bold">Rs. <?php echo number_format($total_paid_amount, 2); ?></div>
                                            <div class="text-muted">Paid</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-warning fw-bold">Rs. <?php echo number_format($total_remaining_amount, 2); ?></div>
                                            <div class="text-muted">Remaining</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-success fw-bold">Rs. <?php echo number_format($total_installment_amount, 2); ?></div>
                                            <div class="text-muted">Total</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Management Tab -->
                        <div class="tab-pane fade" id="loan" role="tabpanel" aria-labelledby="loan-tab">
                            <div class="info-card">
                                <div class="card-body p-0">
                                    <ul class="nav nav-tabs" id="loanTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab" aria-controls="details" aria-selected="true">
                                                <i class="bi bi-info-circle me-2"></i>Loan Details
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="installments-tab" data-bs-toggle="tab" data-bs-target="#installments" type="button" role="tab" aria-controls="installments" aria-selected="false">
                                                <i class="bi bi-calendar me-2"></i>Installments
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab" aria-controls="payments" aria-selected="false">
                                                <i class="bi bi-clock-history me-2"></i>Payment History
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="advance-tab" data-bs-toggle="tab" data-bs-target="#advance" type="button" role="tab" aria-controls="advance" aria-selected="false">
                                                <i class="bi bi-cash-coin me-2"></i>Advance Payments
                                            </button>
                                        </li>
                                    </ul>
                                    <div class="tab-content" id="loanTabsContent">
                                        
                                        <!-- Loan Details Tab -->
                                        <div class="tab-pane fade show active" id="details" role="tabpanel" aria-labelledby="details-tab">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6 class="mb-3 text-primary">Loan Information</h6>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Loan Amount:</span>
                                                        <strong class="amount float-end">Rs. <?php echo number_format($total_loan_amount, 2); ?></strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Interest Rate:</span>
                                                        <strong class="float-end"><?php echo isset($loan_details['interest_rate']) ? $loan_details['interest_rate'] : 'N/A'; ?>%</strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Number of Weeks:</span>
                                                        <strong class="float-end">24 weeks</strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Weekly Installment:</span>
                                                        <strong class="amount float-end">Rs. <?php echo isset($loan_details['weekly_installment']) ? number_format($loan_details['weekly_installment'], 2) : '0.00'; ?></strong>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="mb-3 text-primary">Timeline</h6>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Applied Date:</span>
                                                        <strong class="float-end"><?php echo date('Y-m-d', strtotime($loan_details['created_at'])); ?></strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Approved Date:</span>
                                                        <strong class="float-end"><?php echo isset($loan_details['approved_date']) && !empty($loan_details['approved_date']) ? date('Y-m-d', strtotime($loan_details['approved_date'])) : '-'; ?></strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Disbursed Date:</span>
                                                        <strong class="float-end"><?php echo isset($loan_details['disbursed_date']) && !empty($loan_details['disbursed_date']) ? date('Y-m-d', strtotime($loan_details['disbursed_date'])) : '-'; ?></strong>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="text-muted">Status:</span>
                                                        <span class="badge bg-<?php echo $loan_details['status'] == 'active' ? 'success' : ($loan_details['status'] == 'settled' ? 'info' : ($loan_details['status'] == 'disbursed' ? 'success' : 'warning')); ?> float-end">
                                                            <?php echo ucfirst($loan_details['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Additional Action Buttons -->
                                            <div class="row mt-4">
                                                <div class="col-12">
                                                    <div class="d-flex gap-3 flex-wrap">
                                                        <a href="<?php echo BASE_URL; ?>/modules/loans/edit.php?loan_id=<?php echo $loan_id; ?>" 
                                                           class="btn-edit">
                                                            <i class="bi bi-pencil-square me-1"></i>Edit Loan Details
                                                        </a>
                                                        <?php if (in_array($loan_details['status'], ['active', 'disbursed'])): ?>
                                                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#markSettledModal">
                                                            <i class="bi bi-check-circle me-1"></i>Mark as Settled
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if (in_array($loan_details['status'], ['active', 'disbursed'])): ?>
                                                        <a href="<?php echo BASE_URL; ?>/modules/payment/new_payment_entry.php?loan_id=<?php echo $loan_id; ?>" 
                                                           class="btn btn-warning">
                                                            <i class="bi bi-credit-card me-1"></i>Record Payment
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Installments Tab -->
                                        <div class="tab-pane fade" id="installments" role="tabpanel" aria-labelledby="installments-tab">
                                            <?php if (empty($installments)): ?>
                                                <div class="alert alert-info text-center">
                                                    <i class="bi bi-info-circle me-2"></i>
                                                    No installments found for this loan.
                                                </div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-striped">
                                                        <thead class="table-dark">
                                                            <tr>
                                                                <th>Week #</th>
                                                                <th>Amount</th>
                                                                <th>Paid</th>
                                                                <th>Remaining</th>
                                                                <th>Due Date</th>
                                                                <th>Completion Date</th>
                                                                <th>Status</th>
                                                                <th>Progress</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                            $sequential_break = false;
                                                            foreach ($installments as $installment): 
                                                                $remaining = $installment['amount'] - $installment['paid_amount'];
                                                                $is_overpaid = $remaining < 0;
                                                                $progress = $installment['amount'] > 0 ? ($installment['paid_amount'] / $installment['amount']) * 100 : 0;
                                                                
                                                                if (!$sequential_break && $installment['paid_amount'] < $installment['amount'] && $installment['installment_number'] > 1) {
                                                                    $sequential_break = true;
                                                                }
                                                            ?>
                                                                <tr class="<?php echo $is_overpaid ? 'overpayment' : ''; ?><?php echo $sequential_break ? ' table-warning' : ''; ?>">
                                                                    <td>
                                                                        <?php echo $installment['installment_number']; ?>
                                                                        <?php if ($sequential_break): ?>
                                                                            <br><small class="text-warning">⚠️ Sequential break</small>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>Rs. <?php echo number_format($installment['amount'], 2); ?></td>
                                                                    <td>Rs. <?php echo number_format($installment['paid_amount'], 2); ?></td>
                                                                    <td>
                                                                        <?php if ($is_overpaid): ?>
                                                                            <span class="text-danger">(Rs. <?php echo number_format(abs($remaining), 2); ?>)</span>
                                                                        <?php else: ?>
                                                                            Rs. <?php echo number_format($remaining, 2); ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td><?php echo isset($installment['due_date']) ? $installment['due_date'] : 'N/A'; ?></td>
                                                                    <td>
                                                                        <?php 
                                                                        if (!empty($installment['completion_date'])) {
                                                                            echo $installment['completion_date'];
                                                                        } elseif (!empty($installment['effective_completion_date'])) {
                                                                            echo $installment['effective_completion_date'];
                                                                        } else {
                                                                            echo 'Not Completed';
                                                                        }
                                                                        ?>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge 
                                                                            <?php echo $installment['status'] == 'paid' ? 'badge-completed' : 
                                                                                  ($installment['status'] == 'partial' ? 'badge-partial' : 'badge-pending'); ?>">
                                                                            <?php echo ucfirst($installment['status']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <div class="progress" style="height: 20px;">
                                                                            <div class="progress-bar 
                                                                                <?php echo $installment['status'] == 'paid' ? 'bg-success' : 
                                                                                      ($installment['status'] == 'partial' ? 'bg-warning' : 'bg-danger'); ?>" 
                                                                                style="width: <?php echo $progress; ?>%">
                                                                                <?php echo number_format($progress, 0); ?>%
                                                                            </div>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Payment History Tab -->
                                        <div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
                                            <?php if (empty($original_payments)): ?>
                                                <div class="text-center py-4 text-muted">
                                                    <i class="bi bi-receipt display-4"></i>
                                                    <p class="mt-2 mb-0">No Payments Recorded Yet</p>
                                                </div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-striped">
                                                        <thead class="table-dark">
                                                            <tr>
                                                                <th>PAYMENT DATE</th>
                                                                <?php if ($has_actual_date): ?>
                                                                    <th>ACTUAL PAYMENT DATE</th>
                                                                <?php endif; ?>
                                                                <th>SYSTEM ENTRY DATE & TIME</th>
                                                                <th>AMOUNT</th>
                                                                <th>PAYMENT METHOD</th>
                                                                <th>REFERENCE</th>
                                                                <th>ENTERED BY</th>
                                                                <th>STATUS</th>
                                                                <th>ACTIONS</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                            foreach ($original_payments as $payment): 
                                                                $is_reversal = $payment['is_reversal'];
                                                                $is_reversed = $payment['is_reversed'];
                                                            ?>
                                                                <tr class="<?php echo $is_reversal ? 'reversal-payment' : ($is_reversed ? 'reversed-payment' : ''); ?>">
                                                                    <td>
                                                                        <?php 
                                                                        $display_date = !empty($payment['actual_payment_date']) ? $payment['actual_payment_date'] : $payment['payment_date'];
                                                                        echo $display_date;
                                                                        ?>
                                                                    </td>
                                                                    <?php if ($has_actual_date): ?>
                                                                        <td>
                                                                            <?php echo !empty($payment['actual_payment_date']) ? $payment['actual_payment_date'] : '<span class="text-muted">Not specified</span>'; ?>
                                                                        </td>
                                                                    <?php endif; ?>
                                                                    <td>
                                                                        <div class="text-small">
                                                                            <div><strong>Date:</strong> <?php echo date('Y-m-d', strtotime($payment['created_at'])); ?></div>
                                                                            <div><strong>Time:</strong> <?php echo date('H:i:s', strtotime($payment['created_at'])); ?></div>
                                                                        </div>
                                                                    </td>
                                                                    <td>
                                                                        <strong class="<?php echo $is_reversal ? 'text-danger' : ''; ?>">
                                                                            <?php echo $is_reversal ? '-' : ''; ?>Rs. <?php echo number_format(abs($payment['amount']), 2); ?>
                                                                        </strong>
                                                                        <?php if ($is_reversal): ?>
                                                                            <br><small class="text-danger">Reversal Amount</small>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                                                    <td>
                                                                        <?php 
                                                                        echo $payment['payment_reference'];
                                                                        if ($payment['reversal_notes']) {
                                                                            echo '<br><small class="text-muted">' . $payment['reversal_notes'] . '</small>';
                                                                        }
                                                                        ?>
                                                                    </td>
                                                                    <td>
                                                                        <strong><?php echo htmlspecialchars($payment['entered_by_name']); ?></strong>
                                                                        <?php if (!empty($payment['created_by'])): ?>
                                                                            <br><small class="text-muted">ID: <?php echo $payment['created_by']; ?></small>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <?php if ($is_reversal): ?>
                                                                            <span class="reversal-badge">
                                                                                <i class="bi bi-arrow-counterclockwise me-1"></i>REVERSAL
                                                                            </span>
                                                                        <?php elseif ($is_reversed): ?>
                                                                            <span class="reversed-badge">
                                                                                <i class="bi bi-exclamation-triangle me-1"></i>REVERSED
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-success">ACTIVE</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <!-- Action buttons -->
                                                                        <?php if (!$is_reversal && !$is_reversed && $_SESSION['user_type'] == 'admin'): ?>
                                                                            <a href="<?php echo BASE_URL; ?>/modules/payment/reverse_payment.php?payment_id=<?php echo $payment['id']; ?>" 
                                                                               class="btn btn-sm btn-outline-danger" 
                                                                               onclick="return confirm('Are you sure you want to reverse this payment? This action cannot be undone.')">
                                                                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse
                                                                            </a>
                                                                        <?php elseif ($is_reversal || $is_reversed): ?>
                                                                            <span class="text-muted small">No actions</span>
                                                                        <?php else: ?>
                                                                            <span class="text-muted small">No permission</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Advance Payments Tab -->
                                        <div class="tab-pane fade" id="advance" role="tabpanel" aria-labelledby="advance-tab">
                                            <div class="p-3">
                                                <h5 class="mb-3 text-primary">
                                                    <i class="bi bi-cash-coin me-2"></i>Advance Payments Summary
                                                </h5>
                                                
                                                <?php if ($total_advance_payments > 0): ?>
                                                    <div class="alert alert-success">
                                                        <i class="bi bi-info-circle me-2"></i>
                                                        <strong>Total Advance Payments: Rs. <?php echo number_format($total_advance_payments, 2); ?></strong>
                                                        - <?php echo count($advance_payment_details); ?> installments paid in advance
                                                    </div>
                                                    
                                                    <div class="table-responsive">
                                                        <table class="table table-bordered table-striped">
                                                            <thead class="table-success">
                                                                <tr>
                                                                    <th>Week #</th>
                                                                    <th>Due Date</th>
                                                                    <th>Paid Amount</th>
                                                                    <th>Days Advanced</th>
                                                                    <th>Status</th>
                                                                    <th>Completion Date</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($advance_payment_details as $advance): 
                                                                    $installment_data = null;
                                                                    foreach ($installments as $inst) {
                                                                        if ($inst['installment_number'] == $advance['installment_number']) {
                                                                            $installment_data = $inst;
                                                                            break;
                                                                        }
                                                                    }
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <strong>#<?php echo $advance['installment_number']; ?></strong>
                                                                    </td>
                                                                    <td><?php echo $advance['due_date']; ?></td>
                                                                    <td class="text-success fw-bold">Rs. <?php echo number_format($advance['paid_amount'], 2); ?></td>
                                                                    <td>
                                                                        <span class="badge bg-success">
                                                                            <i class="bi bi-calendar-check me-1"></i>
                                                                            <?php echo $advance['days_advanced']; ?> days
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge <?php 
                                                                            echo $advance['status'] == 'paid' ? 'badge-completed' : 
                                                                                 ($advance['status'] == 'partial' ? 'badge-partial' : 'badge-pending'); 
                                                                        ?>">
                                                                            <?php echo ucfirst($advance['status']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <?php 
                                                                        if ($installment_data && !empty($installment_data['completion_date'])) {
                                                                            echo $installment_data['completion_date'];
                                                                        } elseif ($installment_data && !empty($installment_data['effective_completion_date'])) {
                                                                            echo $installment_data['effective_completion_date'];
                                                                        } else {
                                                                            echo '<span class="text-muted">Not completed</span>';
                                                                        }
                                                                        ?>
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    
                                                    <div class="row mt-4">
                                                        <div class="col-md-6">
                                                            <div class="card bg-light">
                                                                <div class="card-body">
                                                                    <h6 class="card-title">
                                                                        <i class="bi bi-graph-up text-success me-2"></i>
                                                                        Advance Payment Benefits
                                                                    </h6>
                                                                    <ul class="list-unstyled mb-0">
                                                                        <li><i class="bi bi-check-circle text-success me-2"></i>Reduces overall interest cost</li>
                                                                        <li><i class="bi bi-check-circle text-success me-2"></i>Improves credit score</li>
                                                                        <li><i class="bi bi-check-circle text-success me-2"></i>Creates payment buffer</li>
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="card bg-light">
                                                                <div class="card-body">
                                                                    <h6 class="card-title">
                                                                        <i class="bi bi-info-circle text-primary me-2"></i>
                                                                        Payment Distribution
                                                                    </h6>
                                                                    <p class="mb-0 small">
                                                                        Advance payments are automatically applied to future installments 
                                                                        in sequential order, ensuring proper allocation.
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-5">
                                                        <i class="bi bi-cash-coin display-1 text-muted"></i>
                                                        <h4 class="text-muted mt-3">No Advance Payments</h4>
                                                        <p class="text-muted">
                                                            All payments have been made on or after their due dates.
                                                        </p>
                                                        <div class="alert alert-info mt-3">
                                                            <i class="bi bi-lightbulb me-2"></i>
                                                            <strong>Tip:</strong> Customers can make advance payments to reduce their 
                                                            loan burden and improve their credit standing.
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark as Settled Modal -->
    <div class="modal fade" id="markSettledModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Loan as Settled</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="settlement_date" class="form-label">Settlement Date</label>
                            <input type="date" class="form-control" id="settlement_date" name="settlement_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <p class="text-muted">This will mark the loan as fully settled and close all pending installments.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="mark_settled" class="btn btn-success">Mark as Settled</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Auto-activate tab from URL hash
        document.addEventListener('DOMContentLoaded', function() {
            var hash = window.location.hash;
            if (hash) {
                var trigger = document.querySelector('[data-bs-target="' + hash + '"]');
                if (trigger) {
                    bootstrap.Tab.getInstance(trigger)?.show();
                }
            }
        });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>
<?php
    } else {
        echo "<div class='alert alert-danger'>Loan not found!</div>";
    }
} else {
    echo "<div class='alert alert-danger'>Loan ID not specified!</div>";
}
?>