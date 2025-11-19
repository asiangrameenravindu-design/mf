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
$allowed_roles = ['manager', 'admin', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Get payment statistics
$today = date('Y-m-d');
$current_month = date('Y-m');
$current_year = date('Y');

// Today's payments
$today_sql = "SELECT COUNT(*) as count, SUM(amount) as amount 
              FROM loan_payments 
              WHERE payment_date = ?";
$today_stmt = $conn->prepare($today_sql);
$today_stmt->bind_param("s", $today);
$today_stmt->execute();
$today_result = $today_stmt->get_result()->fetch_assoc();

// This month's payments
$month_sql = "SELECT COUNT(*) as count, SUM(amount) as amount 
              FROM loan_payments 
              WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?";
$month_stmt = $conn->prepare($month_sql);
$month_stmt->bind_param("s", $current_month);
$month_stmt->execute();
$month_result = $month_stmt->get_result()->fetch_assoc();

// This year's payments
$year_sql = "SELECT COUNT(*) as count, SUM(amount) as amount 
             FROM loan_payments 
             WHERE YEAR(payment_date) = ?";
$year_stmt = $conn->prepare($year_sql);
$year_stmt->bind_param("s", $current_year);
$year_stmt->execute();
$year_result = $year_stmt->get_result()->fetch_assoc();

// Recent payments
$recent_sql = "SELECT lp.*, l.loan_number, c.full_name, cb.name as cbo_name
               FROM loan_payments lp
               JOIN loans l ON lp.loan_id = l.id
               JOIN customers c ON l.customer_id = c.id
               JOIN cbo cb ON l.cbo_id = cb.id
               ORDER BY lp.created_at DESC 
               LIMIT 10";
$recent_result = $conn->query($recent_sql);

// Top CBOs by payments
$top_cbos_sql = "SELECT cb.name, COUNT(lp.id) as payment_count, SUM(lp.amount) as total_amount
                 FROM loan_payments lp
                 JOIN loans l ON lp.loan_id = l.id
                 JOIN cbo cb ON l.cbo_id = cb.id
                 WHERE lp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY cb.id, cb.name
                 ORDER BY total_amount DESC 
                 LIMIT 5";
$top_cbos_result = $conn->query($top_cbos_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Dashboard - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" style="margin-top: 80px;">
        <div class="container-fluid">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-speedometer2"></i> Payment Dashboard
                </h1>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Today's Payments</h6>
                                    <h3>Rs. <?php echo number_format($today_result['amount'] ?? 0, 2); ?></h3>
                                    <p class="card-text"><?php echo $today_result['count'] ?? 0; ?> payments</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-currency-dollar display-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">This Month</h6>
                                    <h3>Rs. <?php echo number_format($month_result['amount'] ?? 0, 2); ?></h3>
                                    <p class="card-text"><?php echo $month_result['count'] ?? 0; ?> payments</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-calendar-check display-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">This Year</h6>
                                    <h3>Rs. <?php echo number_format($year_result['amount'] ?? 0, 2); ?></h3>
                                    <p class="card-text"><?php echo $year_result['count'] ?? 0; ?> payments</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-graph-up display-6"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Recent Payments -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history"></i> Recent Payments
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Loan Number</th>
                                            <th>CBO</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($payment = $recent_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo formatDate($payment['payment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                                            <td><?php echo $payment['loan_number']; ?></td>
                                            <td><?php echo $payment['cbo_name']; ?></td>
                                            <td class="text-success fw-bold">
                                                Rs. <?php echo number_format($payment['amount'], 2); ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top CBOs -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-trophy"></i> Top CBOs (Last 30 Days)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php while ($cbo = $top_cbos_result->fetch_assoc()): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded">
                                <div>
                                    <h6 class="mb-1"><?php echo $cbo['name']; ?></h6>
                                    <small class="text-muted"><?php echo $cbo['payment_count']; ?> payments</small>
                                </div>
                                <span class="text-success fw-bold">
                                    Rs. <?php echo number_format($cbo['total_amount'], 2); ?>
                                </span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-lightning"></i> Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="payment_entry.php" class="btn btn-primary">
                                    <i class="bi bi-credit-card"></i> New Payment Entry
                                </a>
                                <a href="payment_history.php" class="btn btn-outline-primary">
                                    <i class="bi bi-clock-history"></i> Payment History
                                </a>
                                <a href="payment_report.php" class="btn btn-outline-success">
                                    <i class="bi bi-printer"></i> Generate Report
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>