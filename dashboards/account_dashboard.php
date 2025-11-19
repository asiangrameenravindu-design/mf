<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is account clerk
if ($_SESSION['user_type'] !== 'account_clerk') {
    header('Location: ' . BASE_URL . '/unauthorized.php');
    exit();
}

$branch = $_SESSION['branch'];
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Clerk Dashboard - <?php echo $branch; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .account-card {
            border-left: 4px solid #6f42c1;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid py-4">
            <!-- Account Clerk Specific Header -->
            <div class="dashboard-header bg-primary text-white">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="h2 mb-2">Account Clerk Dashboard</h1>
                        <p class="mb-0"><?php echo $branch; ?> - Accounting & Transactions</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-calculator me-1"></i>Account Clerk
                        </span>
                    </div>
                </div>
            </div>

            <!-- Account Clerk Specific Content -->
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card account-card">
                        <div class="card-body text-center">
                            <i class="bi bi-cash-stack text-primary display-4 mb-3"></i>
                            <h4>Daily Collections</h4>
                            <p>Process daily collections</p>
                            <a href="../modules/collections/daily.php" class="btn btn-primary">View Collections</a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card account-card">
                        <div class="card-body text-center">
                            <i class="bi bi-receipt text-primary display-4 mb-3"></i>
                            <h4>Transaction Reports</h4>
                            <p>Generate financial reports</p>
                            <a href="../modules/reports/transactions.php" class="btn btn-primary">View Reports</a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card account-card">
                        <div class="card-body text-center">
                            <i class="bi bi-journal-text text-primary display-4 mb-3"></i>
                            <h4>Accounting</h4>
                            <p>Manage accounts</p>
                            <a href="../modules/accounting/manage.php" class="btn btn-primary">Manage Accounts</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>