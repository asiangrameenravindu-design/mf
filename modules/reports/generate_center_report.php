<?php
// modules/reports/generate_center_report.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Get report parameters
$cbo_id = isset($_GET['cbo_id']) ? intval($_GET['cbo_id']) : 0;
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$action = isset($_GET['action']) ? $_GET['action'] : 'preview';

if (!$cbo_id) {
    die("Error: CBO ID is required");
}

// Get CBO details
$cbo_details = getCBOById($cbo_id);
if (!$cbo_details) {
    die("Error: CBO not found");
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Center Report - <?php echo htmlspecialchars($cbo_details['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Print styles */
        @media print {
            @page {
                size: A4 landscape;
                margin: 5mm;
            }
            
            body {
                margin: 0 !important;
                padding: 0 !important;
                font-size: 12px;
                -webkit-print-color-adjust: exact;
                width: 297mm !important;
                height: auto !important;
                overflow: visible !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .report-container {
                margin: 0 !important;
                padding: 0 !important;
                width: 287mm !important;
                height: auto !important;
                min-height: 190mm !important;
                max-height: 190mm !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }
            
            .report-table {
                page-break-inside: avoid !important;
            }
        }
        
        /* Screen styles */
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.2;
            background: white;
            margin: 0;
            padding: 10px;
            width: 100%;
            height: auto;
        }
        
        .report-container {
            width: 1280px;
            margin: 0 auto;
            padding: 10px;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #000;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            table-layout: fixed;
        }
        
        .report-table th,
        .report-table td {
            border: 1px solid #000;
            padding: 4px 2px;
            text-align: center;
            vertical-align: middle;
            height: 20px;
        }
        
        .report-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            height: 22px;
            font-size: 10px;
        }
        
        .group-header {
            background-color: #e9ecef !important;
            font-weight: bold;
            text-align: left !important;
            height: 22px !important;
            font-size: 11px !important;
        }
        
        .total-row {
            background-color: #d1ecf1 !important;
            font-weight: bold;
            height: 22px !important;
            font-size: 11px !important;
        }
        
        .center-total {
            background-color: #bee5eb !important;
            font-weight: bold;
            height: 22px !important;
            font-size: 11px !important;
        }
        
        .signature-row {
            background-color: #f8f9fa !important;
            font-weight: bold;
            text-align: left !important;
            height: 22px !important;
            font-size: 11px !important;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-left {
            text-align: left;
        }
        
        .text-center {
            text-align: center;
        }
        
        .print-controls {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            text-align: center;
        }
        
        /* Column widths */
        .col-customer { width: 12%; font-size: 10px; }  
        .col-nic { width: 9%; font-size: 10px; }
        .col-amount { width: 5%; font-size: 9px; }
        .col-balance { width: 5%; font-size: 9px; }
        .col-due { width: 4%; font-size: 9px; }
        .col-arrears { width: 4%; font-size: 9px; }
        .col-week { width: 9%; font-size: 11px; }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <div class="container">
            <button onclick="printReport()" class="btn btn-primary btn-lg">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button onclick="window.close()" class="btn btn-secondary btn-lg">
                <i class="bi bi-x"></i> Close
            </button>
            <a href="center_report.php" class="btn btn-info btn-lg">
                <i class="bi bi-arrow-left"></i> Back to Generator
            </a>
        </div>
    </div>

    <div class="report-container">
        <!-- Main Report Header -->
        <div class="report-header">
            <h2 style="margin: 0; font-size: 16px; font-weight: bold;">
                <?php echo htmlspecialchars($cbo_details['name']); ?> / Center Code - <?php echo htmlspecialchars($cbo_details['cbo_number']); ?>
            </h2>
        </div>

        <!-- Main Report Table -->
        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-customer text-left">Customer<br>Name</th>
                    <th rowspan="2" class="col-nic">Customer<br>NIC</th>
                    <th rowspan="2" class="col-amount">Loan<br>Amount</th>
                    <th rowspan="2" class="col-balance">Loan<br>Balance</th>
                    <th rowspan="2" class="col-due">Weekly<br>Due</th>
                    <th rowspan="2" class="col-arrears">Arrears</th>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <th colspan="2" class="col-week"></th>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <th></th>
                    <th></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                // Get groups for the selected CBO that have disbursed loans
                $group_condition = "";
                $group_params = [$cbo_id];
                $group_types = "i";
                
                if ($group_id) {
                    $group_condition = " AND g.id = ?";
                    $group_params[] = $group_id;
                    $group_types .= "i";
                }
                
                $group_sql = "SELECT DISTINCT g.id, g.group_number, g.group_name 
                             FROM groups g 
                             JOIN group_members gm ON g.id = gm.group_id
                             JOIN customers c ON gm.customer_id = c.id
                             JOIN loans l ON c.id = l.customer_id
                             WHERE g.cbo_id = ? 
                             AND l.status IN ('active', 'disbursed')
                             " . $group_condition . " 
                             ORDER BY g.group_number";
                $group_stmt = $conn->prepare($group_sql);
                $group_stmt->bind_param($group_types, ...$group_params);
                $group_stmt->execute();
                $groups_result = $group_stmt->get_result();
                
                $center_total_loan = 0;
                $center_total_balance = 0;
                $center_total_weekly = 0;
                $center_total_arrears = 0;
                
                while ($group = $groups_result->fetch_assoc()):
                    $group_total_loan = 0;
                    $group_total_balance = 0;
                    $group_total_weekly = 0;
                    $group_total_arrears = 0;
                ?>
                <tr class="group-header">
                    <td colspan="<?php echo 6 + 8; ?>">
                        <strong>Group - <?php echo htmlspecialchars($group['group_name'] ?: 'G-' . $group['group_number']); ?></strong>
                    </td>
                </tr>
                
                <?php
                // Get customers in this group with active/disbursed loans only
                $customer_sql = "SELECT c.id, c.full_name, c.short_name, c.national_id,
                                        l.id as loan_id, l.loan_number, l.amount, l.total_loan_amount,
                                        l.weekly_installment, l.status,
                                        (SELECT COALESCE(SUM(amount), 0) FROM loan_payments WHERE loan_id = l.id) as total_paid
                                 FROM customers c
                                 JOIN group_members gm ON c.id = gm.customer_id
                                 JOIN groups g ON gm.group_id = g.id
                                 JOIN loans l ON c.id = l.customer_id
                                 WHERE g.id = ? 
                                 AND l.status IN ('active', 'disbursed')
                                 AND l.cbo_id = ?
                                 ORDER BY c.full_name";
                $customer_stmt = $conn->prepare($customer_sql);
                $customer_stmt->bind_param("ii", $group['id'], $cbo_id);
                $customer_stmt->execute();
                $customers_result = $customer_stmt->get_result();
                
                while ($customer = $customers_result->fetch_assoc()):
                    $loan_balance = $customer['total_loan_amount'] - $customer['total_paid'];
                    $weekly_due = $customer['weekly_installment'];
                    
                    // Calculate arrears
                    $arrears = 0;
                    $overdue_sql = "SELECT COUNT(*) as overdue_count 
                                   FROM loan_installments 
                                   WHERE loan_id = ? 
                                   AND due_date < ? 
                                   AND status = 'pending'";
                    $overdue_stmt = $conn->prepare($overdue_sql);
                    $overdue_stmt->bind_param("is", $customer['loan_id'], $report_date);
                    $overdue_stmt->execute();
                    $overdue_result = $overdue_stmt->get_result();
                    $overdue_data = $overdue_result->fetch_assoc();
                    $arrears = $overdue_data['overdue_count'] * $weekly_due;
                    
                    // Use short name
                    $display_name = !empty($customer['short_name']) ? $customer['short_name'] : $customer['full_name'];
                    
                    $group_total_loan += $customer['amount'];
                    $group_total_balance += $loan_balance;
                    $group_total_weekly += $weekly_due;
                    $group_total_arrears += $arrears;
                ?>
                <tr>
                    <td class="text-left"><?php echo htmlspecialchars($display_name); ?></td>
                    <td><?php echo htmlspecialchars($customer['national_id']); ?></td>
                    <td class="text-right"><?php echo number_format($customer['amount'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($loan_balance, 2); ?></td>
                    <td class="text-right"><?php echo number_format($weekly_due, 2); ?></td>
                    <td class="text-right"><?php echo number_format($arrears, 2); ?></td>
                    
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td></td>
                    <td></td>
                    <?php endfor; ?>
                </tr>
                <?php endwhile; ?>
                
                <!-- Group Total -->
                <tr class="total-row">
                    <td colspan="2" class="text-left"><strong>Group Total</strong></td>
                    <td class="text-right"><strong><?php echo number_format($group_total_loan, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($group_total_balance, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($group_total_weekly, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($group_total_arrears, 2); ?></strong></td>
                    
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td></td>
                    <td></td>
                    <?php endfor; ?>
                </tr>
                
                <?php
                $center_total_loan += $group_total_loan;
                $center_total_balance += $group_total_balance;
                $center_total_weekly += $group_total_weekly;
                $center_total_arrears += $group_total_arrears;
                ?>
                
                <?php endwhile; ?>
                
                <!-- Center Total -->
                <tr class="center-total">
                    <td colspan="2" class="text-left"><strong>Center Total</strong></td>
                    <td class="text-right"><strong><?php echo number_format($center_total_loan, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($center_total_balance, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($center_total_weekly, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($center_total_arrears, 2); ?></strong></td>
                    
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td></td>
                    <td></td>
                    <?php endfor; ?>
                </tr>

                <!-- Only 2 empty rows after Center Total -->
                <?php for ($i = 1; $i <= 2; $i++): ?>
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php endfor; ?>
                </tr>
                <?php endfor; ?>

                <!-- Signature Rows -->
                <tr class="signature-row">
                    <td><strong>Denomination No:</strong></td>
                    <td colspan="5">&nbsp;</td>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php endfor; ?>
                </tr>
                <tr class="signature-row">
                    <td><strong>Signature of C.S.U. Manager</strong></td>
                    <td colspan="5">&nbsp;</td>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php endfor; ?>
                </tr>
                <tr class="signature-row">
                    <td><strong>Signature Of Cashier</strong></td>
                    <td colspan="5">&nbsp;</td>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php endfor; ?>
                </tr>
                <tr class="signature-row">
                    <td><strong>Signature Of Branch Manager</strong></td>
                    <td colspan="5">&nbsp;</td>
                    <?php for ($week_num = 1; $week_num <= 4; $week_num++): ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <?php endfor; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printReport() {
            // Hide print controls
            document.querySelector('.print-controls').style.display = 'none';
            
            // Print the document
            window.print();
            
            // Show print controls after a delay
            setTimeout(function() {
                document.querySelector('.print-controls').style.display = 'block';
            }, 1000);
        }
        
        // Auto-print if action is print
        <?php if ($action === 'print'): ?>
        window.onload = function() {
            setTimeout(function() {
                printReport();
            }, 500);
        }
        <?php endif; ?>
        
        // Handle print event
        window.addEventListener('afterprint', function() {
            document.querySelector('.print-controls').style.display = 'block';
        });
    </script>
</body>
</html>