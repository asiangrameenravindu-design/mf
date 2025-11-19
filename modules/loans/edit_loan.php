<?php
// modules/loans/edit_loan.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = [ 'admin'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

$loan_details = null;
$error = '';
$success = '';

// Get loan details
if (isset($_GET['loan_id'])) {
    $loan_id = intval($_GET['loan_id']);
    $loan_details = getLoanById($loan_id);
    
    if (!$loan_details) {
        $error = "Loan not found!";
    }
} else {
    $error = "No loan ID specified!";
}

// Handle loan update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    $loan_amount = floatval($_POST['loan_amount']);
    $number_of_weeks = intval($_POST['number_of_weeks']);
    $document_charge = floatval($_POST['document_charge']);
    $remarks = sanitizeInput($_POST['remarks']);
    $updated_by = $_SESSION['user_id'];
    
    // Validate inputs
    if ($loan_amount <= 0 || $number_of_weeks <= 0) {
        $error = "Please enter valid loan amount and number of weeks!";
    } else {
        try {
            $conn->begin_transaction();
            
            // Recalculate loan details based on your loan table
            $loan_table = [
                15000 => ['interest_rate' => 36.80, 'weeks' => 19, 'document_fee' => 450],
                20000 => ['interest_rate' => 35.30, 'weeks' => 22, 'document_fee' => 600],
                25000 => ['interest_rate' => 35.70, 'weeks' => 23, 'document_fee' => 750],
                30000 => ['interest_rate' => 35.20, 'weeks' => 24, 'document_fee' => 900],
                35000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1050],
                40000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1200],
                45000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1350],
                50000 => ['interest_rate' => 35.20, 'weeks' => 26, 'document_fee' => 1500],
                55000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1650],
                60000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1800],
                65000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1950],
                70000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 2100],
                75000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2250],
                80000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2400],
                85000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2550],
                90000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2700],
                95000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2850],
                100000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 3000]
            ];
            
            if (isset($loan_table[$loan_amount])) {
                $loan_data = $loan_table[$loan_amount];
                $interest_amount = ($loan_amount * $loan_data['interest_rate'] / 100);
                $total_amount = $loan_amount + $interest_amount;
                $weekly_installment = $total_amount / $loan_data['weeks'];
                
                // Update loan details - FIXED: Use individual parameters instead of references
                $update_sql = "UPDATE loans SET 
                              amount = ?,
                              service_charge = ?,
                              document_charge = ?,
                              total_loan_amount = ?,
                              weekly_installment = ?,
                              number_of_weeks = ?,
                              interest_rate = ?,
                              remarks = ?,
                              updated_by = ?,
                              updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?";
                
                $service_charge = 0; // Your table doesn't show this separately
                
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("dddddidsii", 
                    $loan_amount,
                    $service_charge,
                    $document_charge,
                    $total_amount,
                    $weekly_installment,
                    $number_of_weeks,
                    $loan_data['interest_rate'],
                    $remarks,
                    $updated_by,
                    $loan_id
                );
                
                if ($stmt->execute()) {
                    // Delete existing installments
                    $delete_installments_sql = "DELETE FROM loan_installments WHERE loan_id = ?";
                    $delete_stmt = $conn->prepare($delete_installments_sql);
                    $delete_stmt->bind_param("i", $loan_id);
                    $delete_stmt->execute();
                    
                    // Create new installments
                    createLoanInstallments($loan_id, $weekly_installment, $number_of_weeks);
                    
                    $conn->commit();
                    $success = "Loan updated successfully!";
                    
                    // Refresh loan details
                    $loan_details = getLoanById($loan_id);
                    
                } else {
                    throw new Exception("Failed to update loan: " . $stmt->error);
                }
            } else {
                throw new Exception("Invalid loan amount! Please select from the available amounts.");
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating loan: " . $e->getMessage();
        }
    }
}

