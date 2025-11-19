<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// If user is already logged in, redirect to dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}

$username = $password = "";
$username_err = $password_err = $login_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    if(empty($username_err) && empty($password_err)){
        
        $sql = "SELECT id, username, password, full_name, user_type, branch, permissions, status FROM users WHERE username = ?";
        
        if($stmt = $conn->prepare($sql)){
            $stmt->bind_param("s", $param_username);
            $param_username = $username;
            
            if($stmt->execute()){
                $stmt->store_result();
                
                if($stmt->num_rows == 1){
                    $stmt->bind_result($id, $username, $hashed_password, $full_name, $user_type, $branch, $permissions, $status);
                    if($stmt->fetch()){
                        echo "<!-- Debug: User found: $username -->";
                        echo "<!-- Debug: Password hash: " . substr($hashed_password, 0, 20) . "... -->";
                        echo "<!-- Debug: Input password: $password -->";
                        
                        if($status == 'active'){
                            if(password_verify($password, $hashed_password)){
                                echo "<!-- Debug: Password verification SUCCESS -->";
                                
                                session_start();
                                
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["username"] = $username;
                                $_SESSION["full_name"] = $full_name;
                                $_SESSION["user_type"] = $user_type;
                                $_SESSION["branch"] = $branch;
                                $_SESSION["permissions"] = json_decode($permissions, true);
                                
                                echo "<!-- Debug: Session variables set -->";
                                echo "<!-- Debug: Redirecting to dashboard -->";
                                
                                header("location: dashboard.php");
                                exit;
                                
                            } else{
                                $login_err = "Invalid password! Hash: " . substr($hashed_password, 0, 20) . "...";
                                echo "<!-- Debug: Password verification FAILED -->";
                            }
                        } else{
                            $login_err = "Your account is not active.";
                        }
                    }
                } else{
                    $login_err = "Invalid username or password.";
                    echo "<!-- Debug: User not found -->";
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Debug - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-container { background: white; border-radius: 15px; padding: 2rem; box-shadow: 0 15px 35px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .debug-info { background: #f8f9fa; border-radius: 5px; padding: 10px; margin-top: 15px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2 class="text-center mb-4">Login Debug</h2>
        
        <?php if (!empty($login_err)): ?>
            <div class="alert alert-danger"><?php echo $login_err; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" value="admin" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" value="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login Debug</button>
        </form>

        <div class="debug-info">
            <strong>Test different passwords:</strong><br>
            - password<br>
            - 123456<br>
            - admin123<br>
            Check browser view source for debug info
        </div>
    </div>
</body>
</html>