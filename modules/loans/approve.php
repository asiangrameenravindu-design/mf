<?php
// modules/loans/approve.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user has permission (manager or admin)
$allowed_roles = ['manager', 'admin'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

$error = '';
$success = '';
$loan_details = null;
$customer_loans = [];
$search_term = '';

// Handle search
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = sanitizeInput($_GET['search']);
}

// Get all pending loans with search filter - UPDATED TO INCLUDE CBO STAFF INFO
$pending_loans_sql = "SELECT l.*, 
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
                      WHERE l.status = 'pending'";

if (!empty($search_term)) {
    $pending_loans_sql .= " AND (l.loan_number LIKE '%$search_term%' 
                               OR c.full_name LIKE '%$search_term%'
                               OR c.national_id LIKE '%$search_term%'
                               OR c.phone LIKE '%$search_term%')";
}

$pending_loans_sql .= " ORDER BY l.applied_date ASC, l.created_at ASC";
$pending_loans = $conn->query($pending_loans_sql);

// If specific loan ID is provided, get its details - BUT ONLY IF IT'S STILL PENDING
if (isset($_GET['loan_id']) && !isset($_POST['approve_loan']) && !isset($_POST['reject_loan'])) {
    $loan_id = intval($_GET['loan_id']);
    $loan_details = getLoanById($loan_id);
    
    if ($loan_details) {
        if ($loan_details['status'] === 'pending') {
            // Get customer's previous loans
            $prev_loans_sql = "SELECT * FROM loans 
                              WHERE customer_id = ? AND id != ? 
                              ORDER BY created_at DESC";
            $prev_loans_stmt = $conn->prepare($prev_loans_sql);
            $prev_loans_stmt->bind_param("ii", $loan_details['customer_id'], $loan_id);
            $prev_loans_stmt->execute();
            $customer_loans_result = $prev_loans_stmt->get_result();
            $customer_loans = [];
            while ($row = $customer_loans_result->fetch_assoc()) {
                $customer_loans[] = $row;
            }
            
            // Get CBO and Field Officer details for this loan
            $cbo_details_sql = "SELECT cb.*, s.full_name as field_officer_name, s.phone as field_officer_phone
                               FROM cbo cb
                               JOIN staff s ON cb.staff_id = s.id
                               WHERE cb.id = ?";
            $cbo_stmt = $conn->prepare($cbo_details_sql);
            $cbo_stmt->bind_param("i", $loan_details['cbo_id']);
            $cbo_stmt->execute();
            $cbo_result = $cbo_stmt->get_result();
            $cbo_details = $cbo_result->fetch_assoc();
        } else {
            // If loan is no longer pending, don't show error, just don't load the details
            $loan_details = null;
            $success = "This loan has already been processed.";
        }
    }
}

