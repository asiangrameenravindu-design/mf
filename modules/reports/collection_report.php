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
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'detailed';

// Get ALL staff members
$field_officers = [];
$staff_sql = "SELECT id, full_name, position FROM staff ORDER BY full_name";
$staff_result = $conn->query($staff_sql);

if ($staff_result && $staff_result->num_rows > 0) {
    while ($staff = $staff_result->fetch_assoc()) {
        $field_officers[] = $staff;
    }
}

// Get CBOs for filter (based on selected field officer)
$cbos = [];
if ($field_officer_id && $field_officer_id != 'all') {
    $cbo_sql = "SELECT id, name FROM cbo WHERE staff_id = ? ORDER BY name";
    $cbo_stmt = $conn->prepare($cbo_sql);
    if ($cbo_stmt) {
        $cbo_stmt->bind_param('i', $field_officer_id);
        $cbo_stmt->execute();
        $cbo_result = $cbo_stmt->get_result();
        while ($cbo = $cbo_result->fetch_assoc()) {
            $cbos[] = $cbo;
        }
        $cbo_stmt->close();
    }
} elseif ($field_officer_id == 'all') {
    // For "All Staff Members", get all CBOs
    $cbo_sql = "SELECT id, name FROM cbo ORDER BY name";
    $cbo_result = $conn->query($cbo_sql);
    if ($cbo_result && $cbo_result->num_rows > 0) {
        while ($cbo = $cbo_result->fetch_assoc()) {
            $cbos[] = $cbo;
        }
    }
}

// Get collection data
$collections = [];
$total_amount = 0;
$cbo_totals = [];
$field_officer_name = '';
$all_staff_data = [];

