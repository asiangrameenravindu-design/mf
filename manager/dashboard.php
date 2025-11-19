<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireAuth();

if (!hasPermission(ROLE_MANAGER)) {
    header('Location: ../dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">Micro Finance - Manager</a>
            <div class="navbar-nav">
                <a class="nav-link" href="clients.php">Clients</a>
                <a class="nav-link" href="loans.php">Loans</a>
                <a class="nav-link" href="reports.php">Reports</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2>Manager Dashboard</h2>
        <p>Welcome, <?php echo $_SESSION['user_name']; ?>!</p>
        
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Total Clients</h5>
                        <h3 class="card-text">150</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title">Active Loans</h5>
                        <h3 class="card-text">75</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title">Pending Approvals</h5>
                        <h3 class="card-text">12</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <h5 class="card-title">Overdue</h5>
                        <h3 class="card-text">5</h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="clients.php" class="btn btn-outline-primary mb-2 w-100">Manage Clients</a>
                        <a href="loans.php" class="btn btn-outline-success mb-2 w-100">Loan Management</a>
                        <a href="reports.php" class="btn btn-outline-info mb-2 w-100">View Reports</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Recent Activities</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group">
                            <li class="list-group-item">New loan application - John Doe</li>
                            <li class="list-group-item">Payment received - Jane Smith</li>
                            <li class="list-group-item">New client registered</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>