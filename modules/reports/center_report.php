<?php
// modules/reports/center_report.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant','credit_officer', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Get all field officers from staff table
$field_officers = [];
$fo_sql = "SELECT id, full_name, national_id FROM staff WHERE position = 'field_officer' ORDER BY full_name";
$fo_result = $conn->query($fo_sql);
if ($fo_result) {
    while ($row = $fo_result->fetch_assoc()) {
        $field_officers[] = $row;
    }
}

// Get CBOs based on selected field officer
$cbos = [];
if (isset($_GET['field_officer_id']) && !empty($_GET['field_officer_id'])) {
    $field_officer_id = intval($_GET['field_officer_id']);
    $cbo_sql = "SELECT id, name, cbo_number FROM cbo WHERE staff_id = ? ORDER BY name";
    $cbo_stmt = $conn->prepare($cbo_sql);
    $cbo_stmt->bind_param("i", $field_officer_id);
    $cbo_stmt->execute();
    $cbo_result = $cbo_stmt->get_result();
    while ($row = $cbo_result->fetch_assoc()) {
        $cbos[] = $row;
    }
} else {
    // If no field officer selected, show all CBOs
    $cbo_sql = "SELECT id, name, cbo_number FROM cbo ORDER BY name";
    $cbo_result = $conn->query($cbo_sql);
    if ($cbo_result) {
        while ($row = $cbo_result->fetch_assoc()) {
            $cbos[] = $row;
        }
    }
}

// Get groups for selected CBO
$groups = [];
if (isset($_GET['cbo_id']) && !empty($_GET['cbo_id'])) {
    $cbo_id = intval($_GET['cbo_id']);
    $group_sql = "SELECT id, group_number, group_name FROM groups WHERE cbo_id = ? ORDER BY group_number";
    $group_stmt = $conn->prepare($group_sql);
    $group_stmt->bind_param("i", $cbo_id);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    while ($row = $group_result->fetch_assoc()) {
        $groups[] = $row;
    }
}