if ($field_officer_id && $start_date && $end_date) {
    // Get field officer name
    if ($field_officer_id != 'all') {
        $fo_sql = "SELECT full_name FROM staff WHERE id = ?";
        $fo_stmt = $conn->prepare($fo_sql);
        if ($fo_stmt) {
            $fo_stmt->bind_param('i', $field_officer_id);
            $fo_stmt->execute();
            $fo_result = $fo_stmt->get_result();
            if ($fo_data = $fo_result->fetch_assoc()) {
                $field_officer_name = $fo_data['full_name'];
            }
            $fo_stmt->close();
        }
    } else {
        $field_officer_name = 'All Staff Members';
    }
    
    if ($report_type == 'detailed') {
        // Detailed report logic
        if ($field_officer_id != 'all') {
            // Single staff member
            $sql = "SELECT lp.*, l.loan_number, c.full_name, c.national_id, c.id as customer_id,
                           cb.name as cbo_name, cb.id as cbo_id, s.full_name as staff_name,
                           li.installment_number, l.id as loan_id
                    FROM loan_payments lp
                    JOIN loans l ON lp.loan_id = l.id
                    JOIN customers c ON l.customer_id = c.id
                    JOIN cbo cb ON l.cbo_id = cb.id
                    JOIN staff s ON cb.staff_id = s.id
                    LEFT JOIN loan_installments li ON lp.installment_id = li.id
                    WHERE cb.staff_id = ? 
                    AND lp.payment_date BETWEEN ? AND ?";
            
            if ($cbo_id) {
                $sql .= " AND cb.id = ?";
            }
            
            $sql .= " ORDER BY cb.name, lp.payment_date, l.loan_number";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($cbo_id) {
                    $stmt->bind_param('issi', $field_officer_id, $start_date, $end_date, $cbo_id);
                } else {
                    $stmt->bind_param('iss', $field_officer_id, $start_date, $end_date);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                $collections = [];
                $total_amount = 0;
                $cbo_totals = [];
                
                while ($collection = $result->fetch_assoc()) {
                    $collections[] = $collection;
                    
                    // Handle negative amounts in reversal payments
                    $amount = $collection['amount'];
                    $is_reversal = $collection['reversal_status'] == 'reversal';
                    
                    if ($is_reversal && $amount < 0) {
                        $amount = abs($amount);
                    }
                    
                    if ($is_reversal) {
                        $total_amount -= $amount;
                    } else {
                        $total_amount += $amount;
                    }
                    
                    if (!isset($cbo_totals[$collection['cbo_id']])) {
                        $cbo_totals[$collection['cbo_id']] = [
                            'name' => $collection['cbo_name'],
                            'total' => 0
                        ];
                    }
                    
                    if ($is_reversal) {
                        $cbo_totals[$collection['cbo_id']]['total'] -= $amount;
                    } else {
                        $cbo_totals[$collection['cbo_id']]['total'] += $amount;
                    }
                }
                $stmt->close();
            }
        } else {
            // All staff members - detailed report
            $sql = "SELECT lp.*, l.loan_number, c.full_name, c.national_id, c.id as customer_id,
                           cb.name as cbo_name, cb.id as cbo_id, s.full_name as staff_name, s.id as staff_id,
                           li.installment_number, l.id as loan_id
                    FROM loan_payments lp
                    JOIN loans l ON lp.loan_id = l.id
                    JOIN customers c ON l.customer_id = c.id
                    JOIN cbo cb ON l.cbo_id = cb.id
                    JOIN staff s ON cb.staff_id = s.id
                    LEFT JOIN loan_installments li ON lp.installment_id = li.id
                    WHERE lp.payment_date BETWEEN ? AND ?";
            
            if ($cbo_id) {
                $sql .= " AND cb.id = ?";
            }
            
            $sql .= " ORDER BY s.full_name, cb.name, lp.payment_date, l.loan_number";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($cbo_id) {
                    $stmt->bind_param('si', $start_date, $end_date, $cbo_id);
                } else {
                    $stmt->bind_param('ss', $start_date, $end_date);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                $collections = [];
                $total_amount = 0;
                $cbo_totals = [];
                $staff_totals = [];
                
                while ($collection = $result->fetch_assoc()) {
                    $collections[] = $collection;
                    
                    // Handle negative amounts in reversal payments
                    $amount = $collection['amount'];
                    $is_reversal = $collection['reversal_status'] == 'reversal';
                    
                    if ($is_reversal && $amount < 0) {
                        $amount = abs($amount);
                    }
                    
                    if ($is_reversal) {
                        $total_amount -= $amount;
                    } else {
                        $total_amount += $amount;
                    }
                    
                    // Staff totals
                    if (!isset($staff_totals[$collection['staff_id']])) {
                        $staff_totals[$collection['staff_id']] = [
                            'name' => $collection['staff_name'],
                            'total' => 0
                        ];
                    }
                    
                    if ($is_reversal) {
                        $staff_totals[$collection['staff_id']]['total'] -= $amount;
                    } else {
                        $staff_totals[$collection['staff_id']]['total'] += $amount;
                    }
                    
                    // CBO totals
                    if (!isset($cbo_totals[$collection['cbo_id']])) {
                        $cbo_totals[$collection['cbo_id']] = [
                            'name' => $collection['cbo_name'],
                            'total' => 0,
                            'staff_name' => $collection['staff_name']
                        ];
                    }
                    
                    if ($is_reversal) {
                        $cbo_totals[$collection['cbo_id']]['total'] -= $amount;
                    } else {
                        $cbo_totals[$collection['cbo_id']]['total'] += $amount;
                    }
                }
                $stmt->close();
            }
        }
    } else {
        // SUMMARY REPORT LOGIC
        if ($field_officer_id != 'all') {
            // Single staff member summary
            $sql = "SELECT 
                        cb.id as cbo_id, 
                        cb.name as cbo_name,
                        COUNT(DISTINCT l.id) as total_loans,
                        COUNT(DISTINCT l.customer_id) as total_customers,
                        SUM(CASE WHEN lp.reversal_status = 'reversal' THEN -lp.amount ELSE lp.amount END) as net_collection
                    FROM loan_payments lp
                    JOIN loans l ON lp.loan_id = l.id
                    JOIN cbo cb ON l.cbo_id = cb.id
                    WHERE cb.staff_id = ? 
                    AND lp.payment_date BETWEEN ? AND ?";
            
            if ($cbo_id) {
                $sql .= " AND cb.id = ?";
            }
            
            $sql .= " GROUP BY cb.id, cb.name ORDER BY cb.name";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($cbo_id) {
                    $stmt->bind_param('issi', $field_officer_id, $start_date, $end_date, $cbo_id);
                } else {
                    $stmt->bind_param('iss', $field_officer_id, $start_date, $end_date);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                $cbo_totals = [];
                $total_amount = 0;
                
                while ($row = $result->fetch_assoc()) {
                    $cbo_totals[$row['cbo_id']] = [
                        'name' => $row['cbo_name'],
                        'total' => $row['net_collection'],
                        'total_loans' => $row['total_loans'],
                        'total_customers' => $row['total_customers']
                    ];
                    $total_amount += $row['net_collection'];
                }
                $stmt->close();
            }
        } else {
            // All staff members summary
            $sql = "SELECT 
                        s.id as staff_id,
                        s.full_name as staff_name,
                        COUNT(DISTINCT l.id) as total_loans,
                        COUNT(DISTINCT l.customer_id) as total_customers,
                        SUM(CASE WHEN lp.reversal_status = 'reversal' THEN -lp.amount ELSE lp.amount END) as net_collection
                    FROM loan_payments lp
                    JOIN loans l ON lp.loan_id = l.id
                    JOIN cbo cb ON l.cbo_id = cb.id
                    JOIN staff s ON cb.staff_id = s.id
                    WHERE lp.payment_date BETWEEN ? AND ?";
            
            if ($cbo_id) {
                $sql .= " AND cb.id = ?";
            }
            
            $sql .= " GROUP BY s.id, s.full_name ORDER BY s.full_name";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($cbo_id) {
                    $stmt->bind_param('si', $start_date, $end_date, $cbo_id);
                } else {
                    $stmt->bind_param('ss', $start_date, $end_date);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                $all_staff_data = [];
                $total_amount = 0;
                
                while ($row = $result->fetch_assoc()) {
                    $all_staff_data[$row['staff_id']] = [
                        'name' => $row['staff_name'],
                        'total' => $row['net_collection'],
                        'total_loans' => $row['total_loans'],
                        'total_customers' => $row['total_customers']
                    ];
                    $total_amount += $row['net_collection'];
                }
                $stmt->close();
            }
        }
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Report - Micro Finance System</title>
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
        .total-card { 
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white; 
            border-radius: 12px; 
            padding: 20px; 
            text-align: center; 
        }
        .cbo-total { 
            background: #ffffff; 
            border-left: 5px solid #3498db; 
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .staff-total { 
            background: #ffffff; 
            border-left: 5px solid #e74c3c; 
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table th { 
            background-color: #34495e;
            color: white; 
            font-weight: 600;
            border: none;
        }
        .table td {
            border-color: #e9ecef;
            vertical-align: middle;
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
        .reversal-payment {
            background-color: #fff5f5 !important;
            color: #dc3545;
        }
        .reversal-badge {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .customer-link, .loan-link {
            color: #2980b9;
            text-decoration: none;
            font-weight: 500;
        }
        .customer-link:hover, .loan-link:hover {
            color: #1a5276;
            text-decoration: underline;
        }
        .text-dark {
            color: #2c3e50 !important;
        }
        .summary-card {
            background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
        }
        .staff-summary-card {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin: 5px 0;
        }
        .summary-label {
            font-size: 0.85rem;
            opacity: 0.9;
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
            .customer-link, .loan-link { color: #2c3e50 !important; text-decoration: none !important; }
            .report-header { background: #2c3e50 !important; }
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
                                <li class="breadcrumb-item active text-primary fw-semibold">Collection Report</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">Collection Report</h1>
                        <p class="text-muted mb-0">Staff wise collection summary and analysis</p>
                    </div>
                    <?php if(!empty($collections) || !empty($cbo_totals) || !empty($all_staff_data)): ?>
                    <div class="col-auto">
                        <span class="badge bg-success fs-6">
                            <i class="bi bi-cash-coin me-1"></i>
                            Net: Rs. <?php echo number_format($total_amount, 2); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filter Collections</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Staff Member *</label>
                            <select class="form-select" name="field_officer_id" required>
                                <option value="">Select Staff Member</option>
                                <option value="all" <?php echo $field_officer_id == 'all' ? 'selected' : ''; ?>>All Staff Members</option>
                                <?php foreach($field_officers as $officer): ?>
                                    <option value="<?php echo $officer['id']; ?>" 
                                        <?php echo $field_officer_id == $officer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($officer['full_name']); ?>
                                        <?php if($officer['position']): ?>
                                            (<?php echo $officer['position']; ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CBO</label>
                            <select class="form-select" name="cbo_id" <?php echo empty($cbos) ? 'disabled' : ''; ?>>
                                <option value="">All CBOs</option>
                                <?php foreach($cbos as $cbo): ?>
                                    <option value="<?php echo $cbo['id']; ?>" 
                                        <?php echo $cbo_id == $cbo['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cbo['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                        </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">From Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>" required>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">To Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>" required>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Report Type</label>
                            <select class="form-select" name="report_type">
                                <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                                <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="bi bi-search me-2"></i>Generate Report
                            </button>
                            <a href="collection_report.php" class="btn btn-secondary px-4">
                                <i class="bi bi-arrow-clockwise me-2"></i>Clear
                            </a>
                            <?php if(!empty($collections) || !empty($cbo_totals) || !empty($all_staff_data)): ?>
                            <button type="button" class="btn btn-info px-4 float-end" onclick="window.print()">
                                <i class="bi bi-printer me-2"></i>Print Report
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results Section -->
            <?php if(!empty($collections) || !empty($cbo_totals) || !empty($all_staff_data)): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-list-ul me-2"></i> 
                            <?php echo $report_type == 'detailed' ? 'Detailed Collection Report' : 'Summary Collection Report'; ?>
                        </h5>
                        <span class="badge bg-light text-dark fs-6">Net Total: Rs. <?php echo number_format($total_amount, 2); ?></span>
                    </div>
                    
                    <div class="card-body">
                        <!-- Report Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <table class="table table-sm table-bordered">
                                    <tr>
                                        <th width="40%" class="bg-light text-dark">Staff Member:</th>
                                        <td class="text-dark"><?php echo htmlspecialchars($field_officer_name); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-dark">Period:</th>
                                        <td class="text-dark"><?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></td>
                                    </tr>
                                    <?php if($cbo_id && isset($cbos)): ?>
                                    <?php 
                                    $selected_cbo_name = '';
                                    foreach($cbos as $cbo) {
                                        if ($cbo['id'] == $cbo_id) {
                                            $selected_cbo_name = $cbo['name'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <th class="bg-light text-dark">CBO:</th>
                                        <td class="text-dark"><?php echo htmlspecialchars($selected_cbo_name); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th class="bg-light text-dark">Report Type:</th>
                                        <td class="text-dark"><?php echo ucfirst($report_type); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-dark">Generated On:</th>
                                        <td class="text-dark"><?php echo date('M d, Y H:i:s'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if($report_type == 'detailed'): ?>
                            <!-- Detailed Report -->
                            <?php if($field_officer_id != 'all'): ?>
                                <!-- Single Staff Member Detailed Report -->
                                <?php foreach($cbo_totals as $cbo_id => $cbo_data): ?>
                                <div class="mb-4">
                                    <div class="cbo-total">
                                        <h6 class="mb-0 d-flex justify-content-between align-items-center text-dark">
                                            <span>
                                                <i class="bi bi-building me-2"></i> 
                                                <?php echo $cbo_data['name']; ?>
                                            </span>
                                            <span class="badge bg-success fs-6">
                                                Net: Rs. <?php echo number_format($cbo_data['total'], 2); ?>
                                            </span>
                                        </h6>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover table-striped">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th class="text-white">Date</th>
                                                    <th class="text-white">Loan No</th>
                                                    <th class="text-white">Customer Name</th>
                                                    <th class="text-white">NIC</th>
                                                    <th class="text-white">Installment</th>
                                                    <th class="text-white">Amount</th>
                                                    <th class="text-white">Status</th>
                                                    <th class="text-white">Reference</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $cbo_collections = array_filter($collections, function($item) use ($cbo_id) {
                                                    return $item['cbo_id'] == $cbo_id;
                                                });
                                                foreach($cbo_collections as $collection): 
                                                    $is_reversal = $collection['reversal_status'] == 'reversal';
                                                    // For display, show the absolute value for reversals
                                                    $display_amount = $collection['amount'];
                                                    if ($is_reversal && $display_amount < 0) {
                                                        $display_amount = abs($display_amount);
                                                    }
                                                ?>
                                                <tr class="<?php echo $is_reversal ? 'reversal-payment' : ''; ?>">
                                                    <td class="fw-semibold text-dark"><?php echo $collection['payment_date']; ?></td>
                                                    <td>
                                                        <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $collection['loan_id']; ?>" 
                                                           class="loan-link" target="_blank">
                                                            <span class="badge bg-primary"><?php echo $collection['loan_number']; ?></span>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $collection['customer_id']; ?>&nic=<?php echo urlencode($collection['national_id']); ?>&name=<?php echo urlencode($collection['full_name']); ?>" 
                                                           class="customer-link" target="_blank">
                                                            <?php echo htmlspecialchars($collection['full_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td><code class="text-dark"><?php echo htmlspecialchars($collection['national_id']); ?></code></td>
                                                    <td>
                                                        <?php if($collection['installment_number']): ?>
                                                            <span class="badge bg-info">#<?php echo $collection['installment_number']; ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="<?php echo $is_reversal ? 'text-danger' : 'text-success'; ?> fw-bold">
                                                        <?php if($is_reversal): ?>
                                                            -Rs. <?php echo number_format($display_amount, 2); ?>
                                                        <?php else: ?>
                                                            Rs. <?php echo number_format($display_amount, 2); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if($is_reversal): ?>
                                                            <span class="reversal-badge">
                                                                <i class="bi bi-arrow-counterclockwise me-1"></i>REVERSAL
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">ACTIVE</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><small class="text-muted"><?php echo $collection['payment_reference'] ?? 'N/A'; ?></small></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- All Staff Members Detailed Report -->
                                <?php 
                                $staff_grouped = [];
                                foreach($collections as $collection) {
                                    $staff_id = $collection['staff_id'];
                                    if (!isset($staff_grouped[$staff_id])) {
                                        $staff_grouped[$staff_id] = [
                                            'name' => $collection['staff_name'],
                                            'collections' => []
                                        ];
                                    }
                                    $staff_grouped[$staff_id]['collections'][] = $collection;
                                }
                                ?>
                                
                                <?php foreach($staff_grouped as $staff_id => $staff_data): ?>
                                <div class="mb-4">
                                    <div class="staff-total">
                                        <h6 class="mb-0 d-flex justify-content-between align-items-center text-dark">
                                            <span>
                                                <i class="bi bi-person-circle me-2"></i> 
                                                <?php echo $staff_data['name']; ?>
                                            </span>
                                            <span class="badge bg-success fs-6">
                                                Net: Rs. <?php echo number_format($staff_totals[$staff_id]['total'], 2); ?>
                                            </span>
                                        </h6>
                                    </div>
                                    
                                    <?php 
                                    $cbo_grouped = [];
                                    foreach($staff_data['collections'] as $collection) {
                                        $cbo_id = $collection['cbo_id'];
                                        if (!isset($cbo_grouped[$cbo_id])) {
                                            $cbo_grouped[$cbo_id] = [
                                                'name' => $collection['cbo_name'],
                                                'collections' => []
                                            ];
                                        }
                                        $cbo_grouped[$cbo_id]['collections'][] = $collection;
                                    }
                                    ?>
                                    
                                    <?php foreach($cbo_grouped as $cbo_id => $cbo_data): ?>
                                    <div class="ms-4 mb-3">
                                        <div class="cbo-total">
                                            <h6 class="mb-0 d-flex justify-content-between align-items-center text-dark">
                                                <span>
                                                    <i class="bi bi-building me-2"></i> 
                                                    <?php echo $cbo_data['name']; ?>
                                                </span>
                                                <span class="badge bg-info fs-6">
                                                    Net: Rs. <?php echo number_format($cbo_totals[$cbo_id]['total'], 2); ?>
                                                </span>
                                            </h6>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover table-striped">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th class="text-white">Date</th>
                                                        <th class="text-white">Loan No</th>
                                                        <th class="text-white">Customer Name</th>
                                                        <th class="text-white">NIC</th>
                                                        <th class="text-white">Installment</th>
                                                        <th class="text-white">Amount</th>
                                                        <th class="text-white">Status</th>
                                                        <th class="text-white">Reference</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($cbo_data['collections'] as $collection): 
                                                        $is_reversal = $collection['reversal_status'] == 'reversal';
                                                        // For display, show the absolute value for reversals
                                                        $display_amount = $collection['amount'];
                                                        if ($is_reversal && $display_amount < 0) {
                                                            $display_amount = abs($display_amount);
                                                        }
                                                    ?>
                                                    <tr class="<?php echo $is_reversal ? 'reversal-payment' : ''; ?>">
                                                        <td class="fw-semibold text-dark"><?php echo $collection['payment_date']; ?></td>
                                                        <td>
                                                            <a href="<?php echo BASE_URL; ?>/modules/loans/view.php?loan_id=<?php echo $collection['loan_id']; ?>" 
                                                               class="loan-link" target="_blank">
                                                                <span class="badge bg-primary"><?php echo $collection['loan_number']; ?></span>
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $collection['customer_id']; ?>&nic=<?php echo urlencode($collection['national_id']); ?>&name=<?php echo urlencode($collection['full_name']); ?>" 
                                                               class="customer-link" target="_blank">
                                                                <?php echo htmlspecialchars($collection['full_name']); ?>
                                                            </a>
                                                        </td>
                                                        <td><code class="text-dark"><?php echo htmlspecialchars($collection['national_id']); ?></code></td>
                                                        <td>
                                                            <?php if($collection['installment_number']): ?>
                                                                <span class="badge bg-info">#<?php echo $collection['installment_number']; ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="<?php echo $is_reversal ? 'text-danger' : 'text-success'; ?> fw-bold">
                                                            <?php if($is_reversal): ?>
                                                                -Rs. <?php echo number_format($display_amount, 2); ?>
                                                            <?php else: ?>
                                                                Rs. <?php echo number_format($display_amount, 2); ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if($is_reversal): ?>
                                                                <span class="reversal-badge">
                                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>REVERSAL
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">ACTIVE</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><small class="text-muted"><?php echo $collection['payment_reference'] ?? 'N/A'; ?></small></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- SUMMARY REPORT -->
                            <?php if($field_officer_id != 'all'): ?>
                                <!-- Single Staff Member Summary -->
                                <?php if($cbo_id): ?>
                                    <!-- Single CBO Summary -->
                                    <?php foreach($cbo_totals as $cbo_id => $cbo_data): ?>
                                    <div class="row mb-4">
                                        <div class="col-md-6 mx-auto">
                                            <div class="summary-card">
                                                <h5 class="mb-3"><?php echo $cbo_data['name']; ?></h5>
                                                <div class="summary-value">Rs. <?php echo number_format($cbo_data['total'], 2); ?></div>
                                                <div class="row mt-3">
                                                    <div class="col-6">
                                                        <div class="summary-label">Total Loans</div>
                                                        <div class="fw-bold"><?php echo $cbo_data['total_loans'] ?? 0; ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="summary-label">Total Customers</div>
                                                        <div class="fw-bold"><?php echo $cbo_data['total_customers'] ?? 0; ?></div>
                                                    </div>
                                                </div>
                                                <?php 
                                                $avg_per_loan = ($cbo_data['total_loans'] > 0) ? $cbo_data['total'] / $cbo_data['total_loans'] : 0;
                                                ?>
                                                <div class="mt-3">
                                                    <div class="summary-label">Average per Loan</div>
                                                    <div class="fw-bold">Rs. <?php echo number_format($avg_per_loan, 2); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Multiple CBOs Summary -->
                                    <div class="row mb-4">
                                        <?php foreach($cbo_totals as $cbo_id => $cbo_data): ?>
                                        <div class="col-md-4">
                                            <div class="summary-card">
                                                <h6 class="mb-2"><?php echo $cbo_data['name']; ?></h6>
                                                <div class="summary-value">Rs. <?php echo number_format($cbo_data['total'], 2); ?></div>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <small class="summary-label">Loans: <?php echo $cbo_data['total_loans'] ?? 0; ?></small>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="summary-label">Customers: <?php echo $cbo_data['total_customers'] ?? 0; ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover table-striped">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th class="text-white">CBO Name</th>
                                                    <th class="text-white text-end">Net Collection</th>
                                                    <th class="text-white text-center">Total Loans</th>
                                                    <th class="text-white text-center">Total Customers</th>
                                                    <th class="text-white text-center">Average per Loan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $grand_total_loans = 0;
                                                $grand_total_customers = 0;
                                                foreach($cbo_totals as $cbo_id => $cbo_data): 
                                                    $avg_per_loan = ($cbo_data['total_loans'] > 0) ? $cbo_data['total'] / $cbo_data['total_loans'] : 0;
                                                    $grand_total_loans += $cbo_data['total_loans'];
                                                    $grand_total_customers += $cbo_data['total_customers'];
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold text-dark">
                                                        <i class="bi bi-building me-2"></i><?php echo $cbo_data['name']; ?>
                                                    </td>
                                                    <td class="text-success fw-bold text-end">Rs. <?php echo number_format($cbo_data['total'], 2); ?></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $cbo_data['total_loans'] ?? 0; ?></span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?php echo $cbo_data['total_customers'] ?? 0; ?></span>
                                                    </td>
                                                    <td class="text-center fw-semibold text-dark">
                                                        Rs. <?php echo number_format($avg_per_loan, 2); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-secondary">
                                                <tr>
                                                    <th class="text-dark">GRAND TOTAL</th>
                                                    <th class="text-success text-end">Rs. <?php echo number_format($total_amount, 2); ?></th>
                                                    <th class="text-center">
                                                        <span class="badge bg-primary"><?php echo $grand_total_loans; ?></span>
                                                    </th>
                                                    <th class="text-center">
                                                        <span class="badge bg-info"><?php echo $grand_total_customers; ?></span>
                                                    </th>
                                                    <th class="text-center text-dark">
                                                        Rs. <?php echo $grand_total_loans > 0 ? number_format($total_amount / $grand_total_loans, 2) : '0.00'; ?>
                                                    </th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- All Staff Members Summary -->
                                <div class="row mb-4">
                                    <?php foreach($all_staff_data as $staff_id => $staff_data): ?>
                                    <div class="col-md-4">
                                        <div class="staff-summary-card">
                                            <h6 class="mb-2"><?php echo $staff_data['name']; ?></h6>
                                            <div class="summary-value">Rs. <?php echo number_format($staff_data['total'], 2); ?></div>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="summary-label">Loans: <?php echo $staff_data['total_loans'] ?? 0; ?></small>
                                                </div>
                                                <div class="col-6">
                                                    <small class="summary-label">Customers: <?php echo $staff_data['total_customers'] ?? 0; ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <th class="text-white">Staff Member</th>
                                                <th class="text-white text-end">Net Collection</th>
                                                <th class="text-white text-center">Total Loans</th>
                                                <th class="text-white text-center">Total Customers</th>
                                                <th class="text-white text-center">Average per Loan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $grand_total_loans = 0;
                                            $grand_total_customers = 0;
                                            foreach($all_staff_data as $staff_id => $staff_data): 
                                                $avg_per_loan = ($staff_data['total_loans'] > 0) ? $staff_data['total'] / $staff_data['total_loans'] : 0;
                                                $grand_total_loans += $staff_data['total_loans'];
                                                $grand_total_customers += $staff_data['total_customers'];
                                            ?>
                                            <tr>
                                                <td class="fw-semibold text-dark">
                                                    <i class="bi bi-person-circle me-2"></i><?php echo $staff_data['name']; ?>
                                                </td>
                                                <td class="text-success fw-bold text-end">Rs. <?php echo number_format($staff_data['total'], 2); ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary"><?php echo $staff_data['total_loans'] ?? 0; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info"><?php echo $staff_data['total_customers'] ?? 0; ?></span>
                                                </td>
                                                <td class="text-center fw-semibold text-dark">
                                                    Rs. <?php echo number_format($avg_per_loan, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-secondary">
                                            <tr>
                                                <th class="text-dark">GRAND TOTAL</th>
                                                <th class="text-success text-end">Rs. <?php echo number_format($total_amount, 2); ?></th>
                                                <th class="text-center">
                                                    <span class="badge bg-primary"><?php echo $grand_total_loans; ?></span>
                                                </th>
                                                <th class="text-center">
                                                    <span class="badge bg-info"><?php echo $grand_total_customers; ?></span>
                                                </th>
                                                <th class="text-center text-dark">
                                                    Rs. <?php echo $grand_total_loans > 0 ? number_format($total_amount / $grand_total_loans, 2) : '0.00'; ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif($field_officer_id): ?>
                <!-- No results message -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h4 class="text-muted mt-3">No Collections Found</h4>
                        <p class="text-muted">No collection records found for the selected criteria.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>