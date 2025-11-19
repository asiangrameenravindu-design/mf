<?php
// modules/loans/new.php

// Enable detailed error logging at the VERY TOP
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/dtrmfslh/public_html/loan_debug.log');

error_log("🚨🚨🚨 LOAN APPLICATION PAGE LOADED 🚨🚨🚨");
error_log("Timestamp: " . date('Y-m-d H:i:s'));
error_log("Script: " . $_SERVER['PHP_SELF']);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

/// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

$error = '';
$success = '';
$calculation = null;

// Check if required tables exist, if not create them
checkAndCreateLoanTables();

// Field officers ලබාගැනීම
function getFieldOfficers() {
    global $conn;
    $sql = "SELECT id, national_id, full_name, short_name, position, phone, email 
            FROM staff 
            WHERE position = 'field_officer' 
            ORDER BY full_name";
    $result = $conn->query($sql);
    return $result;
}

// Selected field officer අනුව CBOs ලබාගැනීම  
function getCBOsByFieldOfficer($field_officer_id) {
    global $conn;
    $sql = "SELECT * FROM cbo WHERE staff_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $field_officer_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Get CBO details including field officer
function getCBOWithFieldOfficer($cbo_id) {
    global $conn;
    $sql = "SELECT cb.*, s.id as field_officer_id, s.full_name as field_officer_name
            FROM cbo cb 
            LEFT JOIN staff s ON cb.staff_id = s.id 
            WHERE cb.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cbo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get customer by NIC from specific CBO
function getCustomerByNICFromCBO($nic, $cbo_id) {
    global $conn;
    $sql = "SELECT c.* FROM customers c 
            JOIN cbo_members cm ON c.id = cm.customer_id 
            WHERE c.national_id = ? AND cm.cbo_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $nic, $cbo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get loan settings from database
function getLoanSettingsFromDB() {
    global $conn;
    
    $settings = [];
    $sql = "SELECT setting_key, setting_value FROM system_settings 
            WHERE setting_key IN ('loan_plans', 'max_loan_amount', 'min_loan_amount', 
                                 'default_interest_rate', 'late_payment_fee', 'service_charge_rate',
                                 'insurance_fee', 'auto_calculate_document_charge')";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    // Parse loan plans
    if (isset($settings['loan_plans'])) {
        $settings['loan_plans'] = json_decode($settings['loan_plans'], true);
    } else {
        // Default loan plans if not set in database
        $settings['loan_plans'] = [
            ['amount' => 15000, 'weeks' => 19, 'interest_rate' => 36.80, 'document_charge' => 450],
            ['amount' => 20000, 'weeks' => 22, 'interest_rate' => 35.30, 'document_charge' => 600],
            ['amount' => 25000, 'weeks' => 23, 'interest_rate' => 35.70, 'document_charge' => 750],
            ['amount' => 30000, 'weeks' => 24, 'interest_rate' => 35.20, 'document_charge' => 900],
            ['amount' => 35000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1050],
            ['amount' => 40000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1200],
            ['amount' => 45000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1350],
            ['amount' => 50000, 'weeks' => 26, 'interest_rate' => 35.20, 'document_charge' => 1500],
            ['amount' => 55000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1650],
            ['amount' => 60000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1800],
            ['amount' => 65000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1950],
            ['amount' => 70000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 2100],
            ['amount' => 75000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2250],
            ['amount' => 80000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2400],
            ['amount' => 85000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2550],
            ['amount' => 90000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2700],
            ['amount' => 95000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2850],
            ['amount' => 100000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 3000]
        ];
    }
    
    return $settings;
}

function getLoanPlanForAmount($amount) {
    $loan_settings = getLoanSettingsFromDB();
    $loan_plans = $loan_settings['loan_plans'];
    
    // Find the closest matching loan plan
    $closest_plan = null;
    $min_difference = PHP_INT_MAX;
    
    foreach ($loan_plans as $plan) {
        $difference = abs($plan['amount'] - $amount);
        if ($difference < $min_difference) {
            $min_difference = $difference;
            $closest_plan = $plan;
        }
    }
    
    return $closest_plan;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== FORM SUBMISSION DETECTED ===");
    error_log("POST Action: " . array_keys($_POST)[0]);
    error_log("POST Data: " . print_r($_POST, true));
    
    if (isset($_POST['add_customer'])) {
        // Add customer to loan applications list
        $customer_id = intval($_POST['customer_id']);
        $loan_amount = floatval($_POST['loan_amount']);
        $cbo_id = intval($_POST['cbo_id']);
        
        error_log("🟡 ADD_CUSTOMER - Customer ID: $customer_id, Amount: $loan_amount, CBO: $cbo_id");
        
        if ($customer_id && $loan_amount && $cbo_id) {
            // 🔥 AUTO-ASSIGN FIELD OFFICER FROM CBO
            $cbo_data = getCBOWithFieldOfficer($cbo_id);
            $field_officer_id = $cbo_data['field_officer_id'] ?? 0;
            
            error_log("🔥 AUTO-ASSIGNED Field Officer: " . ($cbo_data['field_officer_name'] ?? 'None') . " (ID: $field_officer_id)");
            
            if (!isset($_SESSION['loan_applications'])) {
                $_SESSION['loan_applications'] = [];
            }
            
            // functions.php එකේ ඇති getCustomerById() function එක භාවිතා කරයි
            $customer = getCustomerById($customer_id);
            $loan_plan = getLoanPlanForAmount($loan_amount);
            
            if ($customer && $loan_plan) {
                $interest_amount = ($loan_amount * $loan_plan['interest_rate'] / 100);
                $total_amount = $loan_amount + $interest_amount;
                $weekly_installment = $total_amount / $loan_plan['weeks'];
                
                $_SESSION['loan_applications'][$customer_id] = [
                    'customer_id' => $customer_id,
                    'customer_name' => $customer['full_name'],
                    'customer_nic' => $customer['national_id'],
                    'loan_amount' => $loan_amount,
                    'interest_rate' => $loan_plan['interest_rate'],
                    'interest_amount' => $interest_amount,
                    'number_of_weeks' => $loan_plan['weeks'],
                    'total_loan_amount' => $total_amount,
                    'weekly_installment' => $weekly_installment,
                    'document_charge' => $loan_plan['document_charge'],
                    'field_officer_id' => $field_officer_id, // 🔥 Auto-assigned from CBO
                    'cbo_id' => $cbo_id,
                    'customer_phone' => $customer['phone'], // Add phone for SMS
                    'field_officer_name' => $cbo_data['field_officer_name'] ?? 'Not Assigned' // 🔥 Field officer name
                ];
                
                $success = "Customer added to loan applications! Field Officer: " . ($cbo_data['field_officer_name'] ?? 'Auto-assigned');
                error_log("✅ Customer added to applications: " . $customer['full_name'] . " | Field Officer: " . ($cbo_data['field_officer_name'] ?? 'None'));
                
                // Reset form fields
                unset($_POST['customer_nic']);
                unset($_POST['customer_id']);
                unset($_POST['loan_amount']);
            } else {
                $error = "Customer or loan plan not found!";
                error_log("❌ ADD_CUSTOMER - Customer or loan plan not found");
            }
        } else {
            $error = "Please select all required fields!";
            error_log("❌ ADD_CUSTOMER - Missing required fields");
        }
        
    } elseif (isset($_POST['remove_customer'])) {
        // Remove customer from loan applications list
        $customer_id = intval($_POST['customer_id']);
        error_log("🟡 REMOVE_CUSTOMER - Customer ID: $customer_id");
        
        if (isset($_SESSION['loan_applications'][$customer_id])) {
            unset($_SESSION['loan_applications'][$customer_id]);
            $success = "Customer removed from loan applications!";
            error_log("✅ Customer removed from applications");
        }
        
    } elseif (isset($_POST['submit_loans'])) {
        // Submit all loan applications
        error_log("🔵 SUBMIT_LOANS TRIGGERED - START");
        
        if (isset($_SESSION['loan_applications']) && !empty($_SESSION['loan_applications'])) {
            $staff_id = $_SESSION['user_id'] ?? 1;
            
            error_log("🟡 Processing " . count($_SESSION['loan_applications']) . " applications");
            
            try {
                $conn->begin_transaction();
                $success_count = 0;
                $sms_results = [];
                
                foreach ($_SESSION['loan_applications'] as $app_id => $application) {
                    error_log("🎯 Processing application for: " . $application['customer_name']);
                    error_log("   🔥 Field Officer: " . $application['field_officer_name'] . " (ID: " . $application['field_officer_id'] . ")");
                    
                    // Generate loan number
                    $loan_number = generateLoanNumber($application['cbo_id']);
                    error_log("   Loan Number: " . $loan_number);
                    
                    // 🔥 AUTO-ASSIGN FIELD OFFICER TO LOAN
                    $assigned_staff_id = $application['field_officer_id'] ?: $staff_id;
                    
                    // Insert loan application with FIELD OFFICER ASSIGNMENT
                    $sql = "INSERT INTO loans (loan_number, customer_id, cbo_id, staff_id, 
                                              amount, service_charge, document_charge, 
                                              total_loan_amount, weekly_installment, number_of_weeks, 
                                              interest_rate, applied_date, status) 
                            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, CURDATE(), 'pending')";
                    
                    error_log("   SQL: " . $sql);
                    error_log("   Staff ID: " . $assigned_staff_id . " (Field Officer Auto-Assigned)");
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("❌ LOAN PREPARE FAILED: " . $conn->error);
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $bind_result = $stmt->bind_param("siiiddddid", 
                        $loan_number, 
                        $application['customer_id'], 
                        $application['cbo_id'], 
                        $assigned_staff_id, // 🔥 Auto-assigned field officer
                        $application['loan_amount'], 
                        $application['document_charge'],
                        $application['total_loan_amount'], 
                        $application['weekly_installment'], 
                        $application['number_of_weeks'], 
                        $application['interest_rate']
                    );
                    
                    if (!$bind_result) {
                        error_log("❌ LOAN BIND FAILED: " . $stmt->error);
                        throw new Exception("Bind failed: " . $stmt->error);
                    }
                    
                    $execute_result = $stmt->execute();
                    if (!$execute_result) {
                        error_log("❌ LOAN EXECUTE FAILED: " . $stmt->error);
                        throw new Exception("Execute failed: " . $stmt->error . " - Query: " . $sql);
                    }
                    
                    $loan_id = $stmt->insert_id;
                    error_log("✅ LOAN INSERTED SUCCESSFULLY - ID: " . $loan_id . " | Field Officer: " . $application['field_officer_name']);
                    
                    // Create loan installments
                    error_log("🔄 Calling createLoanInstallments for loan: " . $loan_id);
                    $installment_result = createLoanInstallments(
                        $loan_id, 
                        $application['weekly_installment'], 
                        $application['number_of_weeks']
                    );
                    
                    if ($installment_result) {
                        $success_count++;
                        error_log("✅ Installments created successfully for loan: " . $loan_id);
                    } else {
                        error_log("❌ Installment creation failed for loan: " . $loan_id);
                        throw new Exception("Failed to create installments for loan: " . $loan_id);
                    }
                    
                    // ✅ SMS Integration - Send loan application confirmation
                    if (SMS_ENABLED && !empty($application['customer_phone'])) {
                        $message = "Dear {$application['customer_name']}, your loan application {$loan_number} for Rs. " . 
                                  number_format($application['loan_amount'], 2) . " has been submitted successfully. " .
                                  "We will review and update you shortly.";
                        
                        $sms_result = sendSMS($application['customer_phone'], $message);
                        $sms_results[] = [
                            'customer' => $application['customer_name'],
                            'phone' => $application['customer_phone'],
                            'success' => $sms_result['success'],
                            'message' => $sms_result['message']
                        ];
                        
                        error_log("📱 SMS sent to: " . $application['customer_phone'] . " - Result: " . ($sms_result['success'] ? 'Success' : 'Failed'));
                    }
                }
                
                $conn->commit();
                error_log("🎉 TRANSACTION COMMITTED - " . $success_count . " loans created successfully with Field Officer Auto-Assignment");
                
                // Prepare success message with SMS results
                $success_message = "Successfully submitted " . $success_count . " loan application(s) with Field Officer Auto-Assignment!";
                
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
                    
                    $success_message .= " SMS notifications sent: {$sms_success_count} successful, {$sms_failed_count} failed.";
                    
                    // Show detailed SMS results if any failed
                    if ($sms_failed_count > 0) {
                        $failed_sms_details = "";
                        foreach ($sms_results as $sms) {
                            if (!$sms['success']) {
                                $failed_sms_details .= "{$sms['customer']} ({$sms['phone']}): {$sms['message']}. ";
                            }
                        }
                        $success_message .= " Failed: " . $failed_sms_details;
                    }
                }
                
                $success = $success_message;
                unset($_SESSION['loan_applications']);
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error submitting loan applications: " . $e->getMessage();
                $error = $error_message;
                error_log("💥 CRITICAL ERROR - TRANSACTION ROLLED BACK: " . $error_message);
            }
        } else {
            $error = "No loan applications to submit!";
            error_log("⚠️ No applications in session");
        }
    } elseif (isset($_POST['clear_all'])) {
        // Clear all loan applications
        unset($_SESSION['loan_applications']);
        $success = "All loan applications cleared!";
        error_log("🟡 CLEAR_ALL - Applications cleared");
    } elseif (isset($_POST['search_customer'])) {
        // Search customer by NIC
        $customer_nic = trim($_POST['customer_nic']);
        $cbo_id = intval($_POST['cbo_id']);
        
        error_log("🟡 SEARCH_CUSTOMER - NIC: $customer_nic, CBO: $cbo_id");
        
        if ($customer_nic && $cbo_id) {
            $customer = getCustomerByNICFromCBO($customer_nic, $cbo_id);
            if ($customer) {
                $_POST['customer_id'] = $customer['id'];
                $success = "Customer found: " . $customer['full_name'];
                error_log("✅ Customer found: " . $customer['full_name']);
            } else {
                $error = "Customer not found with NIC: " . $customer_nic . " in selected CBO";
                error_log("❌ Customer not found with NIC: $customer_nic in CBO: $cbo_id");
            }
        }
    } elseif (isset($_POST['field_officer_id'])) {
        // Field officer selection changed
        $selected_field_officer = intval($_POST['field_officer_id']);
        error_log("🟡 FIELD_OFFICER_CHANGED - ID: $selected_field_officer");
    } elseif (isset($_POST['cbo_id'])) {
        // CBO selection changed
        $selected_cbo = intval($_POST['cbo_id']);
        error_log("🟡 CBO_CHANGED - ID: $selected_cbo");
        
        // Show field officer info for selected CBO
        if ($selected_cbo) {
            $cbo_data = getCBOWithFieldOfficer($selected_cbo);
            if ($cbo_data && $cbo_data['field_officer_name']) {
                $success = "CBO selected. Auto-assigned Field Officer: " . $cbo_data['field_officer_name'];
                error_log("✅ CBO Field Officer: " . $cbo_data['field_officer_name']);
            }
        }
    }
}

