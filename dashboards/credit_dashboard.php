<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is credit officer
if ($_SESSION['user_type'] !== 'credit_officer') {
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
    <title>Credit Officer Dashboard - <?php echo $branch; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .credit-card {
            border-left: 4px solid #20c997;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid py-4">
            <!-- Credit Officer Specific Header -->
            <div class="dashboard-header bg-success text-white">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="h2 mb-2">Credit Officer Dashboard</h1>
                        <p class="mb-0"><?php echo $branch; ?> - Field Operations</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-geo-alt me-1"></i>Credit Officer
                        </span>
                    </div>
                </div>
            </div>

            <!-- Credit Officer Specific Content -->
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card credit-card">
                        <div class="card-body text-center">
                            <i class="bi bi-person-plus text-success display-4 mb-3"></i>
                            <h4>Customer Registration</h4>
                            <p>Register new customers</p>
                            <a href="../modules/customer/register.php" class="btn btn-success">Register Customer</a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card credit-card">
                        <div class="card-body text-center">
                            <i class="bi bi-cash text-success display-4 mb-3"></i>
                            <h4>Loan Collections</h4>
                            <p>Record loan collections</p>
                            <a href="../modules/collections/field.php" class="btn btn-success">Record Collection</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>