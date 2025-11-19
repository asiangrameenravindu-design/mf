<?php
// modules/admin/dashboard.php
session_start();

$config_path = __DIR__ . '/../../config/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die("Config file not found at: " . $config_path);
}

// Check if user is admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
        }
        
        .main-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar-column {
            width: 280px;
            min-width: 280px;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .content-column {
            margin-left: 280px;
            flex: 1;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        
        @media (max-width: 768px) {
            .sidebar-column {
                width: 100%;
                position: relative;
                height: auto;
            }
            
            .content-column {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Sidebar Column -->
        <div class="sidebar-column">
            <?php 
            $sidebar_path = __DIR__ . '/../../includes/sidebar.php';
            if (file_exists($sidebar_path)) {
                include $sidebar_path;
            } else {
                echo "<div class='alert alert-danger m-3'>Sidebar file not found!</div>";
            }
            ?>
        </div>
        
        <!-- Main Content Column -->
        <div class="content-column">
            <main class="p-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Admin Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="badge bg-primary">
                            <i class="bi bi-person"></i> 
                            <?php echo $_SESSION['user_name'] ?? 'Admin'; ?>
                        </span>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="row">
                    <div class="col-md-3 mb-4">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4>150</h4>
                                        <p>Total Users</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-people fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-4">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4>45</h4>
                                        <p>Active Loans</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-cash-coin fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-4">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4>12</h4>
                                        <p>Pending Loans</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-clock fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-4">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4>LKR 2.5M</h4>
                                        <p>Total Portfolio</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-graph-up fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <a href="manage_sidebar.php" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-menu-button-wide me-2"></i>
                                            Manage Menu
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="users.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-people me-2"></i>
                                            Manage Users
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="loans.php" class="btn btn-outline-warning w-100">
                                            <i class="bi bi-cash-coin me-2"></i>
                                            Loan Management
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="reports.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-graph-up me-2"></i>
                                            View Reports
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Recent Activity</h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-person-plus text-success me-2"></i>
                                            New user registered
                                        </div>
                                        <small class="text-muted">2 minutes ago</small>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-cash-coin text-primary me-2"></i>
                                            Loan application submitted
                                        </div>
                                        <small class="text-muted">5 minutes ago</small>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-check-circle text-success me-2"></i>
                                            Loan approved
                                        </div>
                                        <small class="text-muted">1 hour ago</small>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-graph-up text-info me-2"></i>
                                            Monthly report generated
                                        </div>
                                        <small class="text-muted">2 hours ago</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>