// Get loan settings
$loan_settings = getLoanSettingsFromDB();
$loan_table = $loan_settings['loan_plans'];

// Get initial data for dropdowns
$field_officers = getFieldOfficers();
$selected_field_officer = isset($_POST['field_officer_id']) ? intval($_POST['field_officer_id']) : '';
$selected_cbo = isset($_POST['cbo_id']) ? intval($_POST['cbo_id']) : '';
$selected_customer = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : '';
$selected_loan_amount = isset($_POST['loan_amount']) ? floatval($_POST['loan_amount']) : '';
$customer_nic = isset($_POST['customer_nic']) ? $_POST['customer_nic'] : '';

// Get CBOs if field officer is selected
$cbos = null;
if ($selected_field_officer) {
    $cbos = getCBOsByFieldOfficer($selected_field_officer);
}

// Get CBO field officer info if CBO is selected
$cbo_field_officer_info = null;
if ($selected_cbo) {
    $cbo_field_officer_info = getCBOWithFieldOfficer($selected_cbo);
}

// Get customer details if customer is selected
$customer_details = null;
if ($selected_customer) {
    $customer_details = getCustomerById($selected_customer);
}

error_log("=== LOAN APPLICATION PAGE RENDERED ===");
error_log("Selected CBO: $selected_cbo | Field Officer Info: " . ($cbo_field_officer_info['field_officer_name'] ?? 'None'));
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Loan Application - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
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
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
            border: none;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .loan-amount-btn {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            margin-bottom: 10px;
        }
        
        .loan-amount-btn:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }
        
        .loan-amount-btn.selected {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
        }
        
        .customer-badge {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
            border-radius: 20px;
            padding: 8px 15px;
            margin: 5px;
            display: inline-flex;
            align-items: center;
        }
        
        .applications-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .application-item {
            border-left: 4px solid var(--primary);
            background: #f8f9fa;
        }
        
        .form-select:disabled {
            background-color: #e9ecef;
            opacity: 0.6;
        }
        
        .customer-info-card {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .field-officer-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-btn {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #6c757d;
        }
        
        .sms-status {
            font-size: 0.8rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .sms-success {
            background: #d4edda;
            color: #155724;
        }
        
        .sms-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .auto-assign-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 0.9rem;
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
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/" class="text-decoration-none">Loans</a></li>
                                <li class="breadcrumb-item active">New Loan</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">New Loan Applications</h1>
                        <p class="text-muted mb-0">Create multiple loan applications at once</p>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex align-items-center">
                            <span class="badge bg-light text-dark me-2">
                                <i class="bi bi-phone me-1"></i>SMS: <?php echo SMS_ENABLED ? 'Enabled' : 'Disabled'; ?>
                            </span>
                            <?php if (SMS_TEST_MODE): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-info-circle me-1"></i>Test Mode
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left Column: Selection Forms -->
                <div class="col-lg-6">
                    <!-- Field Officer & CBO Selection -->
                    <div class="info-card">
                        <div class="card-header-custom">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-badge me-2"></i>Field Officer & CBO Selection
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="mainForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold text-dark">Field Officer *</label>
                                            <select class="form-select" name="field_officer_id" id="field_officer_id" required onchange="this.form.submit()">
                                                <option value="">Select Field Officer</option>
                                                <?php
                                                if ($field_officers && $field_officers->num_rows > 0) {
                                                    while ($officer = $field_officers->fetch_assoc()) {
                                                        $selected = ($selected_field_officer == $officer['id']) ? 'selected' : '';
                                                        echo '<option value="' . $officer['id'] . '" ' . $selected . '>' 
                                                             . htmlspecialchars($officer['full_name']) . ' - ' 
                                                             . htmlspecialchars($officer['position']) . '</option>';
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold text-dark">CBO *</label>
                                            <select class="form-select" name="cbo_id" id="cbo_id" required onchange="this.form.submit()" <?php echo !$selected_field_officer ? 'disabled' : ''; ?>>
                                                <option value="">Select CBO</option>
                                                <?php
                                                if ($selected_field_officer && $cbos && $cbos->num_rows > 0) {
                                                    while ($cbo = $cbos->fetch_assoc()) {
                                                        $selected = ($selected_cbo == $cbo['id']) ? 'selected' : '';
                                                        echo '<option value="' . $cbo['id'] . '" ' . $selected . '>' 
                                                             . htmlspecialchars($cbo['name']) . ' (' . $cbo['cbo_number'] . ')</option>';
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- 🔥 AUTO-ASSIGNED FIELD OFFICER INFO -->
                                <?php if ($selected_cbo && $cbo_field_officer_info && $cbo_field_officer_info['field_officer_name']): ?>
                                <div class="auto-assign-info">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-person-check-fill text-primary me-2"></i>
                                        <div>
                                            <strong>Auto-Assigned Field Officer:</strong>
                                            <span class="fw-bold text-primary"><?php echo htmlspecialchars($cbo_field_officer_info['field_officer_name']); ?></span>
                                            <br>
                                            <small class="text-muted">This field officer will be automatically assigned to all loans from this CBO</small>
                                        </div>
                                    </div>
                                </div>
                                <?php elseif ($selected_cbo): ?>
                                <div class="auto-assign-info" style="background: #fff3cd; border-color: #ffeaa7;">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                                        <div>
                                            <strong>No Field Officer Assigned to this CBO</strong>
                                            <br>
                                            <small class="text-muted">Please assign a field officer to this CBO in the system settings</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <!-- Customer Selection -->
                    <div class="info-card">
                        <div class="card-header-custom">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-people me-2"></i>Customer Selection
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="customerForm">
                                <input type="hidden" name="field_officer_id" value="<?php echo $selected_field_officer; ?>">
                                <input type="hidden" name="cbo_id" value="<?php echo $selected_cbo; ?>">
                                <input type="hidden" name="customer_id" id="customer_id" value="<?php echo $selected_customer; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-dark">Enter Customer NIC Number *</label>
                                    <div class="search-box">
                                        <input type="text" 
                                               class="form-control" 
                                               name="customer_nic" 
                                               id="customer_nic"
                                               value="<?php echo htmlspecialchars($customer_nic); ?>" 
                                               placeholder="Enter customer NIC number..."
                                               <?php echo !$selected_cbo ? 'disabled' : ''; ?>
                                               required>
                                        <?php if ($selected_cbo): ?>
                                        <button type="submit" name="search_customer" class="search-btn">
                                            <i class="bi bi-search"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">Enter the customer's NIC number and click search</small>
                                </div>

                                <?php if ($customer_details): ?>
                                <div class="customer-info-card">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="avatar bg-white text-success rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">
                                                <?php echo substr($customer_details['full_name'], 0, 1); ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($customer_details['full_name']); ?></h6>
                                            <p class="mb-1 small">NIC: <?php echo htmlspecialchars($customer_details['national_id']); ?></p>
                                            <p class="mb-0 small">Phone: <?php echo htmlspecialchars($customer_details['phone']); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="mb-4 mt-3">
                                    <label class="form-label fw-semibold text-dark">Select Loan Amount (Rs.) *</label>
                                    <div class="row g-2" id="loanAmounts">
                                        <?php 
                                        $amounts = array_column($loan_table, 'amount');
                                        foreach ($amounts as $amount): 
                                            $selected_class = ($selected_loan_amount == $amount) ? 'selected' : '';
                                        ?>
                                        <div class="col-6 col-md-4">
                                            <div class="loan-amount-btn <?php echo $selected_class; ?>" data-amount="<?php echo $amount; ?>">
                                                <div class="fw-bold text-primary">Rs. <?php echo number_format($amount); ?></div>
                                                <small class="text-muted">
                                                    <?php 
                                                    $plan = getLoanPlanForAmount($amount);
                                                    echo $plan ? $plan['weeks'] . ' weeks' : 'N/A';
                                                    ?>
                                                </small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="loan_amount" id="selected_loan_amount" value="<?php echo $selected_loan_amount; ?>" required>
                                </div>

                                <button type="submit" name="add_customer" class="btn btn-primary-custom w-100 text-white" <?php echo !($selected_customer && $selected_loan_amount) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-plus-circle me-2"></i>Add Customer to Loan Applications
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Loan Applications List -->
                <div class="col-lg-6">
                    <!-- Loan Applications List -->
                    <div class="info-card">
                        <div class="card-header-custom" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-check me-2"></i>Loan Applications
                                    <span class="badge bg-light text-dark ms-2" id="applicationsCount">
                                        <?php echo isset($_SESSION['loan_applications']) ? count($_SESSION['loan_applications']) : 0; ?>
                                    </span>
                                </h5>
                                <?php if (isset($_SESSION['loan_applications']) && !empty($_SESSION['loan_applications'])): ?>
                                <form method="POST" class="d-inline">
                                    <button type="submit" name="clear_all" class="btn btn-light btn-sm">
                                        <i class="bi bi-trash me-1"></i>Clear All
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="applications-list">
                                <?php if (isset($_SESSION['loan_applications']) && !empty($_SESSION['loan_applications'])): ?>
                                    <?php foreach ($_SESSION['loan_applications'] as $application): ?>
                                    <div class="application-item p-3 mb-3 rounded">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div style="flex: 1;">
                                                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($application['customer_name']); ?></h6>
                                                <p class="mb-1 text-muted small">NIC: <?php echo htmlspecialchars($application['customer_nic']); ?></p>
                                                <p class="mb-1">
                                                    <strong>Loan: Rs. <?php echo number_format($application['loan_amount']); ?></strong> | 
                                                    <strong>Total: Rs. <?php echo number_format($application['total_loan_amount']); ?></strong>
                                                </p>
                                                <p class="mb-1 text-muted small">
                                                    <?php echo $application['number_of_weeks']; ?> weeks | 
                                                    Weekly: Rs. <?php echo number_format($application['weekly_installment']); ?>
                                                </p>
                                                
                                                <!-- 🔥 FIELD OFFICER INFO IN APPLICATION -->
                                                <div class="field-officer-badge">
                                                    <i class="bi bi-person-badge me-1"></i>
                                                    <strong>Field Officer:</strong> 
                                                    <?php echo htmlspecialchars($application['field_officer_name']); ?>
                                                </div>
                                                
                                                <?php if (!empty($application['customer_phone'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($application['customer_phone']); ?>
                                                        <?php if (SMS_ENABLED): ?>
                                                            <span class="sms-status sms-success">SMS Ready</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="customer_id" value="<?php echo $application['customer_id']; ?>">
                                                <button type="submit" name="remove_customer" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox display-4 mb-3"></i>
                                        <p>No loan applications added yet</p>
                                        <small>Select Field Officer, CBO, enter Customer NIC and select Loan Amount to add applications</small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (isset($_SESSION['loan_applications']) && !empty($_SESSION['loan_applications'])): ?>
                            <div class="mt-4">
                                <form method="POST">
                                    <button type="submit" name="submit_loans" class="btn btn-success btn-lg w-100">
                                        <i class="bi bi-check-circle me-2"></i>Submit All Loan Applications
                                        <?php if (SMS_ENABLED): ?>
                                            <br><small class="text-white-50">(SMS notifications will be sent automatically)</small>
                                        <?php endif; ?>
                                    </button>
                                    <div class="text-center mt-2">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Field officers will be automatically assigned based on CBO selection
                                        </small>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="info-card">
                        <div class="card-body text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/customer/new.php" class="btn btn-outline-primary me-2">
                                <i class="bi bi-plus-circle me-1"></i>Add New Customer
                            </a>
                            <a href="new.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-repeat me-1"></i>Refresh
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Loan amount selection
        document.querySelectorAll('.loan-amount-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove selected class from all buttons
                document.querySelectorAll('.loan-amount-btn').forEach(b => {
                    b.classList.remove('selected');
                });
                
                // Add selected class to clicked button
                this.classList.add('selected');
                
                // Set the hidden input value
                const amount = this.getAttribute('data-amount');
                document.getElementById('selected_loan_amount').value = amount;
                
                // Enable/disable add button based on selection
                const customerSelected = document.getElementById('customer_id').value;
                const addCustomerBtn = document.querySelector('button[name="add_customer"]');
                addCustomerBtn.disabled = !(customerSelected && amount);
            });
        });

        // Auto-enable add button if both fields are selected on page load
        document.addEventListener('DOMContentLoaded', function() {
            const customerSelected = document.getElementById('customer_id').value;
            const loanAmountSelected = document.getElementById('selected_loan_amount').value;
            const addCustomerBtn = document.querySelector('button[name="add_customer"]');
            
            if (customerSelected && loanAmountSelected) {
                addCustomerBtn.disabled = false;
            }
            
            // Auto-select loan amount buttons
            if (loanAmountSelected) {
                document.querySelectorAll('.loan-amount-btn').forEach(btn => {
                    if (btn.getAttribute('data-amount') === loanAmountSelected) {
                        btn.classList.add('selected');
                    }
                });
            }
        });

        // Enable NIC input when CBO is selected
        document.getElementById('cbo_id').addEventListener('change', function() {
            const customerNicInput = document.getElementById('customer_nic');
            if (this.value) {
                customerNicInput.disabled = false;
                customerNicInput.focus();
            } else {
                customerNicInput.disabled = true;
            }
        });

        // Auto-focus NIC input when CBO is selected on page load
        document.addEventListener('DOMContentLoaded', function() {
            const cboSelect = document.getElementById('cbo_id');
            const customerNicInput = document.getElementById('customer_nic');
            
            if (cboSelect.value) {
                customerNicInput.disabled = false;
            }
        });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>