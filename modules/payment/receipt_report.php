<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Get CBOs for filter
$cbos = getAllCBOs();

// Get Staff members for filter
$staff_sql = "SELECT s.id, s.full_name 
              FROM staff s 
              WHERE s.id IN (SELECT DISTINCT staff_id FROM cbo WHERE staff_id IS NOT NULL)
              ORDER BY s.full_name";
$staff_result = $conn->query($staff_sql);

// Get filter parameters
$cbo_id = $_GET['cbo_id'] ?? '';
$loan_number = $_GET['loan_number'] ?? '';
$customer_nic = $_GET['customer_nic'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$payment_ref = $_GET['payment_ref'] ?? '';
$staff_id = $_GET['staff_id'] ?? '';

// Check if PDF export is requested
$export_pdf = isset($_GET['export']) && $_GET['export'] == 'pdf';

// Get receipt data for report
$receipts = [];
$total_amount = 0;
$cbo_name = 'All CBOs';
$staff_name = 'All Staff';

// Get totals by staff and CBO
$staff_totals = [];
$cbo_totals = [];
$daily_totals = [];

$sql = "SELECT 
            lp.id as payment_id,
            lp.payment_date,
            lp.amount,
            lp.payment_reference,
            lp.payment_method,
            lp.notes,
            l.loan_number,
            c.full_name as customer_name,
            c.national_id,
            c.address,
            cb.id as cbo_id,
            cb.name as cbo_name,
            cb.staff_id as cbo_staff_id,
            g.group_number,
            s.full_name as staff_name,
            li.installment_number,
            li.amount as installment_amount,
            li.due_date,
            li.status as installment_status
        FROM loan_payments lp
        JOIN loans l ON lp.loan_id = l.id
        JOIN customers c ON l.customer_id = c.id
        JOIN cbo cb ON l.cbo_id = cb.id
        LEFT JOIN group_members gm ON c.id = gm.customer_id
        LEFT JOIN groups g ON gm.group_id = g.id
        LEFT JOIN staff s ON cb.staff_id = s.id
        LEFT JOIN loan_installments li ON lp.installment_id = li.id
        WHERE 1=1";
    
$params = [];
$types = '';

if ($cbo_id) {
    $sql .= " AND l.cbo_id = ?";
    $params[] = $cbo_id;
    $types .= 'i';
    
    // Get CBO name
    $cbo_sql = "SELECT name FROM cbo WHERE id = ?";
    $cbo_stmt = $conn->prepare($cbo_sql);
    $cbo_stmt->bind_param("i", $cbo_id);
    $cbo_stmt->execute();
    $cbo_result = $cbo_stmt->get_result();
    if ($cbo_data = $cbo_result->fetch_assoc()) {
        $cbo_name = $cbo_data['name'];
    }
}

if ($loan_number) {
    $sql .= " AND l.loan_number LIKE ?";
    $params[] = "%$loan_number%";
    $types .= 's';
}

if ($customer_nic) {
    $sql .= " AND c.national_id LIKE ?";
    $params[] = "%$customer_nic%";
    $types .= 's';
}

if ($start_date && $end_date) {
    $sql .= " AND lp.payment_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

if ($payment_ref) {
    $sql .= " AND lp.payment_reference LIKE ?";
    $params[] = "%$payment_ref%";
    $types .= 's';
}

if ($staff_id) {
    $sql .= " AND cb.staff_id = ?";
    $params[] = $staff_id;
    $types .= 'i';
    
    // Get Staff name and their CBOs
    $staff_sql = "SELECT s.full_name, 
                         GROUP_CONCAT(cb.name SEPARATOR ', ') as cbo_names,
                         COUNT(cb.id) as cbo_count
                  FROM staff s 
                  LEFT JOIN cbo cb ON s.id = cb.staff_id 
                  WHERE s.id = ?";
    $staff_stmt = $conn->prepare($staff_sql);
    $staff_stmt->bind_param("i", $staff_id);
    $staff_stmt->execute();
    $staff_result_name = $staff_stmt->get_result();
    if ($staff_data = $staff_result_name->fetch_assoc()) {
        $staff_name = $staff_data['full_name'];
        $staff_cbo_names = $staff_data['cbo_names'];
        $staff_cbo_count = $staff_data['cbo_count'];
    }
}

$sql .= " ORDER BY lp.payment_date DESC, lp.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Generate receipt numbers
$receipt_counter = 1;
while ($receipt = $result->fetch_assoc()) {
    // Generate receipt number based on payment ID and date
    $receipt['receipt_number'] = 'RCP' . date('Ymd', strtotime($receipt['payment_date'])) . str_pad($receipt['payment_id'], 4, '0', STR_PAD_LEFT);
    
    $receipts[] = $receipt;
    $total_amount += $receipt['amount'];
    
    // Calculate totals by staff
    $staff_id = $receipt['cbo_staff_id'];
    $staff_name_val = $receipt['staff_name'];
    
    if (!isset($staff_totals[$staff_id])) {
        $staff_totals[$staff_id] = [
            'staff_name' => $staff_name_val,
            'total_amount' => 0,
            'receipt_count' => 0
        ];
    }
    $staff_totals[$staff_id]['total_amount'] += $receipt['amount'];
    $staff_totals[$staff_id]['receipt_count']++;
    
    // Calculate totals by CBO
    $cbo_id = $receipt['cbo_id'];
    $cbo_name_val = $receipt['cbo_name'];
    
    if (!isset($cbo_totals[$cbo_id])) {
        $cbo_totals[$cbo_id] = [
            'cbo_name' => $cbo_name_val,
            'total_amount' => 0,
            'receipt_count' => 0
        ];
    }
    $cbo_totals[$cbo_id]['total_amount'] += $receipt['amount'];
    $cbo_totals[$cbo_id]['receipt_count']++;
    
    // Calculate daily totals
    $payment_date = $receipt['payment_date'];
    if (!isset($daily_totals[$payment_date])) {
        $daily_totals[$payment_date] = [
            'amount' => 0,
            'count' => 0
        ];
    }
    $daily_totals[$payment_date]['amount'] += $receipt['amount'];
    $daily_totals[$payment_date]['count']++;
    
    $receipt_counter++;
}

// Sort daily totals by date
ksort($daily_totals);

// PDF Export
if ($export_pdf) {
    require_once '../../vendor/autoload.php';
    
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Micro Finance System');
    $pdf->SetAuthor('Micro Finance System');
    $pdf->SetTitle('Receipt Report');
    $pdf->SetSubject('Receipt Report');
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Micro Finance System - Receipt Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'CBO: ' . $cbo_name, 0, 1, 'C');
    $pdf->Cell(0, 10, 'Staff: ' . $staff_name, 0, 1, 'C');
    $pdf->Cell(0, 10, 'Period: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y g:i A'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Add table header
    $pdf->SetFont('helvetica', 'B', 9);
    $header = array('Receipt No', 'Date', 'Customer', 'NIC', 'Loan No', 'Amount', 'Method');
    $w = array(25, 20, 40, 25, 25, 25, 20);
    
    for($i=0; $i<count($header); $i++)
        $pdf->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
    $pdf->Ln();
    
    // Add table data
    $pdf->SetFont('helvetica', '', 8);
    foreach ($receipts as $receipt) {
        $pdf->Cell($w[0], 6, $receipt['receipt_number'], 'LR', 0, 'C');
        $pdf->Cell($w[1], 6, date('Y-m-d', strtotime($receipt['payment_date'])), 'LR', 0, 'C');
        $pdf->Cell($w[2], 6, substr($receipt['customer_name'], 0, 18), 'LR', 0, 'L');
        $pdf->Cell($w[3], 6, $receipt['national_id'], 'LR', 0, 'C');
        $pdf->Cell($w[4], 6, $receipt['loan_number'], 'LR', 0, 'C');
        $pdf->Cell($w[5], 6, number_format($receipt['amount'], 2), 'LR', 0, 'R');
        $pdf->Cell($w[6], 6, substr($receipt['payment_method'] ?? 'Cash', 0, 8), 'LR', 0, 'C');
        $pdf->Ln();
    }
    
    // Closing line
    $pdf->Cell(array_sum($w), 0, '', 'T');
    $pdf->Ln(10);
    
    // Total
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Total Receipts: ' . count($receipts) . ' | Total Amount: Rs. ' . number_format($total_amount, 2), 0, 1, 'R');
    
    // Output PDF
    $pdf->Output('receipt_report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Report - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            .container { max-width: 100% !important; }
            .table { font-size: 11px; }
            .receipt-header { border-bottom: 2px solid #333; margin-bottom: 15px; }
            .total-row { background-color: #f8f9fa; font-weight: bold; }
            .btn { display: none !important; }
            .receipt-card { border: 1px solid #000; margin-bottom: 10px; page-break-inside: avoid; }
        }
        .receipt-header { border-bottom: 2px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
        .total-row { background-color: #f8f9fa; font-weight: bold; }
        .receipt-card { 
            border: 1px solid #dee2e6; 
            border-radius: 8px; 
            margin-bottom: 15px; 
            padding: 15px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .receipt-number { 
            background: #007bff; 
            color: white; 
            padding: 5px 10px; 
            border-radius: 4px;
            font-weight: bold;
        }
        .company-info { text-align: center; margin-bottom: 20px; }
        .filter-summary { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .summary-card { background-color: #e9ecef; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .staff-total { background-color: #d4edda; padding: 12px; border-radius: 5px; margin-bottom: 10px; }
        .cbo-total { background-color: #cce7ff; padding: 10px; border-radius: 5px; margin-bottom: 8px; }
        .daily-total { background-color: #fff3cd; padding: 8px; border-radius: 5px; margin-bottom: 5px; }
        .receipt-amount { 
            font-size: 1.2em; 
            font-weight: bold; 
            color: #28a745; 
            text-align: right;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" style="margin-top: 80px;">
        <div class="container-fluid">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-receipt"></i> Receipt Reports
                </h1>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4 no-print">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i> Filter Receipts
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="cbo_id" class="form-label">CBO</label>
                            <select class="form-select" id="cbo_id" name="cbo_id">
                                <option value="">All CBOs</option>
                                <?php while ($cbo = $cbos->fetch_assoc()): ?>
                                    <option value="<?php echo $cbo['id']; ?>" 
                                        <?php echo $cbo_id == $cbo['id'] ? 'selected' : ''; ?>>
                                        <?php echo $cbo['name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="staff_id" class="form-label">CBO Staff Member</label>
                            <select class="form-select" id="staff_id" name="staff_id">
                                <option value="">All Staff</option>
                                <?php 
                                $staff_result->data_seek(0);
                                while ($staff = $staff_result->fetch_assoc()): ?>
                                    <option value="<?php echo $staff['id']; ?>" 
                                        <?php echo $staff_id == $staff['id'] ? 'selected' : ''; ?>>
                                        <?php echo $staff['full_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="loan_number" class="form-label">Loan Number</label>
                            <input type="text" class="form-control" id="loan_number" name="loan_number" 
                                   value="<?php echo htmlspecialchars($loan_number); ?>" 
                                   placeholder="Loan number...">
                        </div>
                        
                        <div class="col-md-2">
                            <label for="customer_nic" class="form-label">Customer NIC</label>
                            <input type="text" class="form-control" id="customer_nic" name="customer_nic" 
                                   value="<?php echo htmlspecialchars($customer_nic); ?>" 
                                   placeholder="NIC number...">
                        </div>
                        
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo $start_date; ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo $end_date; ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="payment_ref" class="form-label">Payment Reference</label>
                            <input type="text" class="form-control" id="payment_ref" name="payment_ref" 
                                   value="<?php echo htmlspecialchars($payment_ref); ?>" 
                                   placeholder="Search by reference...">
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Generate Report
                            </button>
                            <a href="receipt_report.php" class="btn btn-secondary">Clear Filters</a>
                            
                            <?php if (!empty($receipts)): ?>
                            <div class="btn-group float-end">
                                <button type="button" onclick="window.print()" class="btn btn-outline-primary">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <a href="receipt_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                                   class="btn btn-outline-success">
                                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Filter Summary -->
            <?php if (!empty($receipts)): ?>
            <div class="filter-summary no-print">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Report Summary</h6>
                        <p class="mb-1"><strong>CBO:</strong> <?php echo $cbo_name; ?></p>
                        <p class="mb-1"><strong>Staff:</strong> <?php echo $staff_name; ?></p>
                        <?php if (isset($staff_cbo_names) && $staff_cbo_names): ?>
                        <p class="mb-1"><strong>Assigned CBOs:</strong> <?php echo $staff_cbo_names; ?></p>
                        <?php endif; ?>
                        <p class="mb-1"><strong>Period:</strong> <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></p>
                        <p class="mb-1"><strong>Total Receipts:</strong> <?php echo count($receipts); ?></p>
                        <p class="mb-0"><strong>Total Amount:</strong> Rs. <?php echo number_format($total_amount, 2); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-1"><strong>Generated on:</strong> <?php echo date('F j, Y g:i A'); ?></p>
                        <p class="mb-0"><strong>Generated by:</strong> <?php echo $_SESSION['user_name']; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Staff-wise Summary -->
            <?php if (!empty($staff_totals)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person-check"></i> Staff-wise Receipt Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($staff_totals as $staff_id => $staff_data): ?>
                        <div class="col-md-6 mb-3">
                            <div class="staff-total">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo $staff_data['staff_name']; ?></h6>
                                        <small class="text-muted">Receipts: <?php echo $staff_data['receipt_count']; ?></small>
                                    </div>
                                    <span class="badge bg-primary fs-6">Rs. <?php echo number_format($staff_data['total_amount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- CBO-wise Summary -->
            <?php if (!empty($cbo_totals)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-building"></i> CBO-wise Receipt Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($cbo_totals as $cbo_id => $cbo_data): ?>
                        <div class="col-md-4 mb-3">
                            <div class="cbo-total">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo $cbo_data['cbo_name']; ?></h6>
                                        <small class="text-muted">Receipts: <?php echo $cbo_data['receipt_count']; ?></small>
                                    </div>
                                    <span class="badge bg-success fs-6">Rs. <?php echo number_format($cbo_data['total_amount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Daily Totals -->
            <?php if (!empty($daily_totals)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calendar-check"></i> Daily Receipt Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($daily_totals as $date => $daily_data): ?>
                        <div class="col-md-3 mb-2">
                            <div class="daily-total">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted"><?php echo date('Y-m-d', strtotime($date)); ?></small>
                                        <br>
                                        <small>Receipts: <?php echo $daily_data['count']; ?></small>
                                    </div>
                                    <strong class="text-success">Rs. <?php echo number_format($daily_data['amount'], 2); ?></strong>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Receipt Cards -->
            <div class="card">
                <div class="card-header no-print">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-receipt"></i> Receipt Details
                        <?php if (!empty($receipts)): ?>
                            - Total: <span class="text-success"><?php echo count($receipts); ?> Receipts | Rs. <?php echo number_format($total_amount, 2); ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($receipts)): ?>
                        <div class="row">
                            <?php foreach ($receipts as $receipt): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="receipt-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <span class="receipt-number">
                                            <i class="bi bi-receipt"></i> <?php echo $receipt['receipt_number']; ?>
                                        </span>
                                        <small class="text-muted"><?php echo date('Y-m-d', strtotime($receipt['payment_date'])); ?></small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <strong><?php echo htmlspecialchars($receipt['customer_name']); ?></strong>
                                    </div>
                                    
                                    <div class="row small text-muted mb-2">
                                        <div class="col-6">
                                            <i class="bi bi-credit-card"></i> <?php echo $receipt['loan_number']; ?>
                                        </div>
                                        <div class="col-6">
                                            <i class="bi bi-person-badge"></i> <?php echo $receipt['national_id']; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row small text-muted mb-3">
                                        <div class="col-6">
                                            <i class="bi bi-building"></i> <?php echo $receipt['cbo_name']; ?>
                                        </div>
                                        <div class="col-6">
                                            <i class="bi bi-person"></i> <?php echo $receipt['staff_name'] ?? 'Not Assigned'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="receipt-amount">
                                        Rs. <?php echo number_format($receipt['amount'], 2); ?>
                                    </div>
                                    
                                    <div class="row small text-muted mt-2">
                                        <div class="col-6">
                                            Method: <?php echo $receipt['payment_method'] ?? 'Cash'; ?>
                                        </div>
                                        <div class="col-6 text-end">
                                            Ref: <?php echo $receipt['payment_reference'] ?? 'N/A'; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($receipt['installment_number']): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-info">Installment #<?php echo $receipt['installment_number']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($cbo_id || $loan_number || $customer_nic || $staff_id): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-receipt display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No receipts found</h5>
                            <p class="text-muted">No receipt records match your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-search display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">Generate Receipt Report</h5>
                            <p class="text-muted">Use the filters above to generate a receipt report.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Print and Export Buttons -->
            <?php if (!empty($receipts)): ?>
            <div class="no-print mt-4 text-center">
                <div class="btn-group">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <a href="receipt_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                       class="btn btn-success">
                        <i class="bi bi-file-earmark-pdf"></i> Export as PDF
                    </a>
                    <a href="receipt_report.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reports
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit form when dates change for quick filtering
        document.getElementById('start_date').addEventListener('change', function() {
            if (this.value && document.getElementById('end_date').value) {
                this.form.submit();
            }
        });
        
        document.getElementById('end_date').addEventListener('change', function() {
            if (this.value && document.getElementById('start_date').value) {
                this.form.submit();
            }
        });
        
        // Auto-submit when CBO is selected
        document.getElementById('cbo_id').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });

        // Auto-submit when Staff is selected
        document.getElementById('staff_id').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });
    </script>
</body>
</html>