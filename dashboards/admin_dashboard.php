<?php
// dashboards/admin_dashboard.php
require_once '../config/config.php';
require_once '../config/database.php';

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] !== 'admin'){
    header("location: ../login.php");
    exit;
}

error_log("Admin dashboard loaded for: " . $_SESSION['full_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-shield-alt me-2"></i>Admin Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <strong><?php echo $_SESSION['full_name']; ?></strong> (Admin - <?php echo $_SESSION['branch']; ?>)
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">✅ Admin Dashboard Loaded Successfully!</h4>
                    </div>
                    <div class="card-body">
                        <h5>Welcome, <?php echo $_SESSION['full_name']; ?>!</h5>
                        <p>You are logged in as <strong>Administrator</strong> at <strong><?php echo $_SESSION['branch']; ?> Branch</strong></p>
                        
                        <div class="row mt-4">
                            <div class="col-md-3 mb-3">
                                <div class="card text-center border-danger">
                                    <div class="card-body">
                                        <i class="fas fa-users-cog fa-3x text-danger mb-3"></i>
                                        <h6>User Management</h6>
                                        <small class="text-muted">Manage all users</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card text-center border-danger">
                                    <div class="card-body">
                                        <i class="fas fa-building fa-3x text-danger mb-3"></i>
                                        <h6>Branch Management</h6>
                                        <small class="text-muted">Manage branches</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card text-center border-danger">
                                    <div class="card-body">
                                        <i class="fas fa-cog fa-3x text-danger mb-3"></i>
                                        <h6>System Settings</h6>
                                        <small class="text-muted">System configuration</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card text-center border-danger">
                                    <div class="card-body">
                                        <i class="fas fa-chart-bar fa-3x text-danger mb-3"></i>
                                        <h6>Reports</h6>
                                        <small class="text-muted">View all reports</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <a href="../dashboard.php" class="btn btn-secondary me-2">Back to Router</a>
                            <a href="../logout.php" class="btn btn-outline-danger">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>