// Handle disbursement date update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_disbursement_date'])) {
    $loan_id = intval($_POST['loan_id']);
    $disbursed_date = sanitizeInput($_POST['disbursed_date']);
    $updated_by = $_SESSION['user_id'];
    
    try {
        $conn->begin_transaction();
        
        // Update disbursement date
        $update_sql = "UPDATE loans SET 
                      disbursed_date = ?,
                      updated_by = ?,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sii", $disbursed_date, $updated_by, $loan_id);
        
        if ($stmt->execute()) {
            // Update installment due dates based on new disbursement date
            $update_installments_sql = "UPDATE loan_installments 
                                       SET due_date = DATE_ADD(?, INTERVAL (installment_number * 7) DAY)
                                       WHERE loan_id = ?";
            $update_installments_stmt = $conn->prepare($update_installments_sql);
            $update_installments_stmt->bind_param("si", $disbursed_date, $loan_id);
            $update_installments_stmt->execute();
            
            $conn->commit();
            $success = "Disbursement date updated successfully! Installment due dates recalculated.";
            
            // Refresh loan details
            $loan_details = getLoanById($loan_id);
            
        } else {
            throw new Exception("Failed to update disbursement date.");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating disbursement date: " . $e->getMessage();
    }
}

// Handle loan status change - SIMPLIFIED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $loan_id = intval($_POST['loan_id']);
    $new_status = sanitizeInput($_POST['new_status']);
    $status_reason = sanitizeInput($_POST['status_reason']);
    $changed_by = $_SESSION['user_id'];
    
    try {
        // Simple update - only change status and add reason if provided
        $update_sql = "UPDATE loans SET 
                      status = ?,
                      updated_by = ?,
                      updated_at = CURRENT_TIMESTAMP";
        
        // Add appropriate fields based on status
        if ($new_status === 'approved' && !empty($status_reason)) {
            $update_sql .= ", approved_date = CURDATE(), approved_by = ?, approval_notes = ?";
            $update_sql .= " WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("siisi", $new_status, $changed_by, $changed_by, $status_reason, $loan_id);
        }
        elseif ($new_status === 'rejected' && !empty($status_reason)) {
            $update_sql .= ", rejected_date = CURDATE(), rejected_by = ?, rejection_reason = ?";
            $update_sql .= " WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("siisi", $new_status, $changed_by, $changed_by, $status_reason, $loan_id);
        }
        elseif ($new_status === 'disbursed' && !empty($status_reason)) {
            $update_sql .= ", disbursed_date = CURDATE(), disbursed_by = ?, disbursement_notes = ?";
            $update_sql .= " WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("siisi", $new_status, $changed_by, $changed_by, $status_reason, $loan_id);
        }
        else {
            // For other status changes or no reason provided
            $update_sql .= " WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("sii", $new_status, $changed_by, $loan_id);
        }
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = "Loan status changed to " . $new_status . " successfully!";
            
            // Refresh loan details
            $loan_details = getLoanById($loan_id);
            
        } else {
            throw new Exception("Failed to change loan status.");
        }
        
    } catch (Exception $e) {
        $error = "Error changing loan status: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Loan - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --info: #7209b7;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e3e8ff 100%);
            min-height: 100vh;
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
                padding: 20px;
            }
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 20px 20px 0 0 !important;
            padding: 25px;
            border: none;
        }
        
        .card-header-warning {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
        }
        
        .card-header-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .card-header-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-update {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 233, 123, 0.3);
        }
        
        .btn-date {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-date:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .loan-amount-btn {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            margin-bottom: 8px;
        }
        
        .loan-amount-btn:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }
        
        .loan-amount-btn.selected {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .calculation-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
        }
        
        .date-input-group {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="row align-items-center">
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/" class="text-decoration-none text-muted">Loans</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold">Edit Loan</li>
                            </ol>
                        </nav>
                        <h1 class="h2 mb-1 fw-bold text-dark">Edit Loan</h1>
                        <p class="text-muted mb-0">Update loan details and correct errors</p>
                    </div>
                    <div class="col-auto">
                        <?php if ($loan_details): ?>
                        <span class="status-badge bg-<?php 
                            echo $loan_details['status'] == 'approved' ? 'success' : 
                                 ($loan_details['status'] == 'pending' ? 'warning' : 
                                 ($loan_details['status'] == 'rejected' ? 'danger' : 
                                 ($loan_details['status'] == 'disbursed' ? 'info' : 'secondary'))); 
                        ?>">
                            <i class="bi bi-<?php 
                                echo $loan_details['status'] == 'approved' ? 'check-circle' : 
                                     ($loan_details['status'] == 'pending' ? 'clock' : 
                                     ($loan_details['status'] == 'rejected' ? 'x-circle' : 
                                     ($loan_details['status'] == 'disbursed' ? 'cash-coin' : 'info-circle'))); 
                            ?> me-2"></i>
                            <?php echo ucfirst($loan_details['status']); ?>
                        </span>
                        <?php endif; ?>
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

            <?php if ($loan_details && !$error): ?>
            <div class="row">
                <!-- Left Column - Edit Loan Form -->
                <div class="col-lg-8">
                    <!-- Edit Loan Details -->
                    <div class="info-card">
                        <div class="card-header-custom">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-pencil-square me-2"></i>Edit Loan Details
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="editLoanForm">
                                <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Loan Number</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($loan_details['loan_number']); ?>" readonly style="background: #f8f9fa;">
                                            <small class="text-muted">Loan number cannot be changed</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Customer</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($loan_details['customer_name']); ?>" readonly style="background: #f8f9fa;">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Current Status</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst($loan_details['status']); ?>" readonly style="background: #f8f9fa;">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Applied Date</label>
                                            <input type="text" class="form-control" value="<?php echo date('F j, Y', strtotime($loan_details['applied_date'])); ?>" readonly style="background: #f8f9fa;">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Select Loan Amount (Rs.) *</label>
                                    <div class="row g-2" id="loanAmounts">
                                        <?php 
                                        $loan_table = [
                                            15000 => ['interest_rate' => 36.80, 'weeks' => 19, 'document_fee' => 450],
                                            20000 => ['interest_rate' => 35.30, 'weeks' => 22, 'document_fee' => 600],
                                            25000 => ['interest_rate' => 35.70, 'weeks' => 23, 'document_fee' => 750],
                                            30000 => ['interest_rate' => 35.20, 'weeks' => 24, 'document_fee' => 900],
                                            35000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1050],
                                            40000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1200],
                                            45000 => ['interest_rate' => 35.00, 'weeks' => 25, 'document_fee' => 1350],
                                            50000 => ['interest_rate' => 35.20, 'weeks' => 26, 'document_fee' => 1500],
                                            55000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1650],
                                            60000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1800],
                                            65000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 1950],
                                            70000 => ['interest_rate' => 35.00, 'weeks' => 27, 'document_fee' => 2100],
                                            75000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2250],
                                            80000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2400],
                                            85000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2550],
                                            90000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2700],
                                            95000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 2850],
                                            100000 => ['interest_rate' => 35.00, 'weeks' => 30, 'document_fee' => 3000]
                                        ];
                                        $amounts = array_keys($loan_table);
                                        foreach ($amounts as $amount): 
                                        ?>
                                        <div class="col-6 col-md-4">
                                            <div class="loan-amount-btn <?php echo $loan_details['amount'] == $amount ? 'selected' : ''; ?>" data-amount="<?php echo $amount; ?>">
                                                <div class="fw-bold text-primary">Rs. <?php echo number_format($amount); ?></div>
                                                <small class="text-muted">
                                                    <?php echo $loan_table[$amount]['weeks']; ?> weeks
                                                </small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="loan_amount" id="selected_loan_amount" value="<?php echo $loan_details['amount']; ?>" required>
                                </div>

                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Number of Weeks</label>
                                        <input type="number" class="form-control" name="number_of_weeks" 
                                               value="<?php echo $loan_details['number_of_weeks']; ?>" 
                                               min="4" max="52" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Document Charge (Rs.)</label>
                                        <input type="number" class="form-control" name="document_charge" 
                                               value="<?php echo $loan_details['document_charge']; ?>" 
                                               step="0.01" min="0" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="3" 
                                              placeholder="Update remarks for this loan..."><?php echo htmlspecialchars($loan_details['remarks']); ?></textarea>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" name="update_loan" class="btn-update">
                                        <i class="bi bi-check-circle me-2"></i>Update Loan Details
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Disbursement Date Update -->
                    <?php if (in_array($loan_details['status'], ['disbursed', 'approved'])): ?>
                    <div class="info-card">
                        <div class="card-header-custom card-header-info">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-calendar-date me-2"></i>Update Disbursement Date
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="disbursementDateForm">
                                <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Current Disbursement Date</label>
                                            <input type="text" class="form-control" 
                                                   value="<?php echo $loan_details['disbursed_date'] ? date('F j, Y', strtotime($loan_details['disbursed_date'])) : 'Not disbursed yet'; ?>" 
                                                   readonly style="background: #f8f9fa;">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">New Disbursement Date</label>
                                            <input type="date" class="form-control" name="disbursed_date" 
                                                   value="<?php echo $loan_details['disbursed_date'] ? $loan_details['disbursed_date'] : date('Y-m-d'); ?>" 
                                                   max="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <strong>Important:</strong> Changing disbursement date will recalculate all installment due dates automatically.
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="update_disbursement_date" class="btn-date">
                                        <i class="bi bi-calendar-check me-2"></i>Update Disbursement Date
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Status Change -->
                    <div class="info-card">
                        <div class="card-header-custom card-header-warning">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-arrow-repeat me-2"></i>Change Loan Status
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="statusForm">
                                <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">New Status</label>
                                            <select class="form-select" name="new_status" required>
                                                <option value="">Select Status</option>
                                                <option value="pending" <?php echo $loan_details['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="approved" <?php echo $loan_details['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="rejected" <?php echo $loan_details['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                <option value="disbursed" <?php echo $loan_details['status'] == 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Reason for Change</label>
                                            <textarea class="form-control" name="status_reason" rows="2" 
                                                      placeholder="Explain why you're changing the status..."
                                                      required></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="change_status" class="btn btn-warning text-white">
                                        <i class="bi bi-arrow-repeat me-2"></i>Change Status
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Preview & Current Details -->
                <div class="col-lg-4">
                    <!-- Current Loan Details -->
                    <div class="info-card">
                        <div class="card-header-custom card-header-success">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Current Loan Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <small class="text-muted">Loan Amount</small>
                                <div class="fw-bold fs-5 text-success">Rs. <?php echo number_format($loan_details['amount'], 2); ?></div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Weekly Installment</small>
                                <div class="fw-bold text-primary">Rs. <?php echo number_format($loan_details['weekly_installment'], 2); ?></div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Total Payable</small>
                                <div class="fw-bold">Rs. <?php echo number_format($loan_details['total_loan_amount'], 2); ?></div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Interest Rate</small>
                                <div class="fw-bold"><?php echo $loan_details['interest_rate']; ?>%</div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Document Charge</small>
                                <div class="fw-bold">Rs. <?php echo number_format($loan_details['document_charge'], 2); ?></div>
                            </div>
                            <?php if ($loan_details['disbursed_date']): ?>
                            <div class="mb-3">
                                <small class="text-muted">Disbursement Date</small>
                                <div class="fw-bold text-info"><?php echo date('F j, Y', strtotime($loan_details['disbursed_date'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="info-card">
                        <div class="card-header-custom">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-lightning me-2"></i>Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="view.php?loan_id=<?php echo $loan_details['id']; ?>" class="btn btn-outline-primary">
                                    <i class="bi bi-eye me-2"></i>View Loan Details
                                </a>
                                <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Loans
                                </a>
                                <?php if ($loan_details['status'] === 'disbursed'): ?>
                                <a href="#" class="btn btn-outline-info">
                                    <i class="bi bi-cash-coin me-2"></i>View Payments
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Important Notes -->
                    <div class="info-card">
                        <div class="card-header-custom card-header-warning">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>Important Notes
                            </h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    Updating loan amount will recalculate all installments
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    Changing disbursement date affects all due dates
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    Status changes are logged for audit purposes
                                </li>
                                <li class="mb-0">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    Document charge is fixed based on loan amount
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
            });
        });

        // Auto-select if there's a previous selection
        document.addEventListener('DOMContentLoaded', function() {
            const selectedAmount = document.getElementById('selected_loan_amount').value;
            if (selectedAmount) {
                document.querySelectorAll('.loan-amount-btn').forEach(btn => {
                    if (btn.getAttribute('data-amount') === selectedAmount) {
                        btn.classList.add('selected');
                    }
                });
            }
        });

        // Form validation
        document.getElementById('editLoanForm').addEventListener('submit', function(e) {
            const loanAmount = document.getElementById('selected_loan_amount').value;
            if (!loanAmount) {
                e.preventDefault();
                alert('Please select a loan amount from the available options.');
                return false;
            }
        });

        document.getElementById('statusForm').addEventListener('submit', function(e) {
            const statusReason = this.querySelector('textarea[name="status_reason"]');
            if (!statusReason.value.trim()) {
                e.preventDefault();
                alert('Please provide a reason for changing the status.');
                statusReason.focus();
                return false;
            }
        });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>