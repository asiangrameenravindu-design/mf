<?php
// includes/loan_sms_functions.php

function sendLoanApplicationSMS($loan_id) {
    global $conn;
    
    $sql = "SELECT l.*, c.name as customer_name, c.phone, c.nic 
            FROM loans l 
            JOIN customers c ON l.customer_id = c.id 
            WHERE l.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $loan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $loan = $result->fetch_assoc();
    
    if (!$loan) {
        return ['success' => false, 'message' => 'Loan not found'];
    }
    
    $message = "Dear {$loan['customer_name']}, your loan application {$loan['loan_number']} for Rs. " . 
               number_format($loan['amount'], 2) . " has been submitted successfully. We will review and update you shortly.";
    
    $result = sendSMS($loan['phone'], $message);
    
    // Update SMS log with loan ID
    if (isset($result['log_id'])) {
        $update_sql = "UPDATE sms_logs SET loan_id = ?, customer_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("iii", $loan_id, $loan['customer_id'], $result['log_id']);
        $update_stmt->execute();
    }
    
    return $result;
}

function sendLoanApprovalSMS($loan_id) {
    global $conn;
    
    $sql = "SELECT l.*, c.name as customer_name, c.phone, c.nic 
            FROM loans l 
            JOIN customers c ON l.customer_id = c.id 
            WHERE l.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $loan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $loan = $result->fetch_assoc();
    
    if (!$loan) {
        return ['success' => false, 'message' => 'Loan not found'];
    }
    
    $message = "Congratulations {$loan['customer_name']}! Your loan {$loan['loan_number']} for Rs. " . 
               number_format($loan['amount'], 2) . " has been approved. Total repayment: Rs. " . 
               number_format($loan['total_loan_amount'], 2) . ". Weekly installment: Rs. " . 
               number_format($loan['weekly_installment'], 2) . " for {$loan['number_of_weeks']} weeks.";
    
    $result = sendSMS($loan['phone'], $message);
    
    if (isset($result['log_id'])) {
        $update_sql = "UPDATE sms_logs SET loan_id = ?, customer_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("iii", $loan_id, $loan['customer_id'], $result['log_id']);
        $update_stmt->execute();
    }
    
    return $result;
}

function sendPaymentConfirmationSMS($payment_id) {
    global $conn;
    
    $sql = "SELECT p.*, l.loan_number, l.total_loan_amount, l.weekly_installment, l.customer_id,
                   c.name as customer_name, c.phone, 
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE loan_id = l.id) as total_paid,
                   (l.total_loan_amount - (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE loan_id = l.id)) as remaining_balance
            FROM payments p
            JOIN loans l ON p.loan_id = l.id
            JOIN customers c ON l.customer_id = c.id
            WHERE p.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment not found'];
    }
    
    $message = "Dear {$payment['customer_name']}, payment of Rs. " . 
               number_format($payment['amount'], 2) . " received for loan {$payment['loan_number']}. " .
               "Total paid: Rs. " . number_format($payment['total_paid'], 2) . ". " .
               "Remaining: Rs. " . number_format($payment['remaining_balance'], 2) . ". " .
               "Date: " . date('Y-m-d', strtotime($payment['payment_date']));
    
    $result = sendSMS($payment['phone'], $message);
    
    if (isset($result['log_id'])) {
        $update_sql = "UPDATE sms_logs SET loan_id = ?, customer_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("iii", $payment['loan_id'], $payment['customer_id'], $result['log_id']);
        $update_stmt->execute();
    }
    
    return $result;
}