// Handle loan approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    $approval_notes = sanitizeInput($_POST['approval_notes']);
    $approved_by = $_SESSION['user_id'];
    
    try {
        $conn->query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->begin_transaction();
        
        $verify_sql = "SELECT status, customer_id, loan_number, amount, total_loan_amount, weekly_installment, number_of_weeks FROM loans WHERE id = ? FOR UPDATE";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("i", $loan_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            throw new Exception("Loan not found.");
        }
        
        $current_loan = $verify_result->fetch_assoc();
        if ($current_loan['status'] !== 'pending') {
            $conn->rollback();
            $_SESSION['success_message'] = "Loan was already processed.";
            header('Location: approve.php');
            exit();
        }
        
        $update_sql = "UPDATE loans SET 
                      status = 'approved',
                      approved_date = CURDATE(),
                      approved_by = ?,
                      approval_notes = ?,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ? AND status = 'pending'";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("isi", $approved_by, $approval_notes, $loan_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $activate_sql = "UPDATE loan_installments 
                            SET status = 'pending',
                            due_date = DATE_ADD(CURDATE(), INTERVAL (installment_number * 7) DAY)
                            WHERE loan_id = ?";
            $activate_stmt = $conn->prepare($activate_sql);
            $activate_stmt->bind_param("i", $loan_id);
            $activate_stmt->execute();
            
            $conn->commit();
            
            // Get customer details for SMS
            $customer_sql = "SELECT c.full_name, c.phone FROM customers c 
                           JOIN loans l ON l.customer_id = c.id 
                           WHERE l.id = ?";
            $customer_stmt = $conn->prepare($customer_sql);
            $customer_stmt->bind_param("i", $loan_id);
            $customer_stmt->execute();
            $customer_result = $customer_stmt->get_result();
            $customer_data = $customer_result->fetch_assoc();
            
            // SMS Integration
            $sms_sent = false;
            $sms_message = '';
            
            if (SMS_ENABLED && !empty($customer_data['phone'])) {
                $message = "Congratulations {$customer_data['full_name']}! Your loan {$current_loan['loan_number']} for Rs. " . 
                          number_format($current_loan['amount'], 2) . " has been approved. " .
                          "Total repayment: Rs. " . number_format($current_loan['total_loan_amount'], 2) . ". " .
                          "Weekly installment: Rs. " . number_format($current_loan['weekly_installment'], 2) . " " .
                          "for {$current_loan['number_of_weeks']} weeks. - Micro Finance";
                
                $sms_result = sendSMS($customer_data['phone'], $message);
                $sms_sent = $sms_result['success'];
                $sms_message = $sms_result['message'];
            }
            
            $success_msg = "Loan approved successfully!";
            if ($sms_sent) {
                $success_msg .= " SMS sent.";
            } else {
                $success_msg .= " SMS failed: " . $sms_message;
            }
            
            $_SESSION['success_message'] = $success_msg;
            header('Location: approve.php');
            exit();
            
        } else {
            throw new Exception("Failed to approve loan.");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle loan rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    $rejection_reason = sanitizeInput($_POST['rejection_reason']);
    $rejected_by = $_SESSION['user_id'];
    
    try {
        $conn->query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->begin_transaction();
        
        $verify_sql = "SELECT status, customer_id, loan_number, amount FROM loans WHERE id = ? FOR UPDATE";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("i", $loan_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            throw new Exception("Loan not found.");
        }
        
        $current_loan = $verify_result->fetch_assoc();
        if ($current_loan['status'] !== 'pending') {
            $conn->rollback();
            $_SESSION['success_message'] = "Loan was already processed.";
            header('Location: approve.php');
            exit();
        }
        
        $update_sql = "UPDATE loans SET 
                      status = 'rejected',
                      rejected_date = CURDATE(),
                      rejected_by = ?,
                      rejection_reason = ?,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ? AND status = 'pending'";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("isi", $rejected_by, $rejection_reason, $loan_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $conn->commit();
            
            // Get customer details for SMS
            $customer_sql = "SELECT c.full_name, c.phone FROM customers c 
                           JOIN loans l ON l.customer_id = c.id 
                           WHERE l.id = ?";
            $customer_stmt = $conn->prepare($customer_sql);
            $customer_stmt->bind_param("i", $loan_id);
            $customer_stmt->execute();
            $customer_result = $customer_stmt->get_result();
            $customer_data = $customer_result->fetch_assoc();
            
            // SMS Integration
            $sms_sent = false;
            $sms_message = '';
            
            if (SMS_ENABLED && !empty($customer_data['phone'])) {
                $message = "Dear {$customer_data['full_name']}, we regret to inform you that your loan application {$current_loan['loan_number']} " .
                          "for Rs. " . number_format($current_loan['amount'], 2) . " has been rejected. " .
                          "Reason: {$rejection_reason}. Please contact us for more details. - Micro Finance";
                
                $sms_result = sendSMS($customer_data['phone'], $message);
                $sms_sent = $sms_result['success'];
                $sms_message = $sms_result['message'];
            }
            
            $success_msg = "Loan rejected successfully!";
            if ($sms_sent) {
                $success_msg .= " SMS sent.";
            } else {
                $success_msg .= " SMS failed: " . $sms_message;
            }
            
            $_SESSION['success_message'] = $success_msg;
            header('Location: approve.php');
            exit();
        } else {
            throw new Exception("Failed to reject loan.");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Function to calculate risk level
function calculateRiskLevel($loans) {
    if (empty($loans)) {
        return ['level' => 'low', 'color' => 'bg-success', 'text' => 'New Customer - Low Risk'];
    }
    
    $total_loans = count($loans);
    $completed_loans = 0;
    $defaulted_loans = 0;
    $active_loans = 0;
    
    foreach ($loans as $loan) {
        if ($loan['status'] === 'completed') {
            $completed_loans++;
        } elseif (in_array($loan['status'], ['defaulted', 'overdue'])) {
            $defaulted_loans++;
        } elseif (in_array($loan['status'], ['active', 'approved', 'disbursed'])) {
            $active_loans++;
        }
    }
    
    if ($defaulted_loans > 0) {
        return ['level' => 'high', 'color' => 'bg-danger', 'text' => 'High Risk - Has Defaulted Loans'];
    } elseif ($active_loans >= 2) {
        return ['level' => 'medium', 'color' => 'bg-warning text-dark', 'text' => 'Medium Risk - Multiple Active Loans'];
    } elseif ($completed_loans > 0) {
        return ['level' => 'low', 'color' => 'bg-success', 'text' => 'Low Risk - Good Payment History'];
    } else {
        return ['level' => 'medium', 'color' => 'bg-info', 'text' => 'Medium Risk - Limited History'];
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Loans - Micro Finance System</title>
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
        
        @media (max-width: 768px) {
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
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table th {
            background-color: #4361ee;
            color: white;
            border: none;
            padding: 12px 15px;
            font-weight: 600;
        }
        
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
        }
        
        .loan-details-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .loan-details-header {
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            color: white;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-approve {
            background: #28a745;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 500;
        }
        
        .btn-reject {
            background: #dc3545;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 500;
        }
        
        .btn-details {
            background: #17a2b8;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 500;
        }
        
        .risk-high {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        
        .risk-medium {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        
        .risk-low {
            background-color: #d1edff;
            border-left: 4px solid #007bff;
        }
        
        .sms-indicator {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 8px;
        }
        
        .sms-enabled {
            background: #d4edda;
            color: #155724;
        }
        
        .sms-disabled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .selected-row {
            background-color: #e3f2fd !important;
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
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/">Loans</a></li>
                                <li class="breadcrumb-item active">Approve Loans</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold">Pending Loan Approvals</h1>
                        <p class="text-muted mb-0">Review and approve pending loan applications</p>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-clock me-1"></i>
                            <?php echo $pending_loans->num_rows; ?> Pending
                        </span>
                        <span class="badge <?php echo SMS_ENABLED ? 'bg-success' : 'bg-secondary'; ?> ms-2">
                            <i class="bi bi-phone me-1"></i>
                            SMS: <?php echo SMS_ENABLED ? 'ON' : 'OFF'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-2">
                        <div class="col-md-8">
                            <input type="text" class="form-control" 
                                   name="search" placeholder="Search by loan number, customer name, NIC, or phone..." 
                                   value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Search
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="approve.php" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-arrow-clockwise me-1"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left Column - Pending Loans Table -->
                <div class="col-lg-<?php echo ($loan_details && $loan_details['status'] === 'pending') ? '7' : '12'; ?>">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Loan Details</th>
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <th>CBO & Field Officer</th>
                                        <th>Amount</th>
                                        <th>Risk</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($pending_loans->num_rows > 0): 
                                        while ($loan = $pending_loans->fetch_assoc()): 
                                            // Calculate risk for each loan
                                            $prev_loans_temp_sql = "SELECT * FROM loans WHERE customer_id = ? AND id != ?";
                                            $prev_loans_temp_stmt = $conn->prepare($prev_loans_temp_sql);
                                            $prev_loans_temp_stmt->bind_param("ii", $loan['customer_id'], $loan['id']);
                                            $prev_loans_temp_stmt->execute();
                                            $temp_loans_result = $prev_loans_temp_stmt->get_result();
                                            $temp_loans = [];
                                            while ($row = $temp_loans_result->fetch_assoc()) {
                                                $temp_loans[] = $row;
                                            }
                                            $risk_info = calculateRiskLevel($temp_loans);
                                    ?>
                                    <tr class="<?php echo $loan_details && $loan_details['id'] == $loan['id'] ? 'selected-row' : ''; ?>">
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($loan['loan_number']); ?></div>
                                            <small class="text-muted">
                                                Applied: <?php echo date('M j, Y', strtotime($loan['applied_date'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">
                                                <a href="<?php echo BASE_URL; ?>/modules/customers/view.php?id=<?php echo $loan['customer_id']; ?>" 
                                                   class="text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($loan['customer_name']); ?>
                                                </a>
                                            </div>
                                            <small class="text-muted">NIC: <?php echo htmlspecialchars($loan['national_id']); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($loan['phone']); ?></div>
                                            <?php if (SMS_ENABLED && !empty($loan['phone'])): ?>
                                                <span class="sms-indicator sms-enabled">
                                                    <i class="bi bi-check-circle me-1"></i>SMS
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <strong>CBO:</strong> <?php echo htmlspecialchars($loan['cbo_name']); ?><br>
                                                <strong>Field Officer:</strong> <?php echo htmlspecialchars($loan['staff_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success">Rs. <?php echo number_format($loan['amount'], 2); ?></div>
                                            <small class="text-muted">
                                                Rs. <?php echo number_format($loan['weekly_installment'], 2); ?>/week
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $risk_info['color']; ?>">
                                                <?php echo $risk_info['level']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="approve.php?loan_id=<?php echo $loan['id']; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" 
                                                   class="btn btn-details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="approve.php?loan_id=<?php echo $loan['id']; ?><?php echo !empty($search_term) ? '&search=' . urlencode($search_term) : ''; ?>" 
                                                   class="btn btn-approve">
                                                    <i class="bi bi-check-lg"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="bi bi-check-circle display-4 text-muted mb-3"></i>
                                            <h5 class="text-muted">No Pending Loans</h5>
                                            <p class="text-muted">All loan applications have been processed.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Loan Details & Actions -->
                <?php if ($loan_details && $loan_details['status'] === 'pending'): 
                    $risk_info = calculateRiskLevel($customer_loans);
                ?>
                <div class="col-lg-5">
                    <!-- Risk Assessment -->
                    <div class="alert alert-<?php echo $risk_info['level'] == 'high' ? 'danger' : ($risk_info['level'] == 'medium' ? 'warning' : 'success'); ?> mb-3">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-<?php echo $risk_info['level'] == 'high' ? 'exclamation-triangle-fill' : ($risk_info['level'] == 'medium' ? 'info-circle-fill' : 'check-circle-fill'); ?> me-2"></i>
                            <div>
                                <strong>Risk Assessment: <?php echo ucfirst($risk_info['level']); ?> Risk</strong><br>
                                <small><?php echo $risk_info['text']; ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- CBO & Field Officer Information -->
                    <?php if (isset($cbo_details)): ?>
                    <div class="loan-details-card mb-3">
                        <div class="loan-details-header">
                            <h5 class="mb-0">
                                <i class="bi bi-geo-alt me-2"></i>CBO & Field Officer Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="cbo-info">
                                <h6 class="fw-bold text-primary mb-2">CBO Information</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">CBO Name</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($cbo_details['name']); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Meeting Day</small>
                                        <div class="fw-bold"><?php echo ucfirst($cbo_details['meeting_day']); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="field-officer-info">
                                <h6 class="fw-bold text-success mb-2">Field Officer Information</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Field Officer</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($cbo_details['field_officer_name']); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Contact</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($cbo_details['field_officer_phone']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Loan Details Card -->
                    <div class="loan-details-card">
                        <div class="loan-details-header">
                            <h5 class="mb-0">
                                <i class="bi bi-file-text me-2"></i>Loan Application Review
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Loan Information -->
                            <h6 class="fw-bold text-primary mb-3">Loan Information</h6>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Loan Number</small>
                                    <div class="fw-bold"><?php echo htmlspecialchars($loan_details['loan_number']); ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Applied Date</small>
                                    <div class="fw-bold"><?php echo date('M j, Y', strtotime($loan_details['applied_date'])); ?></div>
                                </div>
                            </div>

                            <!-- Financial Details -->
                            <h6 class="fw-bold text-primary mb-3">Financial Details</h6>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Loan Amount</small>
                                    <div class="fw-bold text-success fs-5">Rs. <?php echo number_format($loan_details['amount'], 2); ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Total Repayment</small>
                                    <div class="fw-bold text-primary">Rs. <?php echo number_format($loan_details['total_loan_amount'], 2); ?></div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Weekly Installment</small>
                                    <div class="fw-bold">Rs. <?php echo number_format($loan_details['weekly_installment'], 2); ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Number of Weeks</small>
                                    <div class="fw-bold"><?php echo $loan_details['number_of_weeks']; ?> weeks</div>
                                </div>
                            </div>

                            <!-- Customer Information -->
                            <h6 class="fw-bold text-primary mb-3">Customer Information</h6>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Customer ID</small>
                                    <div class="fw-bold">
                                        <a href="<?php echo BASE_URL; ?>/modules/customers/view.php?id=<?php echo $loan_details['customer_id']; ?>" 
                                           class="text-decoration-none text-primary">
                                            <?php echo $loan_details['customer_id']; ?>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Full Name</small>
                                    <div class="fw-bold"><?php echo htmlspecialchars($loan_details['customer_name']); ?></div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">NIC Number</small>
                                    <div class="fw-bold"><?php echo htmlspecialchars($loan_details['national_id']); ?></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Phone</small>
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($loan_details['phone']); ?>
                                        <?php if (SMS_ENABLED && !empty($loan_details['phone'])): ?>
                                            <span class="sms-indicator sms-enabled ms-1">
                                                <i class="bi bi-check-circle"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <small class="text-muted">Address</small>
                                    <div class="fw-bold"><?php echo htmlspecialchars($loan_details['address']); ?></div>
                                </div>
                            </div>

                            <!-- Previous Loans Summary -->
                            <?php if (!empty($customer_loans)): 
                                $total_previous_loans = count($customer_loans);
                                $completed_loans = 0;
                                $defaulted_loans = 0;
                                $active_loans = 0;
                                
                                foreach ($customer_loans as $prev_loan) {
                                    if ($prev_loan['status'] === 'completed') {
                                        $completed_loans++;
                                    } elseif (in_array($prev_loan['status'], ['defaulted', 'overdue'])) {
                                        $defaulted_loans++;
                                    } elseif (in_array($prev_loan['status'], ['active', 'approved', 'disbursed'])) {
                                        $active_loans++;
                                    }
                                }
                            ?>
                            <h6 class="fw-bold text-primary mb-3">Loan History Summary</h6>
                            <div class="row text-center mb-3">
                                <div class="col-3">
                                    <small class="text-muted">Total</small>
                                    <div class="fw-bold"><?php echo $total_previous_loans; ?></div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Completed</small>
                                    <div class="fw-bold text-success"><?php echo $completed_loans; ?></div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Active</small>
                                    <div class="fw-bold text-primary"><?php echo $active_loans; ?></div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Defaulted</small>
                                    <div class="fw-bold text-danger"><?php echo $defaulted_loans; ?></div>
                                </div>
                            </div>
                            <?php if ($defaulted_loans > 0): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> Customer has <?php echo $defaulted_loans; ?> defaulted loan(s)
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <!-- Approval Actions -->
                            <div class="row mt-4">
                                <div class="col-6">
                                    <form method="POST" id="approveForm">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Approval Notes</label>
                                            <textarea class="form-control form-control-sm" name="approval_notes" rows="2" 
                                                      placeholder="Optional notes..."></textarea>
                                        </div>
                                        <button type="button" class="btn-approve w-100" data-bs-toggle="modal" data-bs-target="#approveModal">
                                            <i class="bi bi-check-circle me-1"></i>Approve
                                        </button>
                                    </form>
                                </div>
                                <div class="col-6">
                                    <form method="POST" id="rejectForm">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan_details['id']; ?>">
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Rejection Reason *</label>
                                            <textarea class="form-control form-control-sm" name="rejection_reason" rows="2" 
                                                      placeholder="Required reason..." required></textarea>
                                        </div>
                                        <button type="button" class="btn-reject w-100" data-bs-toggle="modal" data-bs-target="#rejectModal" id="rejectButton" disabled>
                                            <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
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

    <!-- Approve Confirmation Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle me-2"></i>Confirm Loan Approval
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this loan?</p>
                    <?php if (SMS_ENABLED && !empty($loan_details['phone'])): ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-phone me-2"></i>
                            SMS will be sent to <?php echo $loan_details['phone']; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="approveForm" name="approve_loan" class="btn btn-success">
                        <i class="bi bi-check-circle me-2"></i>Approve Loan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Confirmation Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle me-2"></i>Confirm Loan Rejection
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this loan?</p>
                    <?php if (SMS_ENABLED && !empty($loan_details['phone'])): ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-phone me-2"></i>
                            SMS will be sent to <?php echo $loan_details['phone']; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="rejectForm" name="reject_loan" class="btn btn-danger">
                        <i class="bi bi-x-circle me-2"></i>Reject Loan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const rejectForm = document.getElementById('rejectForm');
            if (rejectForm) {
                const rejectTextarea = rejectForm.querySelector('textarea[name="rejection_reason"]');
                const rejectButton = document.getElementById('rejectButton');
                
                rejectTextarea.addEventListener('input', function() {
                    rejectButton.disabled = this.value.trim().length === 0;
                });
            }
        });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>