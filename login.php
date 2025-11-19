<?php
// login.php
require_once 'config/config.php';

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    redirectToDashboard($_SESSION['user_type']);
    exit();
}

// Function to redirect based on user type
function redirectToDashboard($user_type) {
    global $BASE_URL;
    
    $dashboards = [
        'admin' => 'admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        'credit_officer' => 'credit_dashboard.php',
        'accountant' => 'account_dashboard.php',
        'field_officer' => 'field_dashboard.php',
        'staff' => 'staff_dashboard.php'
    ];
    
    // Default dashboard if user type not found
    $dashboard = $dashboards[strtolower($user_type)] ?? 'dashboard.php';
    
    header('Location: ' . BASE_URL . '/' . $dashboard);
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Authentication using users table
    $sql = "SELECT id, full_name, user_type, username, email, status FROM users WHERE username = ? AND password = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ss", $username, $password);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['last_activity'] = time();
            
            // Redirect to appropriate dashboard based on user type
            redirectToDashboard($user['user_type']);
            exit();
            
        } else {
            $error = "Invalid username or password!";
        }
        $stmt->close();
    } else {
        $error = "Database error!";
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-50px, -50px) rotate(360deg); }
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 2rem;
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .logo h2 {
            color: #4361ee;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .logo p {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus {
            border-color: #4361ee;
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
            background: white;
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid #e9ecef;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.4);
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            backdrop-filter: blur(10px);
        }
        
        .user-types {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .user-type-badge {
            background: rgba(67, 97, 238, 0.1);
            color: #4361ee;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <div class="logo-icon">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <h2><?php echo SITE_NAME; ?></h2>
                <p>Microfinance Management System</p>
                <small class="text-muted">Version <?php echo SITE_VERSION; ?></small>
                
                <div class="user-types">
                    <span class="user-type-badge">👑 Admin</span>
                    <span class="user-type-badge">💼 Manager</span>
                    <span class="user-type-badge">👨‍💼 Credit Officer</span>
                    <span class="user-type-badge">📊 Accountant</span>
                </div>
            </div>
            
            <?php if (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Your session has expired due to inactivity. Please login again.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['logout'])): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    You have been successfully logged out.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['access_denied'])): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-shield-exclamation me-2"></i>
                    Access denied. Please login with appropriate credentials.
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person text-muted"></i>
                        </span>
                        <input type="text" class="form-control" name="username" required 
                               placeholder="Enter your username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock text-muted"></i>
                        </span>
                        <input type="password" class="form-control" name="password" required 
                               placeholder="Enter your password">
                    </div>
                </div>
                <button type="submit" class="btn btn-login text-white w-100 mb-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In to Dashboard
                </button>
            </form>
            
           
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add focus effects
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });
        });
    </script>
</body>
</html>