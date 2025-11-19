<?php
// regenerate_installments.php - COMPLETE PAGE TO REGENERATE INSTALLMENTS WITH MEETING DAY
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
$success = false;
$message = '';
$loan_details = null;
$new_installments = [];

if ($loan_id > 0) {
    // Get loan details
    $loan_details = getLoanById($loan_id);
    
    if ($loan_details && isset($_POST['confirm_regenerate'])) {
        // Regenerate installments
        $conn->begin_transaction();
        
        try {
            // Delete existing installments
            $delete_sql = "DELETE FROM loan_installments WHERE loan_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $loan_id);
            $delete_stmt->execute();
            
            // Get parameters for recreation
            $weekly_installment = $loan_details['weekly_installment'] ?? ($loan_details['total_loan_amount'] / 24);
            $number_of_weeks = 24; // Default to 24 weeks
            $disbursed_date = $loan_details['disbursed_date'] ?? $loan_details['approved_date'] ?? $loan_details['created_at'];
            $cbo_id = $loan_details['cbo_id'];
            
            // Recreate installments with meeting day calculation
            $result = createLoanInstallments($loan_id, $weekly_installment, $number_of_weeks, $disbursed_date, $cbo_id);
            
            if ($result) {
                // Get the newly created installments
                $installments_sql = "SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY installment_number";
                $installments_stmt = $conn->prepare($installments_sql);
                $installments_stmt->bind_param("i", $loan_id);
                $installments_stmt->execute();
                $installments_result = $installments_stmt->get_result();
                
                while ($row = $installments_result->fetch_assoc()) {
                    $new_installments[] = $row;
                }
                
                $conn->commit();
                $success = true;
                $message = "Installments regenerated successfully with meeting day calculation!";
            } else {
                throw new Exception("Failed to create new installments");
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $success = false;
            $message = "Error regenerating installments: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regenerate Installments - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
            color: #333;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
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
            color: white;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-danger-custom {
            background: linear-gradient(135deg, var(--danger), #c82333);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-danger-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
            color: white;
        }
        
        .installment-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
        }
        
        .warning-box {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>

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
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $loan_id; ?>" class="text-decoration-none">Loan Details</a></li>
                                <li class="breadcrumb-item active">Regenerate Installments</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Regenerate Loan Installments</h1>
                        <p class="text-muted mb-0">Recalculate installment due dates based on meeting day</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Loan Details
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                    <i class="bi <?php echo $success ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?> me-2"></i>
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($loan_id > 0 && $loan_details): ?>
                <!-- Loan Information -->
                <div class="info-card">
                    <div class="card-header-custom">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>Loan Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Loan Number:</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($loan_details['loan_number']); ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Customer:</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($loan_details['customer_name']); ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>NIC:</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($loan_details['national_id']); ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>CBO:</strong></div>
                                    <div class="col-sm-8">
                                        <?php 
                                        $cbo_sql = "SELECT name, meeting_day FROM cbo WHERE id = ?";
                                        $cbo_stmt = $conn->prepare($cbo_sql);
                                        $cbo_stmt->bind_param("i", $loan_details['cbo_id']);
                                        $cbo_stmt->execute();
                                        $cbo_result = $cbo_stmt->get_result();
                                        $cbo_data = $cbo_result->fetch_assoc();
                                        echo htmlspecialchars($cbo_data['name'] ?? 'Unknown') . ' (Meeting: ' . ucfirst($cbo_data['meeting_day'] ?? 'tuesday') . ')';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Loan Amount:</strong></div>
                                    <div class="col-sm-8">Rs. <?php echo number_format($loan_details['loan_amount'] ?? $loan_details['total_loan_amount'], 2); ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Weekly Installment:</strong></div>
                                    <div class="col-sm-8">Rs. <?php echo number_format($loan_details['weekly_installment'], 2); ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Disbursed Date:</strong></div>
                                    <div class="col-sm-8"><?php echo $loan_details['disbursed_date'] ?? $loan_details['approved_date'] ?? 'Not set'; ?></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Status:</strong></div>
                                    <div class="col-sm-8">
                                        <span class="badge bg-<?php echo $loan_details['status'] == 'active' ? 'success' : ($loan_details['status'] == 'disbursed' ? 'info' : 'warning'); ?>">
                                            <?php echo ucfirst($loan_details['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$success): ?>
                    <!-- Warning and Confirmation -->
                    <div class="info-card">
                        <div class="card-header-custom" style="background: linear-gradient(135deg, #ffc107, #fd7e14);">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>Important Warning
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="warning-box">
                                <h5 class="text-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Action Required</h5>
                                <p class="mb-3"><strong>This action will:</strong></p>
                                <ul>
                                    <li>Delete all existing installments for this loan</li>
                                    <li>Recalculate due dates based on the CBO's meeting day</li>
                                    <li>Create new installments with proper meeting day calculation</li>
                                </ul>
                                <p class="mb-0 text-danger"><strong>Warning:</strong> This action cannot be undone. Any existing payment records linked to installments may be affected.</p>
                            </div>
                            
                            <form method="POST">
                                <div class="d-flex gap-3 mt-4">
                                    <button type="submit" name="confirm_regenerate" class="btn btn-danger-custom">
                                        <i class="bi bi-arrow-clockwise me-2"></i>Yes, Regenerate Installments
                                    </button>
                                    <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Results -->
                    <div class="info-card">
                        <div class="card-header-custom" style="background: linear-gradient(135deg, #28a745, #20c997);">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-check-circle me-2"></i>Installments Regenerated Successfully
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <strong>Success!</strong> Installments have been recalculated based on the meeting day schedule.
                            </div>
                            
                            <h6 class="mb-3">New Installment Schedule:</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped installment-table">
                                    <thead>
                                        <tr>
                                            <th>Week #</th>
                                            <th>Amount</th>
                                            <th>Due Date</th>
                                            <th>Day of Week</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($new_installments as $installment): ?>
                                        <tr>
                                            <td><?php echo $installment['installment_number']; ?></td>
                                            <td>Rs. <?php echo number_format($installment['amount'], 2); ?></td>
                                            <td><?php echo $installment['due_date']; ?></td>
                                            <td>
                                                <?php 
                                                $due_date = new DateTime($installment['due_date']);
                                                echo $due_date->format('l');
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $installment['status'] == 'pending' ? 'warning' : 'success'; ?>">
                                                    <?php echo ucfirst($installment['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex gap-3 mt-4">
                                <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-primary-custom">
                                    <i class="bi bi-eye me-2"></i>View Loan Details
                                </a>
                                <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-outline-secondary">
                                    <i class="bi bi-list-ul me-2"></i>Back to Loans List
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($loan_id == 0): ?>
                <!-- No Loan ID Provided -->
                <div class="info-card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-exclamation-circle display-1 text-muted"></i>
                        <h3 class="text-muted mt-3">No Loan Specified</h3>
                        <p class="text-muted">Please provide a valid loan ID to regenerate installments.</p>
                        <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-primary-custom mt-3">
                            <i class="bi bi-list-ul me-2"></i>View All Loans
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Loan Not Found -->
                <div class="info-card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-x-circle display-1 text-danger"></i>
                        <h3 class="text-danger mt-3">Loan Not Found</h3>
                        <p class="text-muted">The specified loan ID could not be found in the system.</p>
                        <a href="<?php echo BASE_URL; ?>/modules/loans/" class="btn btn-primary-custom mt-3">
                            <i class="bi bi-list-ul me-2"></i>View All Loans
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>