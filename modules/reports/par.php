<?php
require_once '../../config/config.php';
checkAccess();

$page_title = "PAR Movement Report - Summary & Details";
include '../../includes/header.php';
include '../../includes/sidebar.php';

// Date range filtering
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$center_filter = isset($_GET['center']) ? $_GET['center'] : '';
$view_type = isset($_GET['view']) ? $_GET['view'] : 'summary'; // summary or details

// Get REAL field officers from database
$officers_query = "
    SELECT id, full_name, position 
    FROM staff 
    WHERE position = 'field_officer' 
    ORDER BY full_name
";
$officers_result = $conn->query($officers_query);
$officers = [];

while ($officer = $officers_result->fetch_assoc()) {
    $officers[$officer['id']] = $officer;
}

// PAR Calculation for each officer
$categories = [
    'Day 0' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 0-6.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 7-30.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 31-60.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 61-90.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 91-120.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 121-150.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 151-180.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days 181-365.99' => ['loan_count' => 0, 'outstanding_amount' => 0],
    'Days > 365' => ['loan_count' => 0, 'outstanding_amount' => 0]
];

$officer_par_data = [];
$officer_customer_details = [];

foreach ($officers as $officer_id => $officer) {
    $officer_par_data[$officer_id] = $categories;
    $officer_customer_details[$officer_id] = [];
    
    // Get REAL loan data for each field officer WITH PAYMENT REVERSAL HANDLING
    $par_query = "
        SELECT 
            loan_data.loan_id,
            loan_data.customer_id,
            loan_data.customer_name,
            loan_data.national_id,
            loan_data.phone,
            loan_data.loan_number,
            loan_data.center_name,
            loan_data.outstanding_amount,
            loan_data.max_overdue_days,
            loan_data.overdue_amount,
            CASE 
                WHEN loan_data.max_overdue_days = -1 THEN 'Day 0'
                WHEN loan_data.max_overdue_days BETWEEN 0 AND 6.99 THEN 'Days 0-6.99'
                WHEN loan_data.max_overdue_days BETWEEN 7 AND 30.99 THEN 'Days 7-30.99'
                WHEN loan_data.max_overdue_days BETWEEN 31 AND 60.99 THEN 'Days 31-60.99'
                WHEN loan_data.max_overdue_days BETWEEN 61 AND 90.99 THEN 'Days 61-90.99'
                WHEN loan_data.max_overdue_days BETWEEN 91 AND 120.99 THEN 'Days 91-120.99'
                WHEN loan_data.max_overdue_days BETWEEN 121 AND 150.99 THEN 'Days 121-150.99'
                WHEN loan_data.max_overdue_days BETWEEN 151 AND 180.99 THEN 'Days 151-180.99'
                WHEN loan_data.max_overdue_days BETWEEN 181 AND 365.99 THEN 'Days 181-365.99'
                WHEN loan_data.max_overdue_days > 365 THEN 'Days > 365'
                ELSE 'Day 0'
            END AS overdue_category
        FROM (
            SELECT 
                l.id as loan_id,
                l.customer_id,
                c.full_name as customer_name,
                c.national_id,
                c.phone,
                l.loan_number,
                cb.name as center_name,
                l.staff_id,
                l.balance as outstanding_amount,
                COALESCE(MAX(
                    CASE 
                        WHEN li.due_date <= '$end_date' 
                        AND DATEDIFF('$end_date', li.due_date) >= 0 
                        AND li.status IN ('pending', 'partial')
                        AND (li.amount - COALESCE(ep.effective_paid_amount, li.paid_amount)) > 0
                        THEN DATEDIFF('$end_date', li.due_date)
                        ELSE -1 
                    END
                ), -1) as max_overdue_days,
                COALESCE(SUM(
                    CASE 
                        WHEN li.due_date <= '$end_date' 
                        AND DATEDIFF('$end_date', li.due_date) >= 0 
                        AND li.status IN ('pending', 'partial')
                        THEN (li.amount - COALESCE(ep.effective_paid_amount, li.paid_amount))
                        ELSE 0
                    END
                ), 0) as overdue_amount
            FROM loans l
            LEFT JOIN loan_installments li ON l.id = li.loan_id 
            LEFT JOIN customers c ON l.customer_id = c.id
            LEFT JOIN cbo cb ON l.cbo_id = cb.id
            LEFT JOIN (
                SELECT 
                    lp.loan_id,
                    lp.installment_id,
                    SUM(CASE 
                        WHEN lp.reversal_status = 'original' THEN lp.amount
                        WHEN lp.reversal_status = 'reversal' THEN -lp.amount
                        ELSE lp.amount
                    END) as effective_paid_amount
                FROM loan_payments lp
                WHERE lp.status = 'completed'
                AND lp.payment_date <= '$end_date'
                GROUP BY lp.loan_id, lp.installment_id
            ) ep ON li.id = ep.installment_id AND l.id = ep.loan_id
            WHERE l.status IN ('active', 'disbursed')
            AND l.staff_id = '$officer_id'
            " . ($center_filter ? " AND l.cbo_id = '$center_filter'" : "") . "
            GROUP BY l.id
        ) as loan_data
    ";

    $par_result = $conn->query($par_query);
    
    if ($par_result && $par_result->num_rows > 0) {
        while ($loan = $par_result->fetch_assoc()) {
            $category = $loan['overdue_category'];
            $officer_par_data[$officer_id][$category]['loan_count']++;
            $officer_par_data[$officer_id][$category]['outstanding_amount'] += $loan['outstanding_amount'];
            
            // Store customer details for detailed view
            $officer_customer_details[$officer_id][$category][] = $loan;
        }
    }
}

