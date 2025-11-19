<?php
// dashboards/simple_dashboard.php
require_once '../config/config.php';
require_once '../config/database.php';

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../final_login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">Micro Finance System</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <strong><?php echo $_SESSION['user_name']; ?></strong>
                    (<?php echo $_SESSION['user_type']; ?> - <?php echo $_SESSION['branch']; ?>)
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">✅ Login Successful!</h4>
                    </div>
                    <div class="card-body">
                        <h5>User Information:</h5>
                        <ul>
                            <li><strong>Name:</strong> <?php echo $_SESSION['user_name']; ?></li>
                            <li><strong>Role:</strong> <?php echo $_SESSION['user_type']; ?></li>
                            <li><strong>Branch:</strong> <?php echo $_SESSION['branch']; ?></li>
                            <li><strong>Permissions:</strong> <?php echo json_encode($_SESSION['permissions']); ?></li>
                        </ul>
                        <p class="mb-0">You have successfully logged into the system!</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>