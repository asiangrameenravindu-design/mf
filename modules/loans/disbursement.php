<?php
// modules/loans/disbursement.php

// Include configuration files
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Rest of your PHP code continues here...
$error = '';
$success = '';
$loan_details = null;
$customer_active_loans = [];
$has_active_loans = false;

// Search and filter parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$cbo_filter = isset($_GET['cbo_filter']) ? intval($_GET['cbo_filter']) : 0;
$staff_filter = isset($_GET['staff_filter']) ? intval($_GET['staff_filter']) : 0;
$amount_filter = isset($_GET['amount_filter']) ? sanitizeInput($_GET['amount_filter']) : '';

// Build WHERE clause for filtering
$where_conditions = ["l.status = 'approved'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.full_name LIKE ? OR c.national_id LIKE ? OR l.loan_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if ($cbo_filter > 0) {
    $where_conditions[] = "l.cbo_id = ?";
    $params[] = $cbo_filter;
    $types .= 'i';
}

if ($staff_filter > 0) {
    $where_conditions[] = "l.staff_id = ?";
    $params[] = $staff_filter;
    $types .= 'i';
}

if (!empty($amount_filter)) {
    switch ($amount_filter) {
        case 'small':
            $where_conditions[] = "l.amount <= 50000";
            break;
        case 'medium':
            $where_conditions[] = "l.amount BETWEEN 50001 AND 150000";
            break;
        case 'large':
            $where_conditions[] = "l.amount > 150000";
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get all approved loans ready for disbursement with filters - UPDATED TO INCLUDE CBO STAFF INFO
$approved_loans_sql = "SELECT l.*, 
                              c.id as customer_id,
                              c.full_name as customer_name, 
                              c.national_id,
                              c.phone,
                              c.address,
                              cb.name as cbo_name,
                              cb.staff_id as cbo_staff_id,
                              s.full_name as staff_name,
                              s.position as staff_position
                       FROM loans l
                       JOIN customers c ON l.customer_id = c.id
                       JOIN cbo cb ON l.cbo_id = cb.id
                       JOIN staff s ON cb.staff_id = s.id
                       WHERE $where_clause
                       ORDER BY l.approved_date ASC, l.created_at ASC";

$approved_loans_stmt = $conn->prepare($approved_loans_sql);
if (!empty($params)) {
    $approved_loans_stmt->bind_param($types, ...$params);
}
$approved_loans_stmt->execute();
$approved_loans = $approved_loans_stmt->get_result();

// Get CBO list for filter
$cbo_sql = "SELECT id, name FROM cbo ORDER BY name";
$cbo_result = $conn->query($cbo_sql);

// Get Staff list for filter - ONLY field_officer
$staff_sql = "SELECT id, full_name FROM staff WHERE position = 'field_officer' ORDER BY full_name";
$staff_result = $conn->query($staff_sql);

// If specific loan ID is provided, get its details
if (isset($_GET['loan_id'])) {
    $loan_id = intval($_GET['loan_id']);
    
    // Custom function to get loan details with CBO and Field Officer info
    $loan_sql = "SELECT l.*, 
                        c.id as customer_id,
                        c.full_name as customer_name, 
                        c.national_id,
                        cb.name as cbo_name,
                        cb.staff_id as cbo_staff_id,
                        cb.meeting_day,
                        s.full_name as staff_name,
                        s.phone as staff_phone,
                        s.position as staff_position
                 FROM loans l
                 JOIN customers c ON l.customer_id = c.id
                 JOIN cbo cb ON l.cbo_id = cb.id
                 JOIN staff s ON cb.staff_id = s.id
                 WHERE l.id = ?";
    $loan_stmt = $conn->prepare($loan_sql);
    $loan_stmt->bind_param("i", $loan_id);
    $loan_stmt->execute();
    $loan_details = $loan_stmt->get_result()->fetch_assoc();
    
    if ($loan_details && $loan_details['status'] === 'approved') {
        // Check if customer has any active loans (only for information display, not for blocking rejection)
        $active_loans_sql = "SELECT COUNT(*) as active_count FROM loans 
                            WHERE customer_id = ? AND status IN ('active', 'disbursed') AND id != ?";
        $active_loans_stmt = $conn->prepare($active_loans_sql);
        $active_loans_stmt->bind_param("ii", $loan_details['customer_id'], $loan_id);
        $active_loans_stmt->execute();
        $active_result = $active_loans_stmt->get_result();
        $active_count = $active_result->fetch_assoc()['active_count'];
        
        $has_active_loans = $active_count > 0;
        
        // Get customer's active loans details
        $active_details_sql = "SELECT loan_number, amount, status FROM loans 
                              WHERE customer_id = ? AND status IN ('active', 'disbursed') AND id != ?";
        $active_details_stmt = $conn->prepare($active_details_sql);
        $active_details_stmt->bind_param("ii", $loan_details['customer_id'], $loan_id);
        $active_details_stmt->execute();
        $customer_active_loans = $active_details_stmt->get_result();
    } else {
        $error = "Loan not found or not approved for disbursement!";
    }
}

// Handle loan disbursement - FIXED VERSION WITH OUTSTANDING BALANCE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disburse_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    $disbursement_notes = sanitizeInput($_POST['disbursement_notes']);
    $disbursed_by = $_SESSION['user_id'];
    
    try {
        $conn->begin_transaction();
        
        // First check if customer has any active loans (for disbursement only)
        $check_sql = "SELECT COUNT(*) as active_count FROM loans 
                     WHERE customer_id = (SELECT customer_id FROM loans WHERE id = ?) 
                     AND status IN ('active', 'disbursed') 
                     AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $loan_id, $loan_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $active_count = $check_result->fetch_assoc()['active_count'];
        
        if ($active_count > 0) {
            throw new Exception("Customer has active loans. Cannot disburse new loan until previous loans are settled.");
        }
        
        // Get loan total amount for outstanding balance
        $loan_amount_sql = "SELECT total_loan_amount, amount, number_of_weeks FROM loans WHERE id = ?";
        $loan_amount_stmt = $conn->prepare($loan_amount_sql);
        $loan_amount_stmt->bind_param("i", $loan_id);
        $loan_amount_stmt->execute();
        $loan_amount_result = $loan_amount_stmt->get_result();
        $loan_data = $loan_amount_result->fetch_assoc();
        
        if (!$loan_data) {
            throw new Exception("Loan not found");
        }
        
        $total_loan_amount = $loan_data['total_loan_amount'];
        $principal_amount = $loan_data['amount'];
        $number_of_weeks = $loan_data['number_of_weeks'];
        
        // Update loan status to disbursed AND SET OUTSTANDING BALANCE - FIXED SQL SYNTAX
        $update_sql = "UPDATE loans SET 
                      status = 'disbursed',
                      disbursed_date = CURDATE(),
                      disbursed_by = ?,
                      disbursement_notes = ?,
                      balance = ?,  -- SET OUTSTANDING BALANCE TO TOTAL LOAN AMOUNT
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ? AND status = 'approved'";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("isdi", $disbursed_by, $disbursement_notes, $total_loan_amount, $loan_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Update installments to start from next week
            $update_installments_sql = "UPDATE loan_installments 
                                       SET status = 'pending',
                                       due_date = DATE_ADD(CURDATE(), INTERVAL (installment_number * 7) DAY)
                                       WHERE loan_id = ?";
            $update_installments_stmt = $conn->prepare($update_installments_sql);
            $update_installments_stmt->bind_param("i", $loan_id);
            $update_installments_stmt->execute();
            
            // UPDATE PRINCIPAL/INTEREST BREAKDOWN - FIXED CALCULATION
            $update_breakdown_sql = "UPDATE loan_installments li
                                    JOIN loans l ON li.loan_id = l.id
                                    SET 
                                        li.principal_amount = ROUND(l.amount / l.number_of_weeks, 2),
                                        li.interest_amount = ROUND((l.total_loan_amount - l.amount) / l.number_of_weeks, 2)
                                    WHERE li.loan_id = ?";
            $update_breakdown_stmt = $conn->prepare($update_breakdown_sql);
            $update_breakdown_stmt->bind_param("i", $loan_id);
            $update_breakdown_stmt->execute();
            
            // Get totals for adjustment
            $totals_sql = "SELECT 
                          SUM(principal_amount) as total_principal,
                          SUM(interest_amount) as total_interest
                          FROM loan_installments 
                          WHERE loan_id = ?";
            $totals_stmt = $conn->prepare($totals_sql);
            $totals_stmt->bind_param("i", $loan_id);
            $totals_stmt->execute();
            $totals_result = $totals_stmt->get_result();
            $totals_data = $totals_result->fetch_assoc();
            
            $total_calculated_principal = $totals_data['total_principal'] ?? 0;
            $total_calculated_interest = $totals_data['total_interest'] ?? 0;
            
            // Calculate differences
            $principal_diff = $principal_amount - $total_calculated_principal;
            $interest_diff = ($total_loan_amount - $principal_amount) - $total_calculated_interest;
            
            // Adjust last installment for rounding differences if needed
            if (abs($principal_diff) > 0.01 || abs($interest_diff) > 0.01) {
                $adjust_last_sql = "UPDATE loan_installments 
                                   SET 
                                       principal_amount = principal_amount + ?,
                                       interest_amount = interest_amount + ?
                                   WHERE loan_id = ? 
                                   AND installment_number = (SELECT MAX(installment_number) FROM loan_installments WHERE loan_id = ?)";
                $adjust_last_stmt = $conn->prepare($adjust_last_sql);
                $adjust_last_stmt->bind_param("ddii", $principal_diff, $interest_diff, $loan_id, $loan_id);
                $adjust_last_stmt->execute();
            }
            
            $conn->commit();
            $success = "Loan #" . $loan_id . " disbursed successfully! Outstanding balance set to Rs. " . number_format($total_loan_amount, 2) . ". Installments will start from next week.";
            
            // Refresh the page to show updated list
            echo "<script>setTimeout(function() { window.location.href = 'disbursement.php'; }, 2000);</script>";
            
        } else {
            throw new Exception("Failed to disburse loan or loan is not in approved status.");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error disbursing loan: " . $e->getMessage();
    }
}

// Handle loan rejection after approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_approved_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    $rejection_reason = sanitizeInput($_POST['rejection_reason']);
    $rejected_by = $_SESSION['user_id'];
    
    try {
        $conn->begin_transaction();
        
        // NO NEED to check for active loans when rejecting - rejection is always allowed
        
        // Update loan status to rejected
        $update_sql = "UPDATE loans SET 
                      status = 'rejected',
                      rejected_date = CURDATE(),
                      rejected_by = ?,
                      rejection_reason = ?,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ? AND status = 'approved'";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("isi", $rejected_by, $rejection_reason, $loan_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $conn->commit();
            $success = "Loan #" . $loan_id . " rejected successfully!";
            
            // Refresh the page to show updated list
            echo "<script>setTimeout(function() { window.location.href = 'disbursement.php'; }, 1500);</script>";
        } else {
            throw new Exception("Failed to reject loan or loan is not in approved status.");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error rejecting loan: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Disbursement - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        .page-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: #4361ee;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
            border: none;
        }
        
        .table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
        }
        
        .btn-disburse {
            background: #28a745;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.875rem;
        }
        
        .btn-disburse:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.875rem;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .btn-details {
            background: #6c757d;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.875rem;
        }
        
        .btn-details:hover {
            background: #5a6268;
        }
        
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .table-responsive {
            border-radius: 10px;
        }
        
        .customer-link {
            color: #4361ee;
            text-decoration: none;
            font-weight: 500;
        }
        
        .customer-link:hover {
            color: #3a56d4;
            text-decoration: underline;
        }
        
        .field-officer-info {
            background: #e8f5e8;
            border-left: 4px solid #28a745;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .cbo-info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .installment-breakdown {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .outstanding-info {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <?php 
    // Simple header and sidebar inclusion
    if (file_exists(__DIR__ . '/../../includes/header.php')) {
        include '../../includes/header.php';
    }
    
    if (file_exists(__DIR__ . '/../../includes/sidebar.php')) {
        include '../../includes/sidebar.php';
    }
    ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/" class="text-decoration-none text-muted">Loans</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold">Loan Disbursement</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Loan Disbursement</h1>
                        <p class="text-muted mb-0">Disburse approved loans to customers</p>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-success fs-6">
                            <i class="bi bi-check-circle me-1"></i>
                            <?php echo $approved_loans->num_rows; ?> Approved Loans
                        </span>
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

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search by name, NIC, or loan number..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">CBO</label>
                        <select class="form-select" name="cbo_filter">
                            <option value="0">All CBOs</option>
                            <?php while ($cbo = $cbo_result->fetch_assoc()): ?>
                                <option value="<?php echo $cbo['id']; ?>" <?php echo $cbo_filter == $cbo['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cbo['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Field Officer</label>
                        <select class="form-select" name="staff_filter">
                            <option value="0">All Officers</option>
                            <?php while ($staff = $staff_result->fetch_assoc()): ?>
                                <option value="<?php echo $staff['id']; ?>" <?php echo $staff_filter == $staff['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($staff['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Loan Amount</label>
                        <select class="form-select" name="amount_filter">
                            <option value="">All Amounts</option>
                            <option value="small" <?php echo $amount_filter == 'small' ? 'selected' : ''; ?>>Small (≤ Rs. 50,000)</option>
                            <option value="medium" <?php echo $amount_filter == 'medium' ? 'selected' : ''; ?>>Medium (Rs. 50,001 - 150,000)</option>
                            <option value="large" <?php echo $amount_filter == 'large' ? 'selected' : ''; ?>>Large (> Rs. 150,000)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-2"></i>Filter
                        </button>
                    </div>
                    <?php if ($search || $cbo_filter || $staff_filter || $amount_filter): ?>
                    <div class="col-12">
                        <div class="d-flex align-items-center">
                            <span class="text-muted small me-3">Active filters:</span>
                            <?php if ($search): ?>
                                <span class="badge bg-primary me-2">Search: <?php echo htmlspecialchars($search); ?></span>
                            <?php endif; ?>
                            <?php if ($cbo_filter): ?>
                                <span class="badge bg-info me-2">CBO Filter</span>
                            <?php endif; ?>
                            <?php if ($staff_filter): ?>
                                <span class="badge bg-success me-2">Field Officer Filter</span>
                            <?php endif; ?>
                            <?php if ($amount_filter): ?>
                                <span class="badge bg-warning me-2">Amount: <?php echo ucfirst($amount_filter); ?></span>
                            <?php endif; ?>
                            <a href="disbursement.php" class="btn btn-sm btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="row">
                <!-- Left Column - Approved Loans List -->
                <div class="col-lg-<?php echo $loan_details ? '7' : '12'; ?>">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-list-ul me-2"></i>Approved Loans Ready for Disbursement
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($approved_loans->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Loan #</th>
                                                <th>Customer</th>
                                                <th>NIC</th>
                                                <th>CBO & Field Officer</th>
                                                <th>Amount</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($loan = $approved_loans->fetch_assoc()): 
                                                // Check if this customer has active loans (for display purposes only)
                                                $has_active_loans_sql = "SELECT COUNT(*) as active_count FROM loans 
                                                                       WHERE customer_id = ? AND status IN ('active', 'disbursed') AND id != ?";
                                                $has_active_stmt = $conn->prepare($has_active_loans_sql);
                                                $has_active_stmt->bind_param("ii", $loan['customer_id'], $loan['id']);
                                                $has_active_stmt->execute();
                                                $has_active_result = $has_active_stmt->get_result();
                                                $has_active = $has_active_result->fetch_assoc()['active_count'] > 0;
                                            ?>
                                            <tr class="<?php echo $loan_details && $loan_details['id'] == $loan['id'] ? 'table-active' : ''; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($loan['loan_number']); ?></strong>
                                                    <?php if ($has_active): ?>
                                                    <br><span class="badge badge-warning">Active Loans</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $loan['customer_id']; ?>&nic=<?php echo urlencode($loan['national_id']); ?>&phone=&name=<?php echo urlencode($loan['customer_name']); ?>" 
                                                       class="customer-link" target="_blank">
                                                        <?php echo htmlspecialchars($loan['customer_name']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo htmlspecialchars($loan['national_id']); ?></td>
                                                <td>
                                                    <div class="small">
                                                        <strong>CBO:</strong> <?php echo htmlspecialchars($loan['cbo_name']); ?><br>
                                                        <strong>Field Officer:</strong> <?php echo htmlspecialchars($loan['staff_name']); ?>
                                                    </div>
                                                </td>
                                                <td class="fw-bold text-success">Rs. <?php echo number_format($loan['amount'], 2); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="disbursement.php?loan_id=<?php echo $loan['id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $cbo_filter ? '&cbo_filter=' . $cbo_filter : ''; ?><?php echo $staff_filter ? '&staff_filter=' . $staff_filter : ''; ?><?php echo $amount_filter ? '&amount_filter=' . $amount_filter : ''; ?>" 
                                                           class="btn btn-details btn-sm">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <?php if (!$loan_details || $loan_details['id'] != $loan['id']): ?>
                                                        <a href="disbursement.php?loan_id=<?php echo $loan['id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $cbo_filter ? '&cbo_filter=' . $cbo_filter : ''; ?><?php echo $staff_filter ? '&staff_filter=' . $staff_filter : ''; ?><?php echo $amount_filter ? '&amount_filter=' . $amount_filter : ''; ?>" 
                                                           class="btn btn-disburse btn-sm <?php echo $has_active ? 'disabled' : ''; ?>">
                                                            <i class="bi bi-cash-coin"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                                    <h4 class="text-muted mt-3">No Approved Loans Found</h4>
                                    <p class="text-muted mb-4">No loans match your current filter criteria.</p>
                                    <?php if ($search || $cbo_filter || $staff_filter || $amount_filter): ?>
                                        <a href="disbursement.php" class="btn btn-primary">
                                            <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo BASE_URL; ?>/modules/loans/approve.php" class="btn btn-primary">
                                            <i class="bi bi-arrow-left me-2"></i>Go to Approvals
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Loan Details & Disbursement Actions -->
                <?php if ($loan_details && !$error): ?>
                <div class="col-lg-5">
                    <!-- CBO & Field Officer Information -->
                    <?php if (isset($loan_details)): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-info">
                            <h5 class="card-title mb-0 text-white">
                                <i class="bi bi-geo-alt me-2"></i>CBO & Field Officer Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="cbo-info">
                                <h6 class="fw-bold text-primary mb-2">CBO Information</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">CBO Name</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($loan_details['cbo_name']); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Meeting Day</small>
                                        <div class="fw-bold"><?php echo isset($loan_details['meeting_day']) ? ucfirst($loan_details['meeting_day']) : 'Not specified'; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="field-officer-info">
                                <h6 class="fw-bold text-success mb-2">Field Officer Information</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Field Officer</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($loan_details['staff_name']); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Contact</small>
                                        <div class="fw-bold"><?php echo isset($loan_details['staff_phone']) ? htmlspecialchars($loan_details['staff_phone']) : 'Not available'; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Loan Disbursement Details -->
                    <div class="card">
                        <div class="card-header bg-success">
                            <h5 class="card-title mb-0 text-white">
                                <i class="bi bi-cash-coin me-2"></i>Loan Disbursement Review
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="fw-bold text-primary border-bottom pb-2">Loan Information</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="40%"><small class="text-muted">Loan Number</small></td>
                                            <td><strong><?php echo htmlspecialchars($loan_details['loan_number']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><small class="text-muted">Customer Name</small></td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $loan_details['customer_id']; ?>&nic=<?php echo urlencode($loan_details['national_id']); ?>&phone=&name=<?php echo urlencode($loan_details['customer_name']); ?>" 
                                                   class="customer-link" target="_blank">
                                                    <strong><?php echo htmlspecialchars($loan_details['customer_name']); ?></strong>
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><small class="text-muted">NIC Number</small></td>
                                            <td><strong><?php echo htmlspecialchars($loan_details['national_id']); ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-12">
                                    <h6 class="fw-bold text-primary border-bottom pb-2">Financial Details</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="40%"><small class="text-muted">Disbursement Amount</small></td>
                                            <td><strong class="text-success">Rs. <?php echo number_format($loan_details['amount'], 2); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><small class="text-muted">Weekly Installment</small></td>
                                            <td><strong class="text-primary">Rs. <?php echo number_format($loan_details['weekly_installment'], 2); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><small class="text-muted">Total Payable</small></td>
                                            <td><strong>Rs. <?php echo number_format($loan_details['total_loan_amount'], 2); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><small class="text-muted">Loan Duration</small></td>
                                            <td><strong><?php echo $loan_details['number_of_weeks']; ?> Weeks</strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- Outstanding Balance Information -->
                            <div class="outstanding-info">
                                <h6 class="fw-bold text-success mb-2">
                                    <i class="bi bi-currency-exchange me-2"></i>Outstanding Balance
                                </h6>
                                <p class="mb-2 small">
                                    Upon disbursement, the outstanding balance will be automatically set to:
                                </p>
                                <div class="text-center">
                                    <h4 class="text-success fw-bold">Rs. <?php echo number_format($loan_details['total_loan_amount'], 2); ?></h4>
                                    <small class="text-muted">(Total Loan Amount)</small>
                                </div>
                                <p class="mt-2 mb-0 small text-muted">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    This balance will automatically reduce as payments are received.
                                </p>
                            </div>

                            <!-- Installment Breakdown Information -->
                            <div class="installment-breakdown">
                                <h6 class="fw-bold text-warning mb-2">
                                    <i class="bi bi-info-circle me-2"></i>Installment Breakdown
                                </h6>
                                <p class="mb-2 small">
                                    Upon disbursement, each installment will be automatically split into:
                                </p>
                                <ul class="small mb-0">
                                    <li><strong>Principal Amount:</strong> Capital repayment portion</li>
                                    <li><strong>Interest Amount:</strong> Interest payment portion</li>
                                </ul>
                                <p class="mt-2 mb-0 small text-muted">
                                    <i class="bi bi-lightbulb me-1"></i>
                                    This breakdown helps track capital recovery and interest income separately.
                                </p>
                            </div>

                            <!-- Active Loans Warning (Information only - doesn't block rejection) -->
                            <?php if ($has_active_loans): ?>
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">
                                    <i class="bi bi-exclamation-triangle me-2"></i>Customer Has Active Loans
                                </h6>
                                <p class="mb-2">This customer has the following active loans:</p>
                                <ul class="mb-0">
                                    <?php while ($active_loan = $customer_active_loans->fetch_assoc()): ?>
                                    <li>
                                        <?php echo htmlspecialchars($active_loan['loan_number']); ?> - 
                                        Rs. <?php echo number_format($active_loan['amount'], 2); ?> 
                                        (<?php echo ucfirst($active_loan['status']); ?>)
                                    </li>
                                    <?php endwhile; ?>
                                </ul>
                                <p class="mt-2 mb-0 fw-semibold">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Cannot disburse new loan until existing loans are settled, but you can still reject this loan.
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Disbursement Actions -->
                            <div class="row mt-4">
                                <div class="col-6">
                                    <!-- Disburse Loan Form -->
                                    <form method="POST" id="disburseForm">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Disbursement Notes</label>
                                            <textarea class="form-control" name="disbursement_notes" rows="2" 
                                                      placeholder="Optional notes for disbursement..."></textarea>
                                        </div>
                                        <button type="button" class="btn btn-success w-100 <?php echo $has_active_loans ? 'disabled' : ''; ?>" 
                                                data-bs-toggle="modal" data-bs-target="#disburseModal" 
                                                <?php echo $has_active_loans ? 'disabled' : ''; ?>>
                                            <i class="bi bi-cash-coin me-2"></i>
                                            <?php echo $has_active_loans ? 'Cannot Disburse' : 'Disburse Loan'; ?>
                                        </button>
                                        <?php if ($has_active_loans): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle me-1"></i>
                                                Customer has active loans. Settle existing loans first.
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                <div class="col-6">
                                    <!-- Reject Loan Form -->
                                    <form method="POST" id="rejectForm">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Rejection Reason</label>
                                            <textarea class="form-control" name="rejection_reason" rows="2" 
                                                      placeholder="Required reason for rejection..." required></textarea>
                                        </div>
                                        <button type="button" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                            <i class="bi bi-x-circle me-2"></i>
                                            Reject Loan
                                        </button>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle me-1"></i>
                                                You can reject this loan even if customer has active loans.
                                            </small>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Disbursement Confirmation Modal -->
    <div class="modal fade" id="disburseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-coin me-2"></i>Confirm Loan Disbursement
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-question-circle text-success fs-1"></i>
                        <h5 class="fw-bold mt-3">Are you sure you want to disburse this loan?</h5>
                        <p class="text-muted">
                            Loan <strong><?php echo $loan_details ? htmlspecialchars($loan_details['loan_number']) : ''; ?></strong> 
                            for <strong><?php echo $loan_details ? htmlspecialchars($loan_details['customer_name']) : ''; ?></strong> 
                            will be disbursed and installments will start from next week.
                        </p>
                        <div class="alert alert-info text-start">
                            <h6 class="fw-bold"><i class="bi bi-lightbulb me-2"></i>What will happen:</h6>
                            <ul class="mb-0 small">
                                <li>Loan status will change to "disbursed"</li>
                                <li>Outstanding balance will be set to <strong>Rs. <?php echo $loan_details ? number_format($loan_details['total_loan_amount'], 2) : '0.00'; ?></strong></li>
                                <li>Weekly installments will be scheduled</li>
                                <li>Each installment will be split into principal and interest components</li>
                                <li>Installments will start from next week</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <button type="submit" form="disburseForm" name="disburse_loan" class="btn btn-success">
                        <i class="bi bi-cash-coin me-2"></i>Yes, Disburse Loan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Confirmation Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle me-2"></i>Confirm Loan Rejection
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-exclamation-triangle text-warning fs-1"></i>
                        <h5 class="fw-bold mt-3">Are you sure you want to reject this loan?</h5>
                        <p class="text-muted">
                            This action cannot be undone. The loan will be rejected and the customer will be notified.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <button type="submit" form="rejectForm" name="reject_approved_loan" class="btn btn-danger">
                        <i class="bi bi-x-circle me-2"></i>Yes, Reject Loan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Form validation for rejection
        document.addEventListener('DOMContentLoaded', function() {
            const rejectForm = document.getElementById('rejectForm');
            const rejectButton = rejectForm.querySelector('button[type="button"]');
            const rejectionReason = rejectForm.querySelector('textarea[name="rejection_reason"]');
            
            rejectButton.addEventListener('click', function() {
                if (rejectionReason.value.trim() === '') {
                    alert('Please provide a reason for rejection.');
                    rejectionReason.focus();
                    return;
                }
                
                const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
                modal.show();
            });
        });
    </script>
</body>
</html>