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

// Get filter parameters
$cbo_id = $_GET['cbo_id'] ?? '';
$loan_number = $_GET['loan_number'] ?? '';
$customer_nic = $_GET['customer_nic'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$payment_ref = $_GET['payment_ref'] ?? '';

// Get CBOs for filter
$cbos = getAllCBOs();

// Get payment history - INCLUDING REVERSAL STATUS
$payments = [];
$total_amount = 0;

$sql = "SELECT lp.*, l.loan_number, c.full_name, c.national_id, 
               cb.name as cbo_name, cb.staff_id as cbo_staff_id,
               g.group_number,
               s.full_name as staff_name,
               fo.full_name as field_officer_name,
               li.installment_number, li.amount as installment_amount,
               li.paid_amount, li.status as installment_status,
               lp.reversal_status, lp.reversal_notes
        FROM loan_payments lp
        JOIN loans l ON lp.loan_id = l.id
        JOIN customers c ON l.customer_id = c.id
        JOIN cbo cb ON l.cbo_id = cb.id
        LEFT JOIN group_members gm ON c.id = gm.customer_id
        LEFT JOIN groups g ON gm.group_id = g.id
        LEFT JOIN staff s ON lp.received_by = s.id
        LEFT JOIN staff fo ON cb.staff_id = fo.id  -- Field Officer from CBO
        LEFT JOIN loan_installments li ON lp.installment_id = li.id
        WHERE 1=1";
    
$params = [];
$types = '';

if ($cbo_id) {
    $sql .= " AND l.cbo_id = ?";
    $params[] = $cbo_id;
    $types .= 'i';
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

$sql .= " ORDER BY lp.payment_date DESC, lp.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($payment = $result->fetch_assoc()) {
    $payments[] = $payment;
    // Only add to total if it's not a reversal payment
    if ($payment['reversal_status'] != 'reversal') {
        $total_amount += $payment['amount'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .reversal-payment {
            background-color: #f8d7da !important;
            text-decoration: line-through;
        }
        .reversed-payment {
            background-color: #e2e3e5 !important;
            color: #6c757d !important;
        }
        .reversal-badge {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .reversed-badge {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" style="margin-top: 80px;">
        <div class="container-fluid">
            <!-- Flash Message -->
            <?php $flash = getFlashMessage(); ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show mt-3">
                    <?php echo $flash['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-clock-history"></i> Payment History
                </h1>
            </div>

            <!-- Filter Form -->
            <div class="card mb-4">
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
                                <i class="bi bi-search"></i> Search
                            </button>
                            <a href="payment_history.php" class="btn btn-secondary">Clear</a>
                            <?php if (!empty($payments)): ?>
                            <a href="payment_report.php?<?php echo http_build_query($_GET); ?>" 
                               class="btn btn-outline-primary float-end" target="_blank">
                                <i class="bi bi-printer"></i> Print Report
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i> Payment Records
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
                                    <th>Customer</th>
                                    <th>NIC</th>
                                    <th>Loan Number</th>
                                    <th>CBO</th>
                                    <th>Installment</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Field Officer</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): 
                                    $is_reversal = $payment['reversal_status'] == 'reversal';
                                    $is_reversed = $payment['reversal_status'] == 'reversed';
                                ?>
                                <tr class="<?php echo $is_reversal ? 'reversal-payment' : ($is_reversed ? 'reversed-payment' : ''); ?>">
                                    <td><?php echo formatDate($payment['payment_date']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $payment['payment_reference'] ?? 'N/A'; ?></span>
                                        <?php if ($payment['reversal_notes']): ?>
                                            <br><small class="text-muted"><?php echo $payment['reversal_notes']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['national_id']); ?></td>
                                    <td><?php echo $payment['loan_number']; ?></td>
                                    <td><?php echo $payment['cbo_name']; ?></td>
                                    <td>
                                        <?php if ($payment['installment_number']): ?>
                                            <span class="badge bg-info">#<?php echo $payment['installment_number']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $is_reversal ? 'text-danger' : 'text-success'; ?> fw-bold">
                                        <?php echo $is_reversal ? '-' : ''; ?>Rs. <?php echo number_format(abs($payment['amount']), 2); ?>
                                    </td>
                                    <td>
                                        <?php if ($is_reversal): ?>
                                            <span class="reversal-badge">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>REVERSAL
                                            </span>
                                        <?php elseif ($is_reversed): ?>
                                            <span class="reversed-badge">
                                                <i class="bi bi-exclamation-triangle me-1"></i>REVERSED
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">ACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // Show Field Officer from CBO instead of the staff who entered the payment
                                        echo $payment['field_officer_name'] ?? 'Not Assigned';
                                        
                                        
                                        
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!$is_reversal && !$is_reversed && $_SESSION['user_type'] == 'admin'): ?>
                                            <a href="<?php echo BASE_URL; ?>/modules/payment/reverse_payment.php?payment_id=<?php echo $payment['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to reverse this payment? This action cannot be undone.')">
                                                <i class="bi bi-arrow-counterclockwise"></i> Reverse
                                            </a>
                                        <?php elseif ($is_reversal || $is_reversed): ?>
                                            <span class="text-muted small">No actions</span>
                                        <?php else: ?>
                                            <span class="text-muted small">No permission</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($cbo_id || $loan_number || $customer_nic): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-receipt display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No payments found</h5>
                            <p class="text-muted">No payment records match your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-search display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">Search Payments</h5>
                            <p class="text-muted">Use the filters above to search for payment records.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>