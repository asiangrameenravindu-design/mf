<?php
// modules/loans/skip_due_date.php - COMPLETE FIXED VERSION
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "Please login to access this page";
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user has permission (admin or manager)
$allowed_user_types = ['admin',];
if (!in_array($_SESSION['user_type'], $allowed_user_types)) {
    $_SESSION['error_message'] = "You don't have permission to access this page";
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Skip due date functions
function skipDueDateForAllCenters($skip_date, $skip_reason, $skipped_by_user_id) {
    global $conn;
    
    $conn->begin_transaction();
    
    try {
        // Get all installments with due date matching the skip date
        $installments_sql = "SELECT li.*, l.cbo_id, c.meeting_day, c.name as center_name,
                           l.loan_number, cu.full_name as customer_name
                           FROM loan_installments li 
                           JOIN loans l ON li.loan_id = l.id 
                           JOIN cbo c ON l.cbo_id = c.id 
                           JOIN customers cu ON l.customer_id = cu.id
                           WHERE li.due_date = ? 
                           AND li.is_skipped = FALSE
                           AND li.paid_amount = 0
                           AND l.status IN ('active', 'disbursed', 'approved')";
        
        $installments_stmt = $conn->prepare($installments_sql);
        $installments_stmt->bind_param("s", $skip_date);
        $installments_stmt->execute();
        $installments_result = $installments_stmt->get_result();
        
        $skipped_count = 0;
        $skipped_details = [];
        
        while ($installment = $installments_result->fetch_assoc()) {
            // Store original due date if not already stored
            if (empty($installment['original_due_date'])) {
                $update_original_sql = "UPDATE loan_installments SET original_due_date = ? WHERE id = ?";
                $update_original_stmt = $conn->prepare($update_original_sql);
                $update_original_stmt->bind_param("si", $installment['due_date'], $installment['id']);
                $update_original_stmt->execute();
            }
            
            // Calculate new due date (add 7 days)
            $current_due_date = new DateTime($installment['due_date']);
            $new_due_date = $current_due_date->modify('+7 days')->format('Y-m-d');
            
            // Update installment with skip details
            $update_sql = "UPDATE loan_installments 
                          SET is_skipped = TRUE, 
                              skip_reason = ?, 
                              skipped_by = ?, 
                              skipped_at = NOW(),
                              due_date = ?,
                              rescheduled_date = ?
                          WHERE id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sissi", 
                $skip_reason,
                $skipped_by_user_id,
                $new_due_date,
                $new_due_date,
                $installment['id']
            );
            
            if ($update_stmt->execute()) {
                $skipped_count++;
                
                // Store details for reporting
                $skipped_details[] = [
                    'center_name' => $installment['center_name'],
                    'loan_number' => $installment['loan_number'],
                    'customer_name' => $installment['customer_name'],
                    'installment_number' => $installment['installment_number'],
                    'amount' => $installment['amount'],
                    'original_date' => $installment['due_date'],
                    'new_date' => $new_due_date
                ];
                
                // Update all subsequent installments for this loan
                updateSubsequentInstallments($installment['loan_id'], $installment['installment_number']);
            }
        }
        
        $conn->commit();
        return [
            'count' => $skipped_count,
            'details' => $skipped_details
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Global Skip Error: " . $e->getMessage());
        return false;
    }
}

function updateSubsequentInstallments($loan_id, $current_installment_number) {
    global $conn;
    
    $subsequent_sql = "SELECT id, due_date FROM loan_installments 
                      WHERE loan_id = ? AND installment_number > ? 
                      ORDER BY installment_number";
    $subsequent_stmt = $conn->prepare($subsequent_sql);
    $subsequent_stmt->bind_param("ii", $loan_id, $current_installment_number);
    $subsequent_stmt->execute();
    $subsequent_result = $subsequent_stmt->get_result();
    
    while ($subsequent = $subsequent_result->fetch_assoc()) {
        $new_due_date = (new DateTime($subsequent['due_date']))->modify('+7 days')->format('Y-m-d');
        
        $update_sql = "UPDATE loan_installments SET due_date = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $new_due_date, $subsequent['id']);
        $update_stmt->execute();
    }
}

// Function to get centers with due dates on specific date
function getCentersWithDueDates($target_date) {
    global $conn;
    
    $centers_sql = "SELECT DISTINCT c.id, c.name, c.meeting_day,
                   COUNT(li.id) as installment_count,
                   COUNT(DISTINCT l.id) as loan_count
                   FROM cbo c
                   JOIN loans l ON c.id = l.cbo_id
                   JOIN loan_installments li ON l.id = li.loan_id
                   WHERE li.due_date = ?
                   AND li.is_skipped = FALSE
                   AND li.paid_amount = 0
                   AND l.status IN ('active', 'disbursed', 'approved')
                   GROUP BY c.id, c.name, c.meeting_day
                   ORDER BY c.name";
    
    $centers_stmt = $conn->prepare($centers_sql);
    $centers_stmt->bind_param("s", $target_date);
    $centers_stmt->execute();
    $centers_result = $centers_stmt->get_result();
    
    $centers = [];
    while ($center = $centers_result->fetch_assoc()) {
        $centers[] = $center;
    }
    
    return $centers;
}

// Function to get installment details for a specific date and center
function getInstallmentsForDate($target_date, $cbo_id = null) {
    global $conn;
    
    if ($cbo_id) {
        $installments_sql = "SELECT li.*, l.loan_number, l.cbo_id, 
                           c.name as center_name, cu.full_name as customer_name
                           FROM loan_installments li
                           JOIN loans l ON li.loan_id = l.id
                           JOIN cbo c ON l.cbo_id = c.id
                           JOIN customers cu ON l.customer_id = cu.id
                           WHERE li.due_date = ?
                           AND l.cbo_id = ?
                           AND li.is_skipped = FALSE
                           AND li.paid_amount = 0
                           AND l.status IN ('active', 'disbursed', 'approved')
                           ORDER BY c.name, l.loan_number, li.installment_number";
        
        $installments_stmt = $conn->prepare($installments_sql);
        $installments_stmt->bind_param("si", $target_date, $cbo_id);
    } else {
        $installments_sql = "SELECT li.*, l.loan_number, l.cbo_id, 
                           c.name as center_name, cu.full_name as customer_name
                           FROM loan_installments li
                           JOIN loans l ON li.loan_id = l.id
                           JOIN cbo c ON l.cbo_id = c.id
                           JOIN customers cu ON l.customer_id = cu.id
                           WHERE li.due_date = ?
                           AND li.is_skipped = FALSE
                           AND li.paid_amount = 0
                           AND l.status IN ('active', 'disbursed', 'approved')
                           ORDER BY c.name, l.loan_number, li.installment_number";
        
        $installments_stmt = $conn->prepare($installments_sql);
        $installments_stmt->bind_param("s", $target_date);
    }
    
    $installments_stmt->execute();
    $installments_result = $installments_stmt->get_result();
    
    $installments = [];
    while ($installment = $installments_result->fetch_assoc()) {
        $installments[] = $installment;
    }
    
    return $installments;
}

// Initialize variables
$selected_date = $_POST['skip_date'] ?? $_GET['date'] ?? date('Y-m-d');
$centers = [];
$installments = [];
$show_centers = false;
$show_confirmation = false;

// Debug: Check what step we're on
error_log("Initial - Selected Date: $selected_date, Show Centers: " . ($show_centers ? 'true' : 'false') . ", Show Confirmation: " . ($show_confirmation ? 'true' : 'false'));

// Process date selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['select_date'])) {
    error_log("Processing select_date form");
    $selected_date = $_POST['skip_date'];
    $centers = getCentersWithDueDates($selected_date);
    $show_centers = true;
    error_log("After select_date - Centers found: " . count($centers) . ", Show Centers: true");
}