// Calculate totals
$branch_totals = $categories;
$grand_totals = [
    'total_loans' => 0,
    'total_outstanding' => 0,
    'par_move_loans' => 0,
    'par_move_outstanding' => 0
];

foreach ($officer_par_data as $officer_id => $officer_data) {
    foreach ($officer_data as $category => $data) {
        $branch_totals[$category]['loan_count'] += $data['loan_count'];
        $branch_totals[$category]['outstanding_amount'] += $data['outstanding_amount'];
        
        $grand_totals['total_loans'] += $data['loan_count'];
        $grand_totals['total_outstanding'] += $data['outstanding_amount'];
        
        // PAR Move calculation (from Day 7 onwards)
        if ($category !== 'Day 0' && $category !== 'Days 0-6.99') {
            $grand_totals['par_move_loans'] += $data['loan_count'];
            $grand_totals['par_move_outstanding'] += $data['outstanding_amount'];
        }
    }
}

// Get centers for filter
$centers_query = "SELECT id, name FROM cbo ORDER BY name";
$centers_result = $conn->query($centers_query);
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
        }
        
        body {
            background-color: #f5f7f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            border: none;
            padding: 12px 15px;
        }
        
        .table {
            font-size: 12px;
            margin-bottom: 0;
        }
        
        .table th {
            background-color: #2c3e50;
            color: white;
            border: 1px solid #dee2e6;
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            padding: 8px 6px;
        }
        
        .table td {
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
            padding: 6px 4px;
        }
        
        .officer-header {
            background-color: #34495e !important;
            color: white;
            font-weight: bold;
        }
        
        .total-row {
            background-color: #e8f4fd;
            font-weight: bold;
        }
        
        .branch-total {
            background-color: #d4edda;
            font-weight: bold;
        }
        
        .par-ratio-row {
            background-color: #fff3cd;
            font-weight: bold;
        }
        
        .category-day-0 { background-color: #d4edda; }
        .category-0-6 { background-color: #cce7ff; }
        .category-7-30 { background-color: #fff3cd; }
        .category-31-60 { background-color: #ffeaa7; }
        .category-61-90 { background-color: #ffd8a8; }
        .category-91-120 { background-color: #ffb8a8; }
        .category-121-150 { background-color: #ff9aa8; }
        .category-151-180 { background-color: #ff7ba8; }
        .category-181-365 { background-color: #e83e8c; color: white; }
        .category-365-plus { background-color: #dc3545; color: white; }
        
        .page-title {
            color: #2c3e50;
            font-weight: 700;
        }
        
        .customer-details {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        
        .badge-overdue {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
        }
        
        .view-buttons .btn {
            border-radius: 20px;
            margin: 0 2px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container-fluid py-3">
            
            <!-- Page Header -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="page-title">PAR Movement Report</h1>
                            <p class="text-muted">Summary & Detailed View - As of <?php echo date('F d, Y', strtotime($end_date)); ?></p>
                        </div>
                        <div class="d-flex gap-2">
                            <div class="view-buttons">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'summary'])); ?>" 
                                   class="btn <?php echo $view_type == 'summary' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                    <i class="fas fa-table me-1"></i>Summary
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'details'])); ?>" 
                                   class="btn <?php echo $view_type == 'details' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                    <i class="fas fa-list me-1"></i>Details
                                </a>
                            </div>
                            <button class="btn btn-success" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-1"></i>Export Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Report Filters
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-2">
                                <input type="hidden" name="view" value="<?php echo $view_type; ?>">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">End Date</label>
                                    <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Center</label>
                                    <select class="form-select" name="center">
                                        <option value="">All Centers</option>
                                        <?php 
                                        if ($centers_result) {
                                            while ($center = $centers_result->fetch_assoc()): ?>
                                                <option value="<?php echo $center['id']; ?>" <?php echo $center_filter == $center['id'] ? 'selected' : ''; ?>>
                                                    <?php echo $center['name']; ?>
                                                </option>
                                            <?php endwhile;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-sync-alt me-1"></i>Generate
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="setToday()">
                                        <i class="fas fa-calendar-day me-1"></i>Today
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($view_type == 'summary'): ?>
            <!-- SUMMARY VIEW -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-table me-2"></i>PAR Movement Analysis - Summary View
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0" id="parMovementTable">
                                    <thead>
                                        <tr>
                                            <th rowspan="3" class="text-center align-middle">Overdue Category</th>
                                            <th colspan="2" class="text-center align-middle">BRANCH TOTAL</th>
                                            <?php foreach ($officers as $officer_id => $officer): ?>
                                            <th colspan="2" class="text-center align-middle officer-header">
                                                <?php echo $officer['full_name']; ?>
                                            </th>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr>
                                            <th class="text-center">NO</th>
                                            <th class="text-center">OUTSTANDING</th>
                                            <?php foreach ($officers as $officer_id => $officer): ?>
                                            <th class="text-center">NO</th>
                                            <th class="text-center">OUTSTANDING</th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $category_order = [
                                            'Day 0', 'Days 0-6.99', 'Days 7-30.99', 'Days 31-60.99', 
                                            'Days 61-90.99', 'Days 91-120.99', 'Days 121-150.99', 
                                            'Days 151-180.99', 'Days 181-365.99', 'Days > 365'
                                        ];
                                        
                                        foreach ($category_order as $category) {
                                            $row_class = '';
                                            if ($category === 'Day 0') $row_class = 'category-day-0';
                                            elseif ($category === 'Days 0-6.99') $row_class = 'category-0-6';
                                            elseif ($category === 'Days 7-30.99') $row_class = 'category-7-30';
                                            elseif ($category === 'Days 31-60.99') $row_class = 'category-31-60';
                                            elseif ($category === 'Days 61-90.99') $row_class = 'category-61-90';
                                            elseif ($category === 'Days 91-120.99') $row_class = 'category-91-120';
                                            elseif ($category === 'Days 121-150.99') $row_class = 'category-121-150';
                                            elseif ($category === 'Days 151-180.99') $row_class = 'category-151-180';
                                            elseif ($category === 'Days 181-365.99') $row_class = 'category-181-365';
                                            elseif ($category === 'Days > 365') $row_class = 'category-365-plus';
                                            
                                            echo "<tr class='{$row_class}'>";
                                            echo "<td class='fw-bold text-start'>{$category}</td>";
                                            
                                            // Branch Total
                                            echo "<td class='text-center'>" . $branch_totals[$category]['loan_count'] . "</td>";
                                            echo "<td class='text-end'>" . number_format($branch_totals[$category]['outstanding_amount'], 2) . "</td>";
                                            
                                            // Officer Data
                                            foreach ($officers as $officer_id => $officer) {
                                                $loan_count = $officer_par_data[$officer_id][$category]['loan_count'];
                                                $outstanding = $officer_par_data[$officer_id][$category]['outstanding_amount'];
                                                
                                                echo "<td class='text-center'>";
                                                if ($loan_count > 0) {
                                                    echo "<a href='?view=details&end_date={$end_date}&center={$center_filter}&officer={$officer_id}&category=" . urlencode($category) . "' class='text-decoration-none'>";
                                                    echo $loan_count;
                                                    echo "</a>";
                                                } else {
                                                    echo $loan_count;
                                                }
                                                echo "</td>";
                                                echo "<td class='text-end'>" . number_format($outstanding, 2) . "</td>";
                                            }
                                            
                                            echo "</tr>";
                                        }
                                        ?>
                                        
                                        <!-- Empty row for spacing -->
                                        <tr><td colspan="<?php echo 2 + (count($officers) * 2); ?>" style="height: 5px; background-color: #f8f9fa;"></td></tr>
                                        
                                        <!-- TOTAL Row -->
                                        <tr class="total-row">
                                            <td class="fw-bold text-start">TOTAL</td>
                                            <td class="text-center fw-bold"><?php echo $grand_totals['total_loans']; ?></td>
                                            <td class="text-end fw-bold"><?php echo number_format($grand_totals['total_outstanding'], 2); ?></td>
                                            <?php 
                                            foreach ($officers as $officer_id => $officer) {
                                                $officer_total_loans = 0;
                                                $officer_total_outstanding = 0;
                                                foreach ($officer_par_data[$officer_id] as $category_data) {
                                                    $officer_total_loans += $category_data['loan_count'];
                                                    $officer_total_outstanding += $category_data['outstanding_amount'];
                                                }
                                                echo "<td class='text-center fw-bold'>{$officer_total_loans}</td>";
                                                echo "<td class='text-end fw-bold'>" . number_format($officer_total_outstanding, 2) . "</td>";
                                            }
                                            ?>
                                        </tr>
                                        
                                        <!-- PAR MOVE Row -->
                                        <tr class="branch-total">
                                            <td class="fw-bold text-start">PAR MOVE</td>
                                            <td class="text-center fw-bold"><?php echo $grand_totals['par_move_loans']; ?></td>
                                            <td class="text-end fw-bold"><?php echo number_format($grand_totals['par_move_outstanding'], 2); ?></td>
                                            <?php 
                                            foreach ($officers as $officer_id => $officer) {
                                                $officer_par_move_loans = 0;
                                                $officer_par_move_outstanding = 0;
                                                foreach ($officer_par_data[$officer_id] as $category => $category_data) {
                                                    if ($category !== 'Day 0' && $category !== 'Days 0-6.99') {
                                                        $officer_par_move_loans += $category_data['loan_count'];
                                                        $officer_par_move_outstanding += $category_data['outstanding_amount'];
                                                    }
                                                }
                                                echo "<td class='text-center fw-bold'>{$officer_par_move_loans}</td>";
                                                echo "<td class='text-end fw-bold'>" . number_format($officer_par_move_outstanding, 2) . "</td>";
                                            }
                                            ?>
                                        </tr>
                                        
                                        <!-- PAR RATIO Row -->
                                        <tr class="par-ratio-row">
                                            <td class="fw-bold text-start">PAR RATIO</td>
                                            <td colspan="2" class="text-center fw-bold">
                                                <?php 
                                                $branch_par_ratio = ($grand_totals['total_outstanding'] > 0) ? 
                                                    ($grand_totals['par_move_outstanding'] / $grand_totals['total_outstanding']) * 100 : 0;
                                                echo number_format($branch_par_ratio, 2) . "%";
                                                ?>
                                            </td>
                                            <?php 
                                            foreach ($officers as $officer_id => $officer) {
                                                $officer_total_outstanding = 0;
                                                $officer_par_move_outstanding = 0;
                                                
                                                foreach ($officer_par_data[$officer_id] as $category => $category_data) {
                                                    $officer_total_outstanding += $category_data['outstanding_amount'];
                                                    
                                                    if ($category !== 'Day 0' && $category !== 'Days 0-6.99') {
                                                        $officer_par_move_outstanding += $category_data['outstanding_amount'];
                                                    }
                                                }
                                                
                                                $par_ratio = ($officer_total_outstanding > 0) ? 
                                                    ($officer_par_move_outstanding / $officer_total_outstanding) * 100 : 0;
                                                
                                                echo "<td colspan='2' class='text-center fw-bold'>" . number_format($par_ratio, 2) . "%</td>";
                                            }
                                            ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- DETAILED VIEW -->
            <div class="row">
                <div class="col-12">
                    <?php
                    $selected_officer = isset($_GET['officer']) ? $_GET['officer'] : '';
                    $selected_category = isset($_GET['category']) ? $_GET['category'] : '';
                    
                    if ($selected_officer && $selected_category) {
                        // Show specific officer and category details
                        $officer_name = $officers[$selected_officer]['full_name'];
                        $category_name = $selected_category;
                        $customers = $officer_customer_details[$selected_officer][$selected_category] ?? [];
                        ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>
                                    Customer Details: <?php echo $officer_name; ?> - <?php echo $category_name; ?>
                                    <span class="badge bg-secondary ms-2"><?php echo count($customers); ?> Loans</span>
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (count($customers) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Customer Details</th>
                                                <th>Loan Details</th>
                                                <th>Center</th>
                                                <th>Overdue Days</th>
                                                <th>Outstanding</th>
                                                <th>Overdue Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $counter = 1;
                                            $total_outstanding = 0;
                                            $total_overdue = 0;
                                            
                                            foreach ($customers as $customer): 
                                                $total_outstanding += $customer['outstanding_amount'];
                                                $total_overdue += $customer['overdue_amount'];
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $counter++; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo $customer['customer_name']; ?></div>
                                                    <div class="small text-muted">NIC: <?php echo $customer['national_id']; ?></div>
                                                    <div class="small text-muted">Phone: <?php echo $customer['phone']; ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold">Loan #: <?php echo $customer['loan_number']; ?></div>
                                                <div class="small">Original: Rs. <?php echo number_format($customer['outstanding_amount'] + $customer['overdue_amount'], 2); ?></div>
                                                </td>
                                                <td><?php echo $customer['center_name']; ?></td>
                                                <td class="text-center fw-bold">
                                                    <?php echo $customer['max_overdue_days'] > 0 ? $customer['max_overdue_days'] : '0'; ?>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    Rs. <?php echo number_format($customer['outstanding_amount'], 2); ?>
                                                </td>
                                                <td class="text-end fw-bold text-danger">
                                                    Rs. <?php echo number_format($customer['overdue_amount'], 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Totals Row -->
                                            <tr class="table-secondary fw-bold">
                                                <td colspan="5" class="text-end">TOTALS:</td>
                                                <td class="text-end">Rs. <?php echo number_format($total_outstanding, 2); ?></td>
                                                <td class="text-end">Rs. <?php echo number_format($total_overdue, 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No customers found</h5>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <a href="?view=details&end_date=<?php echo $end_date; ?>&center=<?php echo $center_filter; ?>" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Back to All Details
                                </a>
                            </div>
                        </div>
                        <?php
                    } else {
                        // Show all details
                        ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>PAR Movement Analysis - Detailed View
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($officers as $officer_id => $officer): ?>
                                <div class="officer-section">
                                    <div class="bg-light p-3 border-bottom">
                                        <h6 class="mb-0">
                                            <i class="fas fa-user me-2"></i>
                                            <?php echo $officer['full_name']; ?>
                                            <?php
                                            $officer_total_loans = 0;
                                            foreach ($officer_par_data[$officer_id] as $category_data) {
                                                $officer_total_loans += $category_data['loan_count'];
                                            }
                                            ?>
                                            <span class="badge bg-primary ms-2"><?php echo $officer_total_loans; ?> Loans</span>
                                        </h6>
                                    </div>
                                    
                                    <?php 
                                    $category_order = [
                                        'Day 0', 'Days 0-6.99', 'Days 7-30.99', 'Days 31-60.99', 
                                        'Days 61-90.99', 'Days 91-120.99', 'Days 121-150.99', 
                                        'Days 151-180.99', 'Days 181-365.99', 'Days > 365'
                                    ];
                                    
                                    foreach ($category_order as $category): 
                                        $customers = $officer_customer_details[$officer_id][$category] ?? [];
                                        if (count($customers) > 0):
                                    ?>
                                    <div class="category-section customer-details p-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0 text-primary">
                                                <?php echo $category; ?>
                                                <span class="badge bg-secondary ms-2"><?php echo count($customers); ?> Loans</span>
                                            </h6>
                                            <small class="text-muted">
                                                Total Outstanding: Rs. <?php echo number_format($officer_par_data[$officer_id][$category]['outstanding_amount'], 2); ?>
                                            </small>
                                        </div>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Customer Name</th>
                                                        <th>Loan #</th>
                                                        <th>Center</th>
                                                        <th>Overdue Days</th>
                                                        <th>Outstanding</th>
                                                        <th>Overdue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $counter = 1;
                                                    foreach ($customers as $customer): 
                                                    ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $counter++; ?></td>
                                                        <td>
                                                            <div class="fw-bold"><?php echo $customer['customer_name']; ?></div>
                                                            <div class="small text-muted"><?php echo $customer['national_id']; ?></div>
                                                        </td>
                                                        <td><?php echo $customer['loan_number']; ?></td>
                                                        <td><?php echo $customer['center_name']; ?></td>
                                                        <td class="text-center"><?php echo $customer['max_overdue_days']; ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format($customer['outstanding_amount'], 2); ?></td>
                                                        <td class="text-end">Rs. <?php echo number_format($customer['overdue_amount'], 2); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function exportToExcel() {
        let table = document.getElementById('parMovementTable');
        let html = table.outerHTML;
        let url = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        let downloadLink = document.createElement('a');
        downloadLink.href = url;
        downloadLink.download = 'PAR_Report_<?php echo $view_type; ?>_<?php echo date('Y_m_d'); ?>.xls';
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }

    function setToday() {
        const today = new Date().toISOString().split('T')[0];
        document.querySelector('input[name="end_date"]').value = today;
    }
    </script>
</body>
</html>