<?php
// unauthorized.php - Access Denied Page
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 3rem;
            text-align: center;
            max-width: 500px;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <i class="bi bi-shield-exclamation display-1 text-warning mb-3"></i>
        <h1 class="h2 mb-3">Access Denied</h1>
        <p class="lead text-muted mb-4">
            You don't have permission to access this page. 
            Please contact your system administrator if you believe this is an error.
        </p>
        
        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="bi bi-house"></i> Go to Dashboard
            </a>
            <a href="login.php" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-in-right"></i> Login Again
            </a>
        </div>
        
        <div class="mt-4">
            <small class="text-muted">
                Logged in as: <strong><?php echo $_SESSION['user_name'] ?? 'Guest'; ?></strong> | 
                Role: <strong><?php echo ucfirst($_SESSION['user_type'] ?? 'Unknown'); ?></strong>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>