// Process center selection and show confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['select_centers'])) {
    error_log("Processing select_centers form");
    $selected_date = $_POST['skip_date'];
    $selected_centers = $_POST['centers'] ?? [];
    $skip_reason = $_POST['skip_reason'];
    $custom_reason = $_POST['custom_reason'] ?? '';
    
    $final_reason = $skip_reason;
    if ($skip_reason == 'other' && !empty($custom_reason)) {
        $final_reason = $custom_reason;
    }
    
    // Get installments for selected centers
    if (empty($selected_centers)) {
        $installments = getInstallmentsForDate($selected_date);
    } else {
        $installments = [];
        foreach ($selected_centers as $cbo_id) {
            $center_installments = getInstallmentsForDate($selected_date, $cbo_id);
            $installments = array_merge($installments, $center_installments);
        }
    }
    
    $show_confirmation = true;
    error_log("After select_centers - Installments found: " . count($installments) . ", Show Confirmation: true");
}

// Process final skip confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_skip'])) {
    error_log("Processing confirm_skip form");
    $selected_date = $_POST['skip_date'];
    $skip_reason = $_POST['skip_reason'];
    $custom_reason = $_POST['custom_reason'] ?? '';
    $skipped_by = $_SESSION['user_id'];
    
    $final_reason = $skip_reason;
    if ($skip_reason == 'other' && !empty($custom_reason)) {
        $final_reason = $custom_reason;
    }
    
    // Skip all due dates for the selected date
    $result = skipDueDateForAllCenters($selected_date, $final_reason, $skipped_by);
    
    if ($result !== false) {
        $_SESSION['success_message'] = "Successfully skipped {$result['count']} due dates for {$selected_date}. All installments moved forward by 7 days.";
        $_SESSION['skip_details'] = $result['details'];
        
        // Redirect based on user type
        if ($_SESSION['user_type'] == 'manager') {
            header('Location: ' . BASE_URL . '/manager_dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/modules/loans/');
        }
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to skip due dates. Please try again.";
        header('Location: ' . BASE_URL . '/modules/loans/skip_due_date.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skip Due Dates - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --warning: #f72585;
            --success: #4cc9f0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        .main-content {
            margin-left: 0;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .main-content {
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
            background: linear-gradient(135deg, var(--warning), #fd7e14);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
            border: none;
        }
        
        .btn-warning-custom {
            background: linear-gradient(135deg, var(--warning), #fd7e14);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-warning-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(247, 37, 133, 0.4);
            color: white;
        }
        
        .center-card {
            border-left: 4px solid var(--warning);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .center-card:hover {
            transform: translateX(5px);
            border-left-color: var(--primary);
        }
        
        .center-card.selected {
            background-color: #fff3cd;
            border-left-color: var(--success);
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            text-decoration: none;
            color: var(--primary);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: 1;
        }
        
        .step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
        }
        
        .step.active .step-number {
            background: var(--warning);
            color: white;
        }
        
        .step.completed .step-number {
            background: var(--success);
            color: white;
        }
        
        .step-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .step.active .step-label {
            color: var(--warning);
            font-weight: 600;
        }
        
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Simple Header for Skip Due Dates Page -->
    <nav class="navbar navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-skip-forward text-warning"></i>
                Skip Due Dates
            </a>
            <div class="d-flex">
                <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i>Back to Loans
                </a>
                <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-outline-primary">
                    <i class="bi bi-house me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Debug Information -->
            <div class="debug-info">
                <strong>Debug Info:</strong> 
                User Type: <?php echo $_SESSION['user_type']; ?> | 
                User ID: <?php echo $_SESSION['user_id']; ?> |
                Page: skip_due_date.php |
                Current Step: 
                <?php 
                if ($show_confirmation) echo '3 - Confirmation';
                elseif ($show_centers) echo '2 - Review Centers'; 
                else echo '1 - Select Date';
                ?>
            </div>

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/">Loans</a></li>
                    <li class="breadcrumb-item active">Skip Due Dates</li>
                </ol>
            </nav>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 fw-bold text-dark">
                        <i class="bi bi-skip-forward text-warning me-2"></i>Skip Due Dates
                    </h1>
                    <p class="text-muted mb-0">Skip due dates for all centers on a specific date</p>
                </div>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo !$show_centers && !$show_confirmation ? 'active' : 'completed'; ?>">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Date</div>
                </div>
                <div class="step <?php echo $show_centers && !$show_confirmation ? 'active' : ($show_confirmation ? 'completed' : ''); ?>">
                    <div class="step-number">2</div>
                    <div class="step-label">Review Centers</div>
                </div>
                <div class="step <?php echo $show_confirmation ? 'active' : ''; ?>">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Skip</div>
                </div>
            </div>

            <!-- Step 1: Date Selection -->
            <?php if (!$show_centers && !$show_confirmation): ?>
            <div class="info-card">
                <div class="card-header-custom">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calendar-event me-2"></i>Step 1: Select Date to Skip
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="dateForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="skip_date" class="form-label fw-bold">Select Date to Skip *</label>
                                    <input type="date" class="form-control" id="skip_date" name="skip_date" 
                                           value="<?php echo htmlspecialchars($selected_date); ?>" required>
                                    <div class="form-text">Select the date for which you want to skip all due dates</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Quick Selection</label>
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-outline-primary" onclick="setDate('<?php echo date('Y-m-d'); ?>')">
                                            Today (<?php echo date('Y-m-d'); ?>)
                                        </button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setDate('<?php echo date('Y-m-d', strtotime('next monday')); ?>')">
                                            Next Monday
                                        </button>
                                        <button type="button" class="btn btn-outline-primary" onclick="setDate('<?php echo date('Y-m-d', strtotime('next tuesday')); ?>')">
                                            Next Tuesday
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> This will skip all due dates on the selected date across all centers. 
                            All skipped installments will be moved forward by 7 days, and subsequent installments will be automatically adjusted.
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" name="select_date" class="btn btn-warning-custom">
                                <i class="bi bi-arrow-right me-2"></i>Next: Review Centers
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Step 2: Centers Review -->
            <?php if ($show_centers && !$show_confirmation): ?>
            <div class="info-card">
                <div class="card-header-custom">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-building me-2"></i>Step 2: Centers with Due Dates on <?php echo $selected_date; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($centers)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">No Due Dates Found</h4>
                            <p class="text-muted">There are no due dates scheduled for <?php echo $selected_date; ?></p>
                            <a href="<?php echo BASE_URL; ?>/modules/loans/skip_due_date.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left me-2"></i>Select Different Date
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="centersForm">
                            <input type="hidden" name="skip_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                            
                            <div class="summary-card">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <h3 class="fw-bold"><?php echo count($centers); ?></h3>
                                        <p class="mb-0">Centers</p>
                                    </div>
                                    <div class="col-md-4">
                                        <h3 class="fw-bold">
                                            <?php
                                            $total_loans = 0;
                                            $total_installments = 0;
                                            foreach ($centers as $center) {
                                                $total_loans += $center['loan_count'];
                                                $total_installments += $center['installment_count'];
                                            }
                                            echo $total_loans;
                                            ?>
                                        </h3>
                                        <p class="mb-0">Active Loans</p>
                                    </div>
                                    <div class="col-md-4">
                                        <h3 class="fw-bold"><?php echo $total_installments; ?></h3>
                                        <p class="mb-0">Installments to Skip</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">Select Centers to Skip (or leave empty for all centers)</label>
                                <div class="row">
                                    <?php foreach ($centers as $center): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="center-card card" onclick="toggleCenter(this, <?php echo $center['id']; ?>)">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="centers[]" value="<?php echo $center['id']; ?>" 
                                                           id="center_<?php echo $center['id']; ?>">
                                                    <label class="form-check-label fw-bold" for="center_<?php echo $center['id']; ?>">
                                                        <?php echo htmlspecialchars($center['name']); ?>
                                                    </label>
                                                </div>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="bi bi-calendar me-1"></i>Meeting Day: <?php echo ucfirst($center['meeting_day']); ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bi bi-cash-coin me-1"></i><?php echo $center['loan_count']; ?> loans, 
                                                        <?php echo $center['installment_count']; ?> installments
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="skip_reason" class="form-label fw-bold">Reason for Skipping *</label>
                                <select class="form-control" id="skip_reason" name="skip_reason" required>
                                    <option value="">-- Select Reason --</option>
                                    <option value="public_holiday">Public Holiday</option>
                                    <option value="poya_day">Poya Day</option>
                                    <option value="special_holiday">Special Holiday</option>
                                    <option value="weather_conditions">Bad Weather Conditions</option>
                                    <option value="center_closure">Center Closure</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label for="custom_reason" class="form-label">Custom Reason (if Other)</label>
                                <textarea class="form-control" id="custom_reason" name="custom_reason" rows="2" 
                                          placeholder="Enter custom reason..."></textarea>
                            </div>

                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> This action will skip all selected due dates and move them forward by 7 days. 
                                All subsequent installments will be automatically adjusted. This action cannot be easily undone.
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="<?php echo BASE_URL; ?>/modules/loans/skip_due_date.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Date Selection
                                </a>
                                <button type="submit" name="select_centers" class="btn btn-warning-custom">
                                    <i class="bi bi-arrow-right me-2"></i>Next: Confirm Skip
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Step 3: Confirmation -->
            <?php if ($show_confirmation): ?>
            <div class="info-card">
                <div class="card-header-custom">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-check-circle me-2"></i>Step 3: Confirm Skip Due Dates
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="confirmForm">
                        <input type="hidden" name="skip_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                        <input type="hidden" name="skip_reason" value="<?php echo htmlspecialchars($skip_reason); ?>">
                        <input type="hidden" name="custom_reason" value="<?php echo htmlspecialchars($custom_reason); ?>">
                        
                        <div class="summary-card">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h4 class="fw-bold"><?php echo $selected_date; ?></h4>
                                    <p class="mb-0">Date to Skip</p>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="fw-bold"><?php echo count($installments); ?></h4>
                                    <p class="mb-0">Total Installments</p>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="fw-bold">
                                        <?php
                                        $unique_centers = array_unique(array_column($installments, 'center_name'));
                                        echo count($unique_centers);
                                        ?>
                                    </h4>
                                    <p class="mb-0">Centers Affected</p>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="fw-bold">
                                        <?php
                                        $unique_loans = array_unique(array_column($installments, 'loan_number'));
                                        echo count($unique_loans);
                                        ?>
                                    </h4>
                                    <p class="mb-0">Loans Affected</p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold">Skip Details:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Date:</strong> <?php echo $selected_date; ?></p>
                                    <p><strong>Reason:</strong> 
                                        <?php 
                                        echo $skip_reason == 'other' && !empty($custom_reason) ? 
                                             htmlspecialchars($custom_reason) : 
                                             ucfirst(str_replace('_', ' ', $skip_reason)); 
                                        ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>New Due Date:</strong> 
                                        <?php 
                                        $new_date = (new DateTime($selected_date))->modify('+7 days')->format('Y-m-d');
                                        echo $new_date; 
                                        ?>
                                    </p>
                                    <p><strong>Action:</strong> All installments moved forward by 7 days</p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold">Installments to be Skipped:</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Center</th>
                                            <th>Loan No</th>
                                            <th>Customer</th>
                                            <th>Week #</th>
                                            <th>Amount</th>
                                            <th>New Due Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($installments as $installment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($installment['center_name']); ?></td>
                                            <td><?php echo htmlspecialchars($installment['loan_number']); ?></td>
                                            <td><?php echo htmlspecialchars($installment['customer_name']); ?></td>
                                            <td>Week <?php echo $installment['installment_number']; ?></td>
                                            <td>Rs. <?php echo number_format($installment['amount'], 2); ?></td>
                                            <td class="text-success fw-bold">
                                                <?php echo (new DateTime($installment['due_date']))->modify('+7 days')->format('Y-m-d'); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Final Confirmation:</strong> This action will affect <?php echo count($installments); ?> installments 
                            across <?php echo count($unique_centers); ?> centers. All due dates will be moved forward by 7 days. 
                            This action cannot be undone easily.
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?php echo BASE_URL; ?>/modules/loans/skip_due_date.php?date=<?php echo $selected_date; ?>" 
                               class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Center Selection
                            </a>
                            <button type="submit" name="confirm_skip" class="btn btn-danger btn-lg"
                                    onclick="return confirm('Are you absolutely sure you want to skip <?php echo count($installments); ?> due dates? This action cannot be undone.')">
                                <i class="bi bi-skip-forward me-2"></i>Confirm Skip All Due Dates
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set date for quick selection
        function setDate(date) {
            document.getElementById('skip_date').value = date;
        }

        // Toggle center selection
        function toggleCenter(card, centerId) {
            const checkbox = card.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        }

        // Auto-select all centers when clicking on card
        document.addEventListener('DOMContentLoaded', function() {
            const centerCards = document.querySelectorAll('.center-card');
            centerCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.type !== 'checkbox') {
                        const checkbox = this.querySelector('input[type="checkbox"]');
                        checkbox.checked = !checkbox.checked;
                        
                        if (checkbox.checked) {
                            this.classList.add('selected');
                        } else {
                            this.classList.remove('selected');
                        }
                    }
                });
            });

            // Debug: Log form submission
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    console.log('Form submitted:', this.getAttribute('id'));
                });
            });
        });
    </script>
</body>
</html>