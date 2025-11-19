<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

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

// Get CBOs for filter
$cbos = getAllCBOs();

// Get Staff members for filter - CBO staff only
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

// Get payment data for report
$payments = [];
$total_amount = 0;
$cbo_name = 'All CBOs';
$staff_name = 'All Staff';

// Get totals by staff and CBO
$staff_totals = [];
$cbo_totals = [];
$daily_totals = [];

$sql = "SELECT lp.*, l.loan_number, c.full_name, c.national_id, 
               cb.id as cbo_id, cb.name as cbo_name, cb.staff_id as cbo_staff_id,
               g.group_number,
               s.full_name as staff_name,
               li.installment_number, li.amount as installment_amount,
               li.paid_amount, li.status as installment_status
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

$sql .= " ORDER BY cb.name, lp.payment_date DESC, c.full_name";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($payment = $result->fetch_assoc()) {
    $payments[] = $payment;
    $total_amount += $payment['amount'];
    
    // Calculate totals by staff
    $staff_id = $payment['cbo_staff_id'];
    $staff_name = $payment['staff_name'];
    
    if (!isset($staff_totals[$staff_id])) {
        $staff_totals[$staff_id] = [
            'staff_name' => $staff_name,
            'total_amount' => 0,
            'cbo_totals' => []
        ];
    }
    $staff_totals[$staff_id]['total_amount'] += $payment['amount'];
    
    // Calculate totals by CBO
    $cbo_id = $payment['cbo_id'];
    $cbo_name = $payment['cbo_name'];
    
    if (!isset($staff_totals[$staff_id]['cbo_totals'][$cbo_id])) {
        $staff_totals[$staff_id]['cbo_totals'][$cbo_id] = [
            'cbo_name' => $cbo_name,
            'total_amount' => 0
        ];
    }
    $staff_totals[$staff_id]['cbo_totals'][$cbo_id]['total_amount'] += $payment['amount'];
    
    // Calculate daily totals
    $payment_date = $payment['payment_date'];
    if (!isset($daily_totals[$payment_date])) {
        $daily_totals[$payment_date] = 0;
    }
    $daily_totals[$payment_date] += $payment['amount'];
}

// Sort daily totals by date
ksort($daily_totals);

