<?php
// Start output buffering
ob_start();

// Include config
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
$field_officer_id = $_GET['field_officer_id'] ?? '';
$cbo_id = $_GET['cbo_id'] ?? '';
$group_id = $_GET['group_id'] ?? '';
$due_date = $_GET['due_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'arrears';
$export = $_GET['export'] ?? '';

// Get ALL staff members
$field_officers = [];
$staff_sql = "SELECT id, full_name, position FROM staff ORDER BY full_name";
$staff_result = $conn->query($staff_sql);

if ($staff_result && $staff_result->num_rows > 0) {
    while ($staff = $staff_result->fetch_assoc()) {
        $field_officers[] = $staff;
    }
}

// Get Arrears Report Data in Excel format
$arrears_data = [];
$total_arrears_amount = 0;
$total_loan_amount = 0;
$total_paid_amount = 0;
$total_remaining_balance = 0;

if ($due_date) {
    // Modified query for Excel format
    $sql = "SELECT 
                cb.name as center,
                l.loan_number,
                l.disbursed_date,
                c.full_name as client,
                c.national_id,
                c.phone as mobile,
                s.full_name as credit_officer,
                l.amount as loan_amount,
                l.balance as current_outstanding,
                (SELECT COALESCE(SUM(li.amount - COALESCE(li.paid_amount, 0)), 0)
                 FROM loan_installments li 
                 WHERE li.loan_id = l.id AND li.due_date <= ? AND 
                 (li.status != 'paid' OR li.paid_amount < li.amount)) as current_arrears,
                (SELECT COALESCE(SUM(li.amount), 0)
                 FROM loan_installments li 
                 WHERE li.loan_id = l.id AND li.due_date <= ?) as total_due,
                (SELECT COALESCE(SUM(lp.amount), 0) 
                 FROM loan_payments lp 
                 WHERE lp.loan_id = l.id AND lp.reversal_status != 'reversal') as total_paid,
                (SELECT COALESCE(SUM(li.amount - COALESCE(li.paid_amount, 0)), 0)
                 FROM loan_installments li 
                 WHERE li.loan_id = l.id AND li.due_date <= ? AND 
                 (li.status != 'paid' OR li.paid_amount < li.amount)) as not_paid,
                (SELECT (COALESCE(SUM(li.amount - COALESCE(li.paid_amount, 0)), 0) / 
                        COALESCE(SUM(li.amount), 1) * 100)
                 FROM loan_installments li 
                 WHERE li.loan_id = l.id AND li.due_date <= ?) as not_paid_percentage,
                (SELECT MAX(payment_date) FROM loan_payments 
                 WHERE loan_id = l.id AND reversal_status != 'reversal') as last_payment_date,
                CASE 
                    WHEN (SELECT COUNT(*) FROM loan_installments li 
                          WHERE li.loan_id = l.id AND li.due_date <= ? 
                          AND (li.status != 'paid' OR li.paid_amount < li.amount)) > 0 
                    THEN 'Not Paid'
                    ELSE 'Paid'
                END as status
            FROM loans l
            JOIN customers c ON l.customer_id = c.id
            JOIN cbo cb ON l.cbo_id = cb.id
            JOIN staff s ON cb.staff_id = s.id
            LEFT JOIN group_members gm ON c.id = gm.customer_id AND gm.group_id IS NOT NULL
            LEFT JOIN groups g ON gm.group_id = g.id
            WHERE l.status IN ('active', 'disbursed')
            AND EXISTS (
                SELECT 1 FROM loan_installments li 
                WHERE li.loan_id = l.id 
                AND li.due_date <= ? 
                AND (li.status != 'paid' OR li.paid_amount < li.amount)
            )";
    
    $params = [];
    $param_types = 'ssssss';
    $params[] = $due_date;
    $params[] = $due_date;
    $params[] = $due_date;
    $params[] = $due_date;
    $params[] = $due_date;
    $params[] = $due_date;
    
    if ($field_officer_id && $field_officer_id != 'all') {
        $sql .= " AND s.id = ?";
        $param_types .= 'i';
        $params[] = $field_officer_id;
    }
    
    if ($cbo_id) {
        $sql .= " AND cb.id = ?";
        $param_types .= 'i';
        $params[] = $cbo_id;
    }
    
    if ($group_id) {
        $sql .= " AND g.id = ?";
        $param_types .= 'i';
        $params[] = $group_id;
    }
    
    $sql .= " ORDER BY cb.name, s.full_name, c.full_name";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $arrears_data[] = $row;
            $total_arrears_amount += $row['current_arrears'];
            $total_loan_amount += $row['loan_amount'];
            $total_paid_amount += $row['total_paid'];
            $total_remaining_balance += $row['current_outstanding'];
        }
        $stmt->close();
    }
}

