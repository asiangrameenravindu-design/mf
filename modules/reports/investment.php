<?php
require_once '../../config/config.php';
checkAccess();

// Check permissions
if ($_SESSION['user_type'] !== 'admin' && $_SESSION['user_type'] !== 'staff') {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../dashboard.php");
    exit;
}

// Set page title
$page_title = "Investment Report";

// Process filters
$cbo_id = isset($_GET['cbo_id']) ? intval($_GET['cbo_id']) : null;
$loan_status = isset($_GET['loan_status']) ? $_GET['loan_status'] : null;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$field_officer_id = isset($_GET['field_officer_id']) ? intval($_GET['field_officer_id']) : null;

// Field Officer access control
$field_officer_condition = "";
if ($_SESSION['user_type'] === 'staff') {
    $field_officer_id = $_SESSION['user_id'];
    $field_officer_condition = " AND l.staff_id = " . intval($field_officer_id);
}

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($cbo_id) {
    $where_conditions[] = "l.cbo_id = ?";
    $params[] = $cbo_id;
    $types .= 'i';
}

if ($loan_status) {
    $where_conditions[] = "l.status = ?";
    $params[] = $loan_status;
    $types .= 's';
}

if ($start_date) {
    $where_conditions[] = "l.applied_date >= ?";
    $params[] = $start_date;
    $types .= 's';
}

if ($end_date) {
    $where_conditions[] = "l.applied_date <= ?";
    $params[] = $end_date;
    $types .= 's';
}

if ($field_officer_id && $_SESSION['user_type'] === 'admin') {
    $where_conditions[] = "l.staff_id = ?";
    $params[] = $field_officer_id;
    $types .= 'i';
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) . $field_officer_condition : ($field_officer_condition ? "WHERE 1=1" . $field_officer_condition : "");

// Get summary data
$summary_query = "
    SELECT 
        COUNT(*) as total_loans,
        COALESCE(SUM(l.amount), 0) as total_loan_amount,
        COALESCE(SUM(l.service_charge), 0) as total_service_charge,
        COALESCE(SUM(l.document_charge), 0) as total_document_charge,
        COALESCE(SUM(l.total_loan_amount), 0) as total_investment,
        COALESCE(SUM(l.balance), 0) as outstanding_balance
    FROM loans l
    $where_clause
";

$summary_stmt = $conn->prepare($summary_query);
if ($params) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();

// Get detailed loan data
$loan_query = "
    SELECT 
        cbo.name AS cbo_name,
        cbo.cbo_number,
        l.id AS loan_id,
        l.loan_number,
        l.amount AS loan_amount,
        l.service_charge,
        l.document_charge,
        l.total_loan_amount,
        l.balance AS outstanding_balance,
        l.weekly_installment,
        l.number_of_weeks,
        l.interest_rate,
        l.status AS loan_status,
        l.applied_date,
        l.approved_date,
        l.disbursed_date,
        l.settlement_date,
        cust.full_name AS customer_name,
        cust.phone AS customer_phone,
        staff.full_name AS staff_name,
        staff.short_name AS staff_short_name
    FROM loans l
    LEFT JOIN cbo ON cbo.id = l.cbo_id
    LEFT JOIN customers cust ON cust.id = l.customer_id
    LEFT JOIN staff ON staff.id = l.staff_id
    $where_clause
    ORDER BY l.applied_date DESC, cbo.name
";

$loan_stmt = $conn->prepare($loan_query);
if ($params) {
    $loan_stmt->bind_param($types, ...$params);
}
$loan_stmt->execute();
$loan_result = $loan_stmt->get_result();
$loans = $loan_result->fetch_all(MYSQLI_ASSOC);