// Loan status options
$loan_statuses = [
    'active' => 'Active',
    'disbursed' => 'Disbursed', 
    'rejected' => 'Rejected',
    'pending' => 'Pending',
    'completed' => 'Completed'
];
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Center Report - Micro Finance Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            color: #333;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
        }
        
        .report-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            border: none;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3a56d4, #2f0a8c);
            transform: translateY(-1px);
        }
        
        .preview-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            margin-top: 2rem;
            border: 2px dashed #dee2e6;
        }
        
        .customer-name-link {
            color: var(--primary);
            text-decoration: none;
            cursor: pointer;
        }
        
        .customer-name-link:hover {
            color: var(--secondary);
            text-decoration: underline;
        }
        
        .no-print {
            display: block;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white !important;
                font-size: 12px;
            }
            .main-content {
                margin-left: 0 !important;
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
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/reports/">Reports</a></li>
                            <li class="breadcrumb-item active">Center Report</li>
                        </ol>
                    </nav>
                    <h1 class="h3 fw-bold text-dark">Center Report</h1>
                    <p class="text-muted">Generate center-wise loan collection reports</p>
                </div>
            </div>

            <!-- Report Parameters Card -->
            <div class="report-card no-print">
                <div class="card-header-custom">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-gear me-2"></i>Report Parameters
                    </h5>
                </div>
                <div class="card-body">
                    <form id="reportForm" method="GET" action="">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Select Field Officer</label>
                                <select class="form-select" id="field_officer_id" name="field_officer_id" required>
                                    <option value="">-- Select Field Officer --</option>
                                    <?php foreach ($field_officers as $fo): ?>
                                        <option value="<?php echo $fo['id']; ?>" 
                                            <?php echo (isset($_GET['field_officer_id']) && $_GET['field_officer_id'] == $fo['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fo['full_name']) . ' (' . $fo['national_id'] . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Select Center (CBO)</label>
                                <select class="form-select" id="cbo_id" name="cbo_id" <?php echo (empty($cbos)) ? 'disabled' : ''; ?> required>
                                    <option value="">-- Select Center --</option>
                                    <?php foreach ($cbos as $cbo): ?>
                                        <option value="<?php echo $cbo['id']; ?>" 
                                            <?php echo (isset($_GET['cbo_id']) && $_GET['cbo_id'] == $cbo['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cbo['name']) . ' (Code: ' . $cbo['cbo_number'] . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($cbos) && isset($_GET['field_officer_id'])): ?>
                                    <div class="text-danger small mt-1">No centers found for this field officer</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Select Group (Optional)</label>
                                <select class="form-select" id="group_id" name="group_id" <?php echo (empty($groups)) ? 'disabled' : ''; ?>>
                                    <option value="">-- All Groups --</option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo $group['id']; ?>"
                                            <?php echo (isset($_GET['group_id']) && $_GET['group_id'] == $group['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($group['group_name'] ?: 'Group ' . $group['group_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Loan Status</label>
                                <select class="form-select" id="loan_status" name="loan_status">
                                    <option value="">-- All Statuses --</option>
                                    <?php foreach ($loan_statuses as $value => $label): ?>
                                        <option value="<?php echo $value; ?>"
                                            <?php echo (isset($_GET['loan_status']) && $_GET['loan_status'] == $value) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Report Date</label>
                                <input type="date" class="form-control" id="report_date" name="report_date" 
                                       value="<?php echo isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="d-flex gap-2">
                                    <button type="submit" name="action" value="preview" class="btn btn-primary">
                                        <i class="bi bi-eye me-2"></i>Show Report
                                    </button>
                                    <?php if (isset($_GET['cbo_id']) && !empty($_GET['cbo_id'])): ?>
                                    <a href="generate_center_report.php?<?php echo http_build_query($_GET); ?>&action=print" 
                                       target="_blank" 
                                       class="btn btn-warning">
                                        <i class="bi bi-printer me-2"></i>Print Report
                                    </a>
                                    <?php endif; ?>
                                    <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                                        <i class="bi bi-arrow-clockwise me-2"></i>Reset
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Preview -->
            <?php if (isset($_GET['cbo_id']) && !empty($_GET['cbo_id']) && isset($_GET['action']) && $_GET['action'] == 'preview'): ?>
            <div class="report-card">
                <div class="card-header-custom no-print">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-file-text me-2"></i>Report Preview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="preview-section">
                        <?php
                        // Get CBO details
                        $cbo_id = intval($_GET['cbo_id']);
                        $cbo_details = getCBOById($cbo_id);
                        
                        // Get Field Officer details
                        $field_officer_name = "";
                        if (isset($_GET['field_officer_id']) && !empty($_GET['field_officer_id'])) {
                            $fo_id = intval($_GET['field_officer_id']);
                            $fo_sql = "SELECT full_name FROM staff WHERE id = ?";
                            $fo_stmt = $conn->prepare($fo_sql);
                            $fo_stmt->bind_param("i", $fo_id);
                            $fo_stmt->execute();
                            $fo_result = $fo_stmt->get_result();
                            $fo_data = $fo_result->fetch_assoc();
                            $field_officer_name = $fo_data['full_name'] ?? '';
                        }
                        
                        if ($cbo_details):
                        ?>
                        <div class="text-center mb-4">
                            <h4 class="fw-bold"><?php echo htmlspecialchars($cbo_details['name']); ?> / Center Code - <?php echo htmlspecialchars($cbo_details['cbo_number']); ?></h4>
                            <?php if (!empty($field_officer_name)): ?>
                                <p class="text-muted mb-1">Field Officer: <?php echo htmlspecialchars($field_officer_name); ?></p>
                            <?php endif; ?>
                            <p class="text-muted">Report Date: <?php echo date('F j, Y', strtotime($_GET['report_date'])); ?></p>
                            <?php if (isset($_GET['loan_status']) && !empty($_GET['loan_status'])): ?>
                                <p class="text-muted">Loan Status: <?php echo htmlspecialchars($loan_statuses[$_GET['loan_status']]); ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Normal preview with good styling -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Customer NIC</th>
                                        <th>Loan Amount</th>
                                        <th>Loan Balance</th>
                                        <th>Weekly Due</th>
                                        <th>Arrears</th>
                                        <th>Loan Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Get groups for the selected CBO
                                    $group_condition = "";
                                    $group_params = [$cbo_id];
                                    $group_types = "i";
                                    
                                    if (isset($_GET['group_id']) && !empty($_GET['group_id'])) {
                                        $group_condition = " AND g.id = ?";
                                        $group_params[] = intval($_GET['group_id']);
                                        $group_types .= "i";
                                    }
                                    
                                    $group_sql = "SELECT g.id, g.group_number, g.group_name 
                                                 FROM groups g 
                                                 WHERE g.cbo_id = ?" . $group_condition . " 
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
                                    <tr class="table-info">
                                        <td colspan="7" class="fw-bold">
                                            Group - <?php echo htmlspecialchars($group['group_name'] ?: 'G-' . $group['group_number']); ?>
                                        </td>
                                    </tr>
                                    
                                    <?php
                                    // Get customers in this group with loans based on status filter
                                    $loan_status_condition = "";
                                    $customer_params = [$group['id'], $cbo_id];
                                    $customer_types = "ii";
                                    
                                    if (isset($_GET['loan_status']) && !empty($_GET['loan_status'])) {
                                        $loan_status_condition = " AND l.status = ?";
                                        $customer_params[] = $_GET['loan_status'];
                                        $customer_types .= "s";
                                    }
                                    
                                    $customer_sql = "SELECT c.id, c.full_name, c.national_id, c.short_name,
                                                            l.id as loan_id, l.loan_number, l.amount, 
                                                            (l.amount + l.service_charge + l.document_charge) as total_loan_amount,
                                                            l.weekly_installment, l.status,
                                                            (SELECT COALESCE(SUM(amount), 0) FROM loan_payments WHERE loan_id = l.id AND reversal_status != 'reversal') as total_paid
                                                     FROM customers c
                                                     JOIN group_members gm ON c.id = gm.customer_id
                                                     JOIN groups g ON gm.group_id = g.id
                                                     JOIN loans l ON c.id = l.customer_id
                                                     WHERE g.id = ? 
                                                     AND l.cbo_id = ?
                                                     " . $loan_status_condition . "
                                                     ORDER BY c.full_name";
                                    $customer_stmt = $conn->prepare($customer_sql);
                                    $customer_stmt->bind_param($customer_types, ...$customer_params);
                                    $customer_stmt->execute();
                                    $customers_result = $customer_stmt->get_result();
                                    
                                    $has_customers = false;
                                    while ($customer = $customers_result->fetch_assoc()):
                                        $has_customers = true;
                                        $loan_balance = $customer['total_loan_amount'] - $customer['total_paid'];
                                        $weekly_due = $customer['weekly_installment'];
                                        
                                        // Calculate arrears - Use the same logic as Arrears Report
                                        $arrears_sql = "SELECT COALESCE(SUM(li.amount - COALESCE(li.paid_amount, 0)), 0) as arrears_amount
                                                       FROM loan_installments li 
                                                       WHERE li.loan_id = ? 
                                                       AND li.due_date <= ? 
                                                       AND (li.status != 'paid' OR li.paid_amount < li.amount)";
                                        $arrears_stmt = $conn->prepare($arrears_sql);
                                        $arrears_stmt->bind_param("is", $customer['loan_id'], $_GET['report_date']);
                                        $arrears_stmt->execute();
                                        $arrears_result = $arrears_stmt->get_result();
                                        $arrears_data = $arrears_result->fetch_assoc();
                                        $arrears = $arrears_data['arrears_amount'];
                                        
                                        $group_total_loan += $customer['amount'];
                                        $group_total_balance += $loan_balance;
                                        $group_total_weekly += $weekly_due;
                                        $group_total_arrears += $arrears;
                                    ?>
                                    <tr>
                                        <td>
                                           <a href="<?php echo BASE_URL; ?>/modules/customer/view.php?customer_id=<?php echo $customer['id']; ?>" 
   class="customer-name-link" 
   target="_blank"
   title="View Customer Details">
    <?php echo htmlspecialchars($customer['full_name']); ?>
</a>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['national_id']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($customer['amount'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($loan_balance, 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($weekly_due, 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($arrears, 2); ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge 
                                                <?php 
                                                switch($customer['status']) {
                                                    case 'active': echo 'bg-success'; break;
                                                    case 'disbursed': echo 'bg-primary'; break;
                                                    case 'rejected': echo 'bg-danger'; break;
                                                    case 'pending': echo 'bg-warning'; break;
                                                    case 'completed': echo 'bg-secondary'; break;
                                                    default: echo 'bg-light text-dark';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars(ucfirst($customer['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    
                                    <?php if (!$has_customers): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">
                                            No customers found for this group with the selected criteria.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Group Total -->
                                    <?php if ($has_customers): ?>
                                    <tr class="table-warning">
                                        <td colspan="2" class="fw-bold">Group Total</td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($group_total_loan, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($group_total_balance, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($group_total_weekly, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($group_total_arrears, 2); ?></td>
                                        <td></td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php
                                    if ($has_customers) {
                                        $center_total_loan += $group_total_loan;
                                        $center_total_balance += $group_total_balance;
                                        $center_total_weekly += $group_total_weekly;
                                        $center_total_arrears += $group_total_arrears;
                                    }
                                    ?>
                                    
                                    <?php endwhile; ?>
                                    
                                    <!-- Center Total -->
                                    <?php if ($center_total_loan > 0): ?>
                                    <tr class="table-success">
                                        <td colspan="2" class="fw-bold">Center Total</td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($center_total_loan, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($center_total_balance, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($center_total_weekly, 2); ?></td>
                                        <td style="text-align: right;" class="fw-bold"><?php echo number_format($center_total_arrears, 2); ?></td>
                                        <td></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-exclamation-triangle display-4"></i>
                            <p class="mt-3">CBO not found.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-load CBOs when Field Officer is selected
        document.getElementById('field_officer_id').addEventListener('change', function() {
            if (this.value) {
                document.getElementById('reportForm').submit();
            }
        });

        // Auto-load groups when CBO is selected
        document.getElementById('cbo_id').addEventListener('change', function() {
            if (this.value) {
                document.getElementById('reportForm').submit();
            }
        });

        function resetForm() {
            // Clear all form fields and reload the page
            window.location.href = 'center_report.php';
        }
    </script>
</body>
</html>