// PDF Export
if ($export_pdf) {
    require_once '../../vendor/autoload.php'; // TCPDF library path
    
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Micro Finance System');
    $pdf->SetAuthor('Micro Finance System');
    $pdf->SetTitle('Payment Report');
    $pdf->SetSubject('Payment Report');
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Micro Finance System - Payment Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'CBO: ' . $cbo_name, 0, 1, 'C');
    $pdf->Cell(0, 10, 'Staff: ' . $staff_name, 0, 1, 'C');
    $pdf->Cell(0, 10, 'Period: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y g:i A'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Add table header
    $pdf->SetFont('helvetica', 'B', 10);
    $header = array('Date', 'Reference', 'Customer', 'NIC', 'Loan No', 'Amount');
    $w = array(25, 30, 45, 30, 25, 25);
    
    for($i=0; $i<count($header); $i++)
        $pdf->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
    $pdf->Ln();
    
    // Add table data
    $pdf->SetFont('helvetica', '', 9);
    foreach ($payments as $payment) {
        $pdf->Cell($w[0], 6, date('Y-m-d', strtotime($payment['payment_date'])), 'LR', 0, 'C');
        $pdf->Cell($w[1], 6, substr($payment['payment_reference'] ?? 'N/A', 0, 8), 'LR', 0, 'C');
        $pdf->Cell($w[2], 6, substr($payment['full_name'], 0, 20), 'LR', 0, 'L');
        $pdf->Cell($w[3], 6, $payment['national_id'], 'LR', 0, 'C');
        $pdf->Cell($w[4], 6, $payment['loan_number'], 'LR', 0, 'C');
        $pdf->Cell($w[5], 6, number_format($payment['amount'], 2), 'LR', 0, 'R');
        $pdf->Ln();
    }
    
    // Closing line
    $pdf->Cell(array_sum($w), 0, '', 'T');
    $pdf->Ln(10);
    
    // Total
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Total Amount: Rs. ' . number_format($total_amount, 2), 0, 1, 'R');
    
    // Output PDF
    $pdf->Output('payment_report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Report - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            .container { max-width: 100% !important; }
            .table { font-size: 12px; }
            .report-header { border-bottom: 2px solid #333; margin-bottom: 20px; }
            .total-row { background-color: #f8f9fa; font-weight: bold; }
            .btn { display: none !important; }
        }
        .report-header { border-bottom: 2px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
        .total-row { background-color: #f8f9fa; font-weight: bold; }
        .company-info { text-align: center; margin-bottom: 20px; }
        .filter-summary { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .summary-card { background-color: #e9ecef; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .staff-total { background-color: #d4edda; padding: 15px; border-radius: 5px; margin-bottom: 15px; }
        .cbo-total { background-color: #cce7ff; padding: 10px; border-radius: 5px; margin-bottom: 10px; margin-left: 20px; }
        .daily-total { background-color: #fff3cd; padding: 8px; border-radius: 5px; margin-bottom: 5px; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" style="margin-top: 80px;">
        <div class="container-fluid">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-printer"></i> Payment Reports
                </h1>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4 no-print">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i> Filter Payments
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
                                $staff_result->data_seek(0); // Reset pointer
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
                            <a href="payment_report.php" class="btn btn-secondary">Clear Filters</a>
                            
                            <?php if (!empty($payments)): ?>
                            <div class="btn-group float-end">
                                <button type="button" onclick="window.print()" class="btn btn-outline-primary">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <a href="payment_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
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
            <?php if (!empty($payments)): ?>
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
                        <p class="mb-1"><strong>Total Payments:</strong> <?php echo count($payments); ?></p>
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
                        <i class="bi bi-person-check"></i> Staff-wise Collection Summary
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($staff_totals as $staff_id => $staff_data): ?>
                    <div class="staff-total">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><?php echo $staff_data['staff_name']; ?></h6>
                            <span class="badge bg-primary fs-6">Total: Rs. <?php echo number_format($staff_data['total_amount'], 2); ?></span>
                        </div>
                        
                        <?php if (!empty($staff_data['cbo_totals'])): ?>
                        <div class="row">
                            <?php foreach ($staff_data['cbo_totals'] as $cbo_id => $cbo_data): ?>
                            <div class="col-md-6 mb-2">
                                <div class="cbo-total">
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo $cbo_data['cbo_name']; ?></span>
                                        <strong class="text-success">Rs. <?php echo number_format($cbo_data['total_amount'], 2); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Daily Totals -->
            <?php if (!empty($daily_totals)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calendar-check"></i> Daily Collection Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($daily_totals as $date => $amount): ?>
                        <div class="col-md-3 mb-2">
                            <div class="daily-total">
                                <div class="d-flex justify-content-between">
                                    <span><?php echo date('Y-m-d', strtotime($date)); ?></span>
                                    <strong class="text-success">Rs. <?php echo number_format($amount, 2); ?></strong>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Report Data -->
            <div class="card">
                <div class="card-header no-print">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i> Payment Report
                        <?php if (!empty($payments)): ?>
                            - Total: <span class="text-success">Rs. <?php echo number_format($total_amount, 2); ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                
                <div class="card-body p-0">
                    <?php if (!empty($payments)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Customer Name</th>
                                    <th>NIC</th>
                                    <th>Loan Number</th>
                                    <th>CBO</th>
                                    <th>Group</th>
                                    <th>Installment</th>
                                    <th class="text-end">Amount (Rs.)</th>
                                    <th>CBO Staff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo formatDate($payment['payment_date']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $payment['payment_reference'] ?? 'N/A'; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['national_id']); ?></td>
                                    <td><?php echo $payment['loan_number']; ?></td>
                                    <td><?php echo $payment['cbo_name']; ?></td>
                                    <td>
                                        <?php if ($payment['group_number']): ?>
                                            Group <?php echo $payment['group_number']; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($payment['installment_number']): ?>
                                            <span class="badge bg-info">#<?php echo $payment['installment_number']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-success fw-bold">
                                        <?php echo number_format($payment['amount'], 2); ?>
                                    </td>
                                    <td><?php echo $payment['staff_name'] ?? 'Not Assigned'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="8" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                    <td class="text-end"><strong>Rs. <?php echo number_format($total_amount, 2); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($cbo_id || $loan_number || $customer_nic || $staff_id): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-receipt display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No payments found</h5>
                            <p class="text-muted">No payment records match your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-search display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">Generate Payment Report</h5>
                            <p class="text-muted">Use the filters above to generate a payment report.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Print and Export Buttons -->
            <?php if (!empty($payments)): ?>
            <div class="no-print mt-4 text-center">
                <div class="btn-group">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <a href="payment_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                       class="btn btn-success">
                        <i class="bi bi-file-earmark-pdf"></i> Export as PDF
                    </a>
                    <a href="payment_report.php" class="btn btn-secondary">
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