// Include header
include '../../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
            --light-bg: #f8f9fa;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-container {
            background: white;
            min-height: 100vh;
            border-radius: 0;
            box-shadow: 0 0 50px rgba(0,0,0,0.1);
        }
        
        .page-header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            border: none;
            padding: 20px;
            font-weight: 600;
        }
        
        .table th {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            border: none;
            font-weight: 600;
            padding: 15px 12px;
            font-size: 0.9rem;
        }
        
        .table td {
            padding: 12px;
            vertical-align: middle;
            border-color: #e9ecef;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 176, 155, 0.4);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: translateY(-1px);
        }
        
        .badge {
            font-size: 0.75em;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #b8b8b8;
            margin-bottom: 20px;
        }
        
        .user-info {
            background: rgba(255,255,255,0.1);
            padding: 10px 20px;
            border-radius: 10px;
            margin-left: 20px;
        }
        
        .summary-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        /* Status badge colors */
        .badge-active { background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%); }
        .badge-completed { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .badge-pending { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .badge-approved { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .badge-rejected { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #000; }
        .badge-disbursed { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 20px 0;
            }
            
            .stat-card {
                margin-bottom: 15px;
            }
            
            .user-info {
                margin-left: 0;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Main Container -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1 class="h2 mb-2"><i class="fas fa-chart-line me-2"></i>Investment Report</h1>
                        <p class="mb-0 opacity-75">Comprehensive overview of loan investments and portfolio performance</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="user-info d-inline-block">
                            <i class="fas fa-user-shield me-2"></i>
                            <strong><?php echo $_SESSION['full_name']; ?></strong>
                            <span class="opacity-75"> - System Administrator</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- Action Buttons -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div></div>
                        <div class="btn-group">
                            <button class="btn btn-primary" onclick="printReport()">
                                <i class="fas fa-print me-2"></i>Print Report
                            </button>
                            <button class="btn btn-success" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-2"></i>Export Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Report Filters
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <?php if ($_SESSION['user_type'] === 'admin'): ?>
                                <div class="col-md-3">
                                    <label for="field_officer_id" class="form-label fw-semibold">Field Officer</label>
                                    <select class="form-select" id="field_officer_id" name="field_officer_id">
                                        <option value="">All Field Officers</option>
                                        <?php
                                        $staff_query = "SELECT id, full_name, short_name FROM staff WHERE position = 'field_officer' ORDER BY full_name";
                                        $staff_result = $conn->query($staff_query);
                                        while ($staff = $staff_result->fetch_assoc()) {
                                            $selected = $field_officer_id == $staff['id'] ? 'selected' : '';
                                            echo "<option value='{$staff['id']}' $selected>{$staff['full_name']} ({$staff['short_name']})</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-3">
                                    <label for="cbo_id" class="form-label fw-semibold">CBO</label>
                                    <select class="form-select" id="cbo_id" name="cbo_id">
                                        <option value="">All CBOs</option>
                                        <?php
                                        $cbo_query = "SELECT id, cbo_number, name FROM cbo ORDER BY name";
                                        if ($_SESSION['user_type'] === 'staff') {
                                            $cbo_query = "SELECT DISTINCT c.id, c.cbo_number, c.name 
                                                         FROM cbo c 
                                                         LEFT JOIN loans l ON l.cbo_id = c.id 
                                                         WHERE l.staff_id = " . intval($_SESSION['user_id']) . " 
                                                         ORDER BY c.name";
                                        }
                                        $cbo_result = $conn->query($cbo_query);
                                        while ($cbo = $cbo_result->fetch_assoc()) {
                                            $selected = $cbo_id == $cbo['id'] ? 'selected' : '';
                                            echo "<option value='{$cbo['id']}' $selected>{$cbo['cbo_number']} - {$cbo['name']}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="loan_status" class="form-label fw-semibold">Loan Status</label>
                                    <select class="form-select" id="loan_status" name="loan_status">
                                        <option value="">All Status</option>
                                        <option value="active" <?= $loan_status == 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="completed" <?= $loan_status == 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="pending" <?= $loan_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="approved" <?= $loan_status == 'approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="rejected" <?= $loan_status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="start_date" class="form-label fw-semibold">From Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                           value="<?= htmlspecialchars($start_date) ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="end_date" class="form-label fw-semibold">To Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           value="<?= htmlspecialchars($end_date) ?>">
                                </div>
                                
                                <div class="col-12">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-sync-alt me-2"></i>Generate Report
                                        </button>
                                        <a href="investment.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-undo me-2"></i>Reset Filters
                                        </a>
                                        <?php if (count($loans) > 0): ?>
                                        <span class="ms-3 align-self-center text-muted">
                                            <i class="fas fa-info-circle me-1"></i>Showing <?= count($loans) ?> records
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary mx-auto">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="summary-value text-primary"><?= number_format($summary['total_loans']) ?></div>
                            <div class="summary-label">Total Loans</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-success bg-opacity-10 text-success mx-auto">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="summary-value text-success">Rs. <?= number_format($summary['total_loan_amount'], 2) ?></div>
                            <div class="summary-label">Loan Amount</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-info bg-opacity-10 text-info mx-auto">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div class="summary-value text-info">Rs. <?= number_format($summary['total_service_charge'], 2) ?></div>
                            <div class="summary-label">Service Charge</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning mx-auto">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="summary-value text-warning">Rs. <?= number_format($summary['total_document_charge'], 2) ?></div>
                            <div class="summary-label">Document Charge</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary mx-auto">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="summary-value text-secondary">Rs. <?= number_format($summary['total_investment'], 2) ?></div>
                            <div class="summary-label">Total Investment</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="card stat-card">
                        <div class="card-body text-center">
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger mx-auto">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="summary-value text-danger">Rs. <?= number_format($summary['outstanding_balance'], 2) ?></div>
                            <div class="summary-label">Outstanding Balance</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Report -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list-check me-2"></i>Loan Investment Details
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($loans) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped mb-0">
                                        <thead class="table-dark">
                                            <tr>
                                                <th width="50">#</th>
                                                <th>CBO Details</th>
                                                <th>Loan Information</th>
                                                <th>Customer Details</th>
                                                <th class="text-end">Financials</th>
                                                <th>Status & Dates</th>
                                                <th>Field Officer</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $counter = 1;
                                            foreach ($loans as $loan): 
                                                $customer_profile_url = "http://dtrmf20251019.slhosted.lk/modules/customer/view.php?customer_id=" . $loan['customer_id'];
                                            ?>
                                                <tr>
                                                    <td class="text-muted fw-bold"><?= $counter++ ?></td>
                                                    <td>
                                                        <div class="fw-bold text-primary"><?= htmlspecialchars($loan['cbo_name']) ?></div>
                                                        <small class="text-muted">ID: <?= $loan['cbo_number'] ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary fs-6"><?= htmlspecialchars($loan['loan_number']) ?></span>
                                                        <div class="mt-1">
                                                            <small class="text-muted">Rs. <?= number_format($loan['loan_amount'], 2) ?></small>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <a href="<?= $customer_profile_url ?>" class="text-decoration-none fw-semibold text-dark" target="_blank" title="View Customer Profile">
                                                            <?= htmlspecialchars($loan['customer_name']) ?>
                                                            <i class="fas fa-external-link-alt ms-1 text-primary" style="font-size: 0.7em;"></i>
                                                        </a>
                                                        <?php if ($loan['customer_phone']): ?>
                                                            <div class="mt-1">
                                                                <small class="text-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($loan['customer_phone']) ?></small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="fw-bold text-success">Rs. <?= number_format($loan['loan_amount'], 2) ?></div>
                                                        <div class="text-info">Service: Rs. <?= number_format($loan['service_charge'], 2) ?></div>
                                                        <div class="text-warning">Doc: Rs. <?= number_format($loan['document_charge'], 2) ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?= getLoanStatusBadge($loan['loan_status']) ?> mb-2">
                                                            <?= ucfirst($loan['loan_status']) ?>
                                                        </span>
                                                        <div class="small">
                                                            <div class="text-muted">Applied: <?= $loan['applied_date'] ? date('M d, Y', strtotime($loan['applied_date'])) : 'N/A' ?></div>
                                                            <?php if ($loan['disbursed_date']): ?>
                                                                <div class="text-success">Disbursed: <?= date('M d, Y', strtotime($loan['disbursed_date'])) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($loan['staff_short_name'] ?: $loan['staff_name']) ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h4 class="text-muted mt-3">No Loan Data Found</h4>
                                    <p class="text-muted mb-4">Try adjusting your filters or search criteria to see results</p>
                                    <a href="investment.php" class="btn btn-primary">
                                        <i class="fas fa-undo me-2"></i>Reset Filters
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function printReport() {
        window.print();
    }

    function exportToExcel() {
        const table = document.querySelector('table');
        const html = table.outerHTML;
        const url = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        const downloadLink = document.createElement('a');
        downloadLink.href = url;
        downloadLink.download = 'Investment_Report_<?= date('Y_m_d') ?>.xls';
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
        
        // Show success message
        showNotification('Report exported successfully!', 'success');
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 3000);
    }

    // Initialize date inputs
    document.addEventListener('DOMContentLoaded', function() {
        const startDate = document.getElementById('start_date');
        const endDate = document.getElementById('end_date');
        
        if (!startDate.value) {
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
        }
        
        if (!endDate.value) {
            endDate.value = new Date().toISOString().split('T')[0];
        }
    });
    </script>
</body>
</html>

<?php
// Helper function for status badge classes
function getLoanStatusBadge($status) {
    switch($status) {
        case 'active': return 'badge-active';
        case 'completed': return 'badge-completed';
        case 'pending': return 'badge-pending';
        case 'approved': return 'badge-approved';
        case 'rejected': return 'badge-rejected';
        case 'disbursed': return 'badge-disbursed';
        default: return 'bg-secondary';
    }
}

include '../../includes/footer.php';
?>