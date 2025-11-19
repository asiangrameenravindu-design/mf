<?php
// modules/admin/edit_user.php
session_start();
require_once '../../config/database.php';

// Check if user is logged in and is admin
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("location: ../../login.php");
    exit;
}

// Get user ID from URL
$user_id = $_GET['id'] ?? 0;
if(!$user_id) {
    header("location: users.php");
    exit;
}

// Get user details
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    header("location: users.php");
    exit;
}

$user = $result->fetch_assoc();

// Handle form submission
$message = '';
$error = '';

if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_user'])) {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $user_type = $_POST['user_type'];
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $status = $_POST['status'];
    
    // Check if username exists (excluding current user)
    $check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $username, $user_id);
    $check_stmt->execute();
    
    if($check_stmt->get_result()->num_rows > 0) {
        $error = "Username already exists!";
    } else {
        // Update user
        $sql = "UPDATE users SET username = ?, full_name = ?, user_type = ?, email = ?, phone = ?, status = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $username, $full_name, $user_type, $email, $phone, $status, $user_id);
        
        if($stmt->execute()) {
            $message = "User updated successfully!";
            // Refresh user data
            $sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        } else {
            $error = "Error updating user: " . $conn->error;
        }
    }
}

// Handle password reset
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if(empty($new_password)) {
        $error = "Password cannot be empty!";
    } elseif($new_password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_password, $user_id);
        
        if($stmt->execute()) {
            $message = "Password reset successfully!";
        } else {
            $error = "Error resetting password: " . $conn->error;
        }
    }
}

$base_url = 'http://localhost/micro_finance_system';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            margin: 0 auto;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: all 0.3s;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../../includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-pencil-square"></i>
                        Edit User
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="users.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Users
                        </a>
                    </div>
                </div>

                <!-- Messages -->
                <?php if($message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill"></i> <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- User Profile Card -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="user-avatar mb-3">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                                <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                                
                                <div class="mb-3">
                                    <span class="badge bg-<?php 
                                        switch($user['user_type']) {
                                            case 'admin': echo 'danger'; break;
                                            case 'manager': echo 'warning'; break;
                                            case 'credit_officer': echo 'success'; break;
                                            case 'accountant': echo 'info'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php 
                                        $role_names = [
                                            'admin' => 'Administrator',
                                            'manager' => 'Manager',
                                            'credit_officer' => 'Credit Officer',
                                            'accountant' => 'Accountant'
                                        ];
                                        echo $role_names[$user['user_type']] ?? $user['user_type'];
                                        ?>
                                    </span>
                                </div>

                                <div class="small text-muted">
                                    <div><i class="bi bi-envelope"></i> <?php echo $user['email'] ?: 'No email'; ?></div>
                                    <div><i class="bi bi-phone"></i> <?php echo $user['phone'] ?: 'No phone'; ?></div>
                                    <div><i class="bi bi-calendar"></i> Joined <?php echo date('M j, Y', strtotime($user['created_at'])); ?></div>
                                    <div>
                                        <i class="bi bi-circle-fill text-<?php echo $user['status'] == 'active' ? 'success' : 'secondary'; ?>"></i>
                                        <?php echo ucfirst($user['status']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit User Form -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-person-gear"></i> Edit User Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Username *</label>
                                            <input type="text" name="username" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" name="full_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">User Type *</label>
                                            <select name="user_type" class="form-select" required>
                                                <option value="manager" <?php echo $user['user_type'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                                <option value="credit_officer" <?php echo $user['user_type'] == 'credit_officer' ? 'selected' : ''; ?>>Credit Officer</option>
                                                <option value="accountant" <?php echo $user['user_type'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Status *</label>
                                            <select name="status" class="form-select" required>
                                                <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $user['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email']); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Phone</label>
                                            <input type="tel" name="phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['phone']); ?>">
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="update_user" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i> Update User
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Password Reset Card -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Reset Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">New Password *</label>
                                            <input type="password" name="new_password" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Confirm Password *</label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>Warning:</strong> This will immediately change the user's password.
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="reset_password" class="btn btn-warning" 
                                                onclick="return confirm('Are you sure you want to reset this user\\'s password?')">
                                            <i class="bi bi-arrow-repeat"></i> Reset Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Danger Zone -->
                        <?php if($user['id'] != $_SESSION['user_id']): ?>
                        <div class="card mt-4 border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Danger Zone</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">Once you delete a user, there is no going back. Please be certain.</p>
                                <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-danger" 
                                   onclick="return confirm('Are you absolutely sure you want to delete this user? This action cannot be undone.')">
                                    <i class="bi bi-trash"></i> Delete User
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>