// Handle Excel Export
if ($export == 'excel' && !empty($arrears_data)) {
    ob_clean();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="arrears_report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Center</th>";
    echo "<th>Loan No</th>";
    echo "<th>Loan Disbursed Date</th>";
    echo "<th>Client</th>";
    echo "<th>NIC Number</th>";
    echo "<th>Mobile</th>";
    echo "<th>Credit Officer</th>";
    echo "<th>Loan Amount</th>";
    echo "<th>Current Outstanding</th>";
    echo "<th>Current Arrears As of today</th>";
    echo "<th>Total Due</th>";
    echo "<th>Total Paid</th>";
    echo "<th>Not Paid</th>";
    echo "<th>Not Paid %</th>";
    echo "<th>Last Payment Date</th>";
    echo "<th>Status</th>";
    echo "</tr>";
    
    foreach ($arrears_data as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['center']) . "</td>";
        echo "<td>" . htmlspecialchars($row['loan_number']) . "</td>";
        echo "<td>" . date('m/d/Y', strtotime($row['disbursed_date'])) . "</td>";
        echo "<td>" . htmlspecialchars($row['client']) . "</td>";
        echo "<td>" . htmlspecialchars($row['national_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['mobile']) . "</td>";
        echo "<td>" . htmlspecialchars($row['credit_officer']) . "</td>";
        echo "<td>" . number_format($row['loan_amount'], 2) . "</td>";
        echo "<td>" . number_format($row['current_outstanding'], 2) . "</td>";
        echo "<td>" . number_format($row['current_arrears'], 2) . "</td>";
        echo "<td>" . number_format($row['total_due'], 2) . "</td>";
        echo "<td>" . number_format($row['total_paid'], 2) . "</td>";
        echo "<td>" . number_format($row['not_paid'], 2) . "</td>";
        echo "<td>" . number_format($row['not_paid_percentage'], 2) . "%</td>";
        echo "<td>" . ($row['last_payment_date'] ? date('m/d/Y', strtotime($row['last_payment_date'])) : 'N/A') . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "</tr>";
    }
    
    // Add totals row
    echo "<tr style='font-weight:bold; background-color:#f0f0f0;'>";
    echo "<td colspan='7' style='text-align:right;'>TOTALS:</td>";
    echo "<td>" . number_format($total_loan_amount, 2) . "</td>";
    echo "<td>" . number_format($total_remaining_balance, 2) . "</td>";
    echo "<td>" . number_format($total_arrears_amount, 2) . "</td>";
    echo "<td colspan='5'></td>";
    echo "</tr>";
    
    echo "</table>";
    exit();
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arrears Report - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Custom styles for proper layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #2c3e50;
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
        
        .report-header { 
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white; 
            padding: 25px; 
            border-radius: 15px; 
            margin-bottom: 25px; 
        }
        .table th { 
            background-color: #34495e;
            color: white; 
            font-weight: 600;
            border: none;
            position: sticky;
            top: 0;
        }
        .table td {
            border-color: #e9ecef;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .card { 
            border: none; 
            border-radius: 12px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.05); 
        }
        .card-header { 
            border-radius: 12px 12px 0 0 !important; 
            border: none; 
            font-weight: 600;
            background-color: #2c3e50;
            color: white;
        }
        .status-not-paid { 
            background-color: #e74c3c; 
            color: white; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.8rem;
        }
        .status-paid { 
            background-color: #27ae60; 
            color: white; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.8rem;
        }
        .total-row {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        /* Page header styles */
        .page-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .card { box-shadow: none; border: 1px solid #dee2e6; }
            .table th { background-color: #2c3e50 !important; }
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
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
                                <li class="breadcrumb-item active text-primary fw-semibold">Arrears Report</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Arrears Report - Excel Format</h1>
                        <p class="text-muted mb-0">Complete arrears analysis in spreadsheet format</p>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-danger fs-6">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?php echo count($arrears_data); ?> Loans in Arrears
                        </span>
                    </div>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Arrears Report</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Field Officer</label>
                            <select class="form-select" name="field_officer_id">
                                <option value="">All Field Officers</option>
                                <?php foreach($field_officers as $officer): ?>
                                    <option value="<?php echo $officer['id']; ?>" 
                                        <?php echo $field_officer_id == $officer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($officer['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Due Date (Up to)</label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo $due_date; ?>" required>
                            <div class="form-text">
                                <small>Installments due up to this date</small>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="bi bi-search me-2"></i>Generate Report
                            </button>
                            <a href="Not_pay.php" class="btn btn-secondary px-4">
                                <i class="bi bi-arrow-clockwise me-2"></i>Clear
                            </a>
                            <?php if(!empty($arrears_data)): ?>
                            <button type="button" class="btn btn-info px-4" onclick="window.print()">
                                <i class="bi bi-printer me-2"></i>Print Report
                            </button>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
                               class="btn btn-success px-4">
                                <i class="bi bi-file-excel me-2"></i>Export to Excel
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results Section -->
            <?php if(!empty($arrears_data)): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-list-ul me-2"></i> 
                            Arrears Report - Excel Format
                        </h5>
                        <span class="badge bg-light text-dark fs-6">
                            Total Records: <?php echo count($arrears_data); ?> | 
                            Total Arrears: Rs. <?php echo number_format($total_arrears_amount, 2); ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <!-- Report Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <table class="table table-sm table-bordered">
                                    <tr>
                                        <th width="40%" class="bg-light text-dark">Report Type:</th>
                                        <td class="text-dark">Arrears Report - Excel Format</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-dark">Due Date (Up to):</th>
                                        <td class="text-dark"><?php echo date('M d, Y', strtotime($due_date)); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-dark">Total Records:</th>
                                        <td class="text-dark"><?php echo count($arrears_data); ?> Loans</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-dark">Generated On:</th>
                                        <td class="text-dark"><?php echo date('M d, Y H:i:s'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Excel Style Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Center</th>
                                        <th>Loan No</th>
                                        <th>Loan Disbursed Date</th>
                                        <th>Client</th>
                                        <th>NIC Number</th>
                                        <th>Mobile</th>
                                        <th>Credit Officer</th>
                                        <th>Loan Amount</th>
                                        <th>Current Outstanding</th>
                                        <th>Current Arrears</th>
                                        <th>Total Due</th>
                                        <th>Total Paid</th>
                                        <th>Not Paid</th>
                                        <th>Not Paid %</th>
                                        <th>Last Payment Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($arrears_data as $row): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($row['center']); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['loan_number']); ?></td>
                                        <td><?php echo date('m/d/Y', strtotime($row['disbursed_date'])); ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($row['client']); ?></td>
                                        <td><?php echo htmlspecialchars($row['national_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['mobile']); ?></td>
                                        <td><?php echo htmlspecialchars($row['credit_officer']); ?></td>
                                        <td class="text-end">Rs. <?php echo number_format($row['loan_amount'], 2); ?></td>
                                        <td class="text-end">Rs. <?php echo number_format($row['current_outstanding'], 2); ?></td>
                                        <td class="text-end text-danger fw-bold">Rs. <?php echo number_format($row['current_arrears'], 2); ?></td>
                                        <td class="text-end">Rs. <?php echo number_format($row['total_due'], 2); ?></td>
                                        <td class="text-end text-success">Rs. <?php echo number_format($row['total_paid'], 2); ?></td>
                                        <td class="text-end text-danger">Rs. <?php echo number_format($row['not_paid'], 2); ?></td>
                                        <td class="text-end text-danger"><?php echo number_format($row['not_paid_percentage'], 2); ?>%</td>
                                        <td><?php echo $row['last_payment_date'] ? date('m/d/Y', strtotime($row['last_payment_date'])) : 'N/A'; ?></td>
                                        <td>
                                            <span class="<?php echo $row['status'] == 'Not Paid' ? 'status-not-paid' : 'status-paid'; ?>">
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="total-row">
                                    <tr>
                                        <td colspan="7" class="text-end fw-bold">TOTALS:</td>
                                        <td class="text-end">Rs. <?php echo number_format($total_loan_amount, 2); ?></td>
                                        <td class="text-end">Rs. <?php echo number_format($total_remaining_balance, 2); ?></td>
                                        <td class="text-end">Rs. <?php echo number_format($total_arrears_amount, 2); ?></td>
                                        <td colspan="6"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif($due_date): ?>
                <!-- No results message -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle display-1 text-success"></i>
                        <h4 class="text-success mt-3">No Arrears Found</h4>
                        <p class="text-muted">No customers with arrears found for the selected criteria.</p>
                        <p class="text-muted">Try using a different due date or filter combination.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>