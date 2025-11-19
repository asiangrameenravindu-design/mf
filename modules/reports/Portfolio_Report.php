<?php
// modules/reports/Portfolio_Report.php
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission
$allowed_roles = ['manager', 'admin', 'accountant', 'credit_officer'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header('Location: ../../dashboard.php');
    exit();
}

// Get filter parameters
$center_id = isset($_GET['center_id']) ? intval($_GET['center_id']) : 0;
$officer_id = isset($_GET['officer_id']) ? intval($_GET['officer_id']) : 0;
$loan_status = isset($_GET['loan_status']) ? $_GET['loan_status'] : 'completed_disbursed';
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

// Validate date
if (!DateTime::createFromFormat('Y-m-d', $report_date)) {
    $report_date = date('Y-m-d');
}

// Initialize variables
$loans = [];
$centers = [];
$officers = [];
$center_info = null;
$summary = [
    'total_loans' => 0,
    'total_loan_amount' => 0,
    'total_outstanding' => 0,
    'total_arrears' => 0,
    'completed_loans' => 0,
    'disbursed_loans' => 0
];

try {
    // Get centers for filter
    $centers_result = $conn->query("SELECT id, name FROM cbo ORDER BY name");
    if ($centers_result) {
        $centers = $centers_result->fetch_all(MYSQLI_ASSOC);
    }

    // Get selected center info if center is selected
    if ($center_id > 0) {
        $center_info_result = $conn->query("
            SELECT 
                cb.*, 
                s.full_name as field_officer,
                s.id as field_officer_id
            FROM cbo cb 
            LEFT JOIN staff s ON cb.staff_id = s.id 
            WHERE cb.id = $center_id
        ");
        if ($center_info_result && $center_info_result->num_rows > 0) {
            $center_info = $center_info_result->fetch_assoc();
        } else {
            // If no staff linked, get center basic info
            $center_basic_result = $conn->query("
                SELECT * FROM cbo WHERE id = $center_id
            ");
            if ($center_basic_result && $center_basic_result->num_rows > 0) {
                $center_info = $center_basic_result->fetch_assoc();
                $center_info['field_officer'] = 'Not Assigned';
            }
        }
    }

    // Get ONLY FIELD OFFICERS from database (staff assigned to centers)
    $officers_result = $conn->query("
        SELECT DISTINCT s.id, s.full_name 
        FROM staff s 
        INNER JOIN cbo cb ON s.id = cb.staff_id 
        WHERE cb.staff_id IS NOT NULL
        ORDER BY s.full_name
    ");
    
    if ($officers_result) {
        $officers = $officers_result->fetch_all(MYSQLI_ASSOC);
    }

    // Build the main query with ONLY FIELD OFFICER
    $query = "
    SELECT 
        c.full_name AS customer_name,
        c.national_id,
        c.phone,
        c.address,
        cb.name AS center_name,
        cb.id as center_id,
        -- Get ONLY FIELD OFFICER from cbo table
        s.full_name AS officer_name,
        l.loan_number,
        l.amount AS principal_amount,
        l.total_loan_amount,
        l.balance,
        l.status AS loan_status,
        l.disbursed_date,
        l.settlement_date,
        -- Last payment date as completion date for completed loans
        CASE 
            WHEN l.status = 'completed' THEN (
                SELECT MAX(lp.payment_date) 
                FROM loan_payments lp 
                WHERE lp.loan_id = l.id 
                AND lp.status = 'completed'
                AND lp.reversal_status = 'original'
            )
            ELSE NULL
        END AS completion_date,
        
        -- Calculate total paid amount
        COALESCE((
            SELECT SUM(lp.amount) 
            FROM loan_payments lp 
            WHERE lp.loan_id = l.id 
            AND lp.status = 'completed' 
            AND lp.reversal_status = 'original'
        ), 0) AS total_paid,
        
        -- Count total installments
        (
            SELECT COUNT(*) 
            FROM loan_installments li 
            WHERE li.loan_id = l.id
        ) AS total_installments,
        
        -- Count paid installments
        (
            SELECT COUNT(*) 
            FROM loan_installments li 
            WHERE li.loan_id = l.id 
            AND li.status = 'paid'
        ) AS paid_installments,

        -- CORRECT ARREARS CALCULATION: Sum of overdue installments that are not fully paid
        COALESCE((
            SELECT SUM(li.amount - COALESCE(li.paid_amount, 0))
            FROM loan_installments li
            WHERE li.loan_id = l.id 
            AND li.due_date <= ?  -- Include today and past due dates
            AND li.status IN ('pending', 'overdue')
            AND (li.amount - COALESCE(li.paid_amount, 0)) > 0
        ), 0) AS arrears_amount,

        -- Count arrears installments
        (
            SELECT COUNT(*) 
            FROM loan_installments li 
            WHERE li.loan_id = l.id 
            AND li.due_date <= ?  -- Include today and past due dates
            AND li.status IN ('pending', 'overdue')
            AND (li.amount - COALESCE(li.paid_amount, 0)) > 0
        ) AS arrears_installments,

        -- Additional useful fields for arrears analysis
        (
            SELECT MIN(li.due_date)
            FROM loan_installments li
            WHERE li.loan_id = l.id 
            AND li.due_date <= ?
            AND li.status IN ('pending', 'overdue')
            AND (li.amount - COALESCE(li.paid_amount, 0)) > 0
        ) AS first_overdue_date,

        -- Calculate days in arrears (maximum days overdue)
        (
            SELECT DATEDIFF(?, MIN(li.due_date))
            FROM loan_installments li
            WHERE li.loan_id = l.id 
            AND li.due_date <= ?
            AND li.status IN ('pending', 'overdue')
            AND (li.amount - COALESCE(li.paid_amount, 0)) > 0
        ) AS days_in_arrears

    FROM loans l
    INNER JOIN customers c ON l.customer_id = c.id
    INNER JOIN cbo cb ON l.cbo_id = cb.id
    LEFT JOIN staff s ON cb.staff_id = s.id  -- Only join for FIELD OFFICER
    WHERE 1=1
    ";

    // Build parameters for arrears calculation
    $params = [$report_date, $report_date, $report_date, $report_date, $report_date];
    $types = "sssss";

    // Add filters
    $filter_conditions = [];

    if ($center_id > 0) {
        $filter_conditions[] = "l.cbo_id = ?";
        $params[] = $center_id;
        $types .= "i";
    }

    if ($officer_id > 0) {
        // Filter by FIELD OFFICER (staff assigned to center)
        $filter_conditions[] = "cb.staff_id = ?";
        $params[] = $officer_id;
        $types .= "i";
    }

    // Loan status filter
    if ($loan_status == 'completed_disbursed') {
        $filter_conditions[] = "l.status IN ('completed', 'disbursed')";
    } elseif (!empty($loan_status) && $loan_status != 'all') {
        $filter_conditions[] = "l.status = ?";
        $params[] = $loan_status;
        $types .= "s";
    }

    // Add filter conditions to query
    if (!empty($filter_conditions)) {
        $query .= " AND " . implode(" AND ", $filter_conditions);
    }

    // Order by
    $query .= " ORDER BY l.status, cb.name, c.full_name, l.applied_date DESC";

    // Prepare and execute
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $loans = $result->fetch_all(MYSQLI_ASSOC);

    // Calculate summary statistics
    $summary['total_loans'] = count($loans);
    foreach ($loans as $loan) {
        $summary['total_loan_amount'] += floatval($loan['total_loan_amount']);
        $summary['total_outstanding'] += floatval($loan['balance']);
        $summary['total_arrears'] += floatval($loan['arrears_amount']);
        
        if ($loan['loan_status'] == 'completed') {
            $summary['completed_loans']++;
        } elseif ($loan['loan_status'] == 'disbursed') {
            $summary['disbursed_loans']++;
        }
    }

    $stmt->close();

} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Custom styles for proper layout */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
        }
        
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                width: 100%;
            }
        }
        
        .table th { background-color: #f8f9fa; }
        .status-completed { color: #28a745; font-weight: bold; }
        .status-disbursed { color: #17a2b8; font-weight: bold; }
        .summary-card { border-left: 4px solid #007bff; }
        .negative-amount { color: #dc3545; font-weight: bold; }
        .table-responsive { font-size: 0.875rem; }
        .filter-section { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .completed-row { background-color: #f8f9fa; }
        .disbursed-row { background-color: #e7f3ff; }
        .summary-value { font-size: 1.1rem; font-weight: bold; }
        .center-info { background-color: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .arrears-details { font-size: 0.75rem; color: #dc3545; }
        .address-column { max-width: 200px; word-wrap: break-word; }
        
        /* Card styles */
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
        
        /* Ensure content doesn't overlap with sidebar */
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        
        .row {
            margin-left: 0;
            margin-right: 0;
        }
        
        .col-12 {
            padding-left: 0;
            padding-right: 0;
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
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/reports/" class="text-decoration-none text-muted">Reports</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold">Portfolio Report</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Portfolio Report</h1>
                        <p class="text-muted mb-0">Comprehensive overview of loan portfolio performance</p>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-primary fs-6">
                            <i class="bi bi-graph-up me-1"></i>
                            <?php echo $summary['total_loans']; ?> Total Loans
                        </span>
                    </div>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <div class="filter-section">
                <form method="GET" class="mb-0">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="center_id" class="form-label">Center</label>
                            <select name="center_id" id="center_id" class="form-select">
                                <option value="0">All Centers</option>
                                <?php foreach ($centers as $center): ?>
                                    <option value="<?= $center['id'] ?>" 
                                        <?= $center_id == $center['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($center['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="officer_id" class="form-label">Field Officer</label>
                            <select name="officer_id" id="officer_id" class="form-select">
                                <option value="0">All Field Officers</option>
                                <?php foreach ($officers as $officer): ?>
                                    <option value="<?= $officer['id'] ?>" 
                                        <?= $officer_id == $officer['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($officer['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="loan_status" class="form-label">Loan Status</label>
                            <select name="loan_status" id="loan_status" class="form-select">
                                <option value="completed_disbursed" <?= $loan_status == 'completed_disbursed' ? 'selected' : '' ?>>Completed & Disbursed</option>
                                <option value="all" <?= $loan_status == 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="completed" <?= $loan_status == 'completed' ? 'selected' : '' ?>>Completed Only</option>
                                <option value="disbursed" <?= $loan_status == 'disbursed' ? 'selected' : '' ?>>Disbursed Only</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="report_date" class="form-label">Report Date</label>
                            <input type="date" name="report_date" id="report_date" 
                                   class="form-control" value="<?= htmlspecialchars($report_date) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <button type="button" class="btn btn-success" onclick="exportToExcel()">
                                    Export to Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Center Information -->
            <?php if ($center_info): ?>
            <div class="center-info">
                <div class="row">
                    <div class="col-md-6">
                        <h5><?= htmlspecialchars($center_info['name']) ?></h5>
                        <p class="mb-1"><strong>CBO Number:</strong> <?= $center_info['cbo_number'] ?? 'N/A' ?></p>
                        <p class="mb-1"><strong>Meeting Day:</strong> <?= $center_info['meeting_day'] ?? 'N/A' ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Field Officer:</strong> 
                            <?= !empty($center_info['field_officer']) ? htmlspecialchars($center_info['field_officer']) : 'Not Assigned' ?>
                        </p>
                        <p class="mb-0"><strong>Report Date:</strong> <?= date('m/d/Y', strtotime($report_date)) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Total Loans</h6>
                            <h4 class="text-primary summary-value"><?= $summary['total_loans'] ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Total Amount</h6>
                            <h4 class="text-info summary-value">LKR <?= number_format($summary['total_loan_amount'], 2) ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Outstanding</h6>
                            <h4 class="text-warning summary-value">LKR <?= number_format($summary['total_outstanding'], 2) ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Arrears</h6>
                            <h4 class="text-danger summary-value">LKR <?= number_format($summary['total_arrears'], 2) ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Completed</h6>
                            <h4 class="text-success summary-value"><?= $summary['completed_loans'] ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 mb-3">
                    <div class="card summary-card h-100">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted">Disbursed</h6>
                            <h4 class="text-primary summary-value"><?= $summary['disbursed_loans'] ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Report Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-table me-2"></i>Loan Portfolio Details
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="portfolioTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Customer</th>
                                    <th>NIC</th>
                                    <th>Phone</th>
                                    <th>Address</th>
                                    <th>Center</th>
                                    <th>Field Officer</th>
                                    <th>Loan No</th>
                                    <th>Principal</th>
                                    <th>Total Loan</th>
                                    <th>Outstanding</th>
                                    <th>Paid</th>
                                    <th>Arrears</th>
                                    <th>Installments</th>
                                    <th>Status</th>
                                    <th>Disbursed Date</th>
                                    <th>Completion Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($loans)): ?>
                                    <tr>
                                        <td colspan="17" class="text-center py-4">
                                            <i class="bi bi-search text-muted" style="font-size: 2rem;"></i>
                                            <h5 class="text-muted mt-3">No loans found for the selected criteria</h5>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($loans as $index => $loan): ?>
                                        <tr class="<?= $loan['loan_status'] === 'completed' ? 'completed-row' : '' ?><?= $loan['loan_status'] === 'disbursed' ? 'disbursed-row' : '' ?>">
                                            <td><?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($loan['customer_name']) ?></td>
                                            <td><?= htmlspecialchars($loan['national_id']) ?></td>
                                            <td><?= htmlspecialchars($loan['phone']) ?></td>
                                            <td class="address-column"><?= !empty($loan['address']) ? htmlspecialchars($loan['address']) : '-' ?></td>
                                            <td><?= htmlspecialchars($loan['center_name']) ?></td>
                                            <td>
                                                <?= !empty($loan['officer_name']) ? htmlspecialchars($loan['officer_name']) : 'Not Assigned' ?>
                                            </td>
                                            <td><strong><?= htmlspecialchars($loan['loan_number']) ?></strong></td>
                                            <td class="text-end">LKR <?= number_format($loan['principal_amount'], 2) ?></td>
                                            <td class="text-end">LKR <?= number_format($loan['total_loan_amount'], 2) ?></td>
                                            <td class="text-end <?= $loan['balance'] > 0 ? 'text-warning' : '' ?>">
                                                LKR <?= number_format($loan['balance'], 2) ?>
                                            </td>
                                            <td class="text-end">LKR <?= number_format($loan['total_paid'], 2) ?></td>
                                            <td class="text-end <?= $loan['arrears_amount'] > 0 ? 'negative-amount' : '' ?>">
                                                LKR <?= number_format($loan['arrears_amount'], 2) ?>
                                                <?php if ($loan['arrears_installments'] > 0): ?>
                                                    <br>
                                                    <small class="arrears-details">
                                                        (<?= $loan['arrears_installments'] ?> installments)
                                                        <?php if ($loan['days_in_arrears'] > 0): ?>
                                                            <br><?= $loan['days_in_arrears'] ?> days overdue
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?= $loan['paid_installments'] ?>/<?= $loan['total_installments'] ?>
                                                <?php if ($loan['paid_installments'] == $loan['total_installments']): ?>
                                                    <br><small class="text-success">✓ Fully Paid</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-<?= $loan['loan_status'] ?>"><?= ucfirst($loan['loan_status']) ?></span>
                                            </td>
                                            <td>
                                                <?= $loan['disbursed_date'] ? date('m/d/Y', strtotime($loan['disbursed_date'])) : '-' ?>
                                            </td>
                                            <td>
                                                <?= $loan['completion_date'] ? date('m/d/Y', strtotime($loan['completion_date'])) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportToExcel() {
            const table = document.getElementById('portfolioTable');
            const html = table.outerHTML;
            const url = 'data:application/vnd.ms-excel,' + escape(html);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'portfolio_report_<?= date('Y-m-d') ?>.xls';
            link.click();
        }

        // Auto-submit form when filters change
        document.getElementById('center_id').addEventListener('change', function() {
            this.form.submit();
        });

        document.getElementById('officer_id').addEventListener('change', function() {
            this.form.submit();
        });

        document.getElementById('loan_status').addEventListener('change', function() {
            this.form.submit();
        });

        document.getElementById('report_date').addEventListener('change', function() {
            this.form.submit();
        });
    </script>
</body>
</html>