<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] !== 'manager'){
    header("location: ../login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-warning">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-user-tie me-2"></i>Manager Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <strong><?php echo $_SESSION['full_name']; ?></strong> (Manager - <?php echo $_SESSION['branch']; ?>)
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">✅ Manager Dashboard Loaded Successfully!</h4>
                    </div>
                    <div class="card-body">
                        <h5>Manager Controls for <?php echo $_SESSION['branch']; ?> Branch:</h5>
                        <div class="row mt-3">
                            <div class="col-md-4 mb-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="fas fa-users fa-2x text-warning mb-2"></i>
                                        <h6>Staff Management</h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                                        <h6>Branch Reports</h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="fas fa-check-circle fa-2x text-warning mb-2"></i>
                                        <h6>Loan Approval</h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <a href="../dashboard.php" class="btn btn-secondary">Back to Main Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>