<?php
// edit_user.php
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is admin
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("location: login.php");
    exit;
}

$user_id = $_GET['id'] ?? 0;
$message = '';
$error = '';

// Get user data
$user_sql = "SELECT * FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if($user_result->num_rows === 0) {
    header("location: user_management.php");
    exit;
}

$user = $user_result->fetch_assoc();

// Update user
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_user'])) {
    $full_name = trim($_POST['full_name']);
    $user_type = $_POST['user_type'];
    $branch = trim($_POST['branch']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $status = $_POST['status'];
    $password = trim($_POST['password']);
    
    // Build SQL query based on whether password is provided
    if(!empty($password)) {
        $sql = "UPDATE users SET full_name = ?, user_type = ?, branch = ?, email = ?, phone = ?, status = ?, password = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param("sssssssi", $full_name, $user_type, $branch, $email, $phone, $status, $hashed_password, $user_id);
    } else {
        $sql = "UPDATE users SET full_name = ?, user_type = ?, branch = ?, email = ?, phone = ?, status = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $full_name, $user_type, $branch, $email, $phone, $status, $user_id);
    }
    
    if($stmt->execute()) {
        $message = "User updated successfully!";
        // Refresh user data
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
    } else {
        $error = "Error updating user: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            background: #343a40;
            min-height: 100vh;
            color: white;
            position: fixed;
            width: 250px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link {
            color: white;
            padding: 12px 20px;
            border-left: 4px solid transparent;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #495057;
            border-left-color: #dc3545;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -250px;
            }
            .main-content {
                margin-left: 0;
            }
            .sidebar.active {
                margin-left: 0;
            }
        }
        
        /* Password toggle button styling */
        .input-group .btn-outline-secondary {
            border-color: #ced4da;
        }
        .input-group .btn-outline-secondary:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        .password-note {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="navbar navbar-light bg-white shadow-sm rounded mb-4">
            <div class="container-fluid">
                <button class="btn btn-outline-secondary" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user me-1"></i>
                        <strong><?php echo $_SESSION['full_name']; ?></strong>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>
            </div>
        </nav>

        <div class="container-fluid">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-edit me-2"></i>Edit User</h2>
                <a href="user_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Users
                </a>
            </div>

            <!-- Messages -->
            <?php if($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Edit User Form -->
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Editing: <?php echo $user['username']; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?php echo $user['username']; ?>" readonly>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" value="<?php echo $user['full_name']; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">User Type *</label>
                                <select name="user_type" class="form-select" required>
                                    <option value="admin" <?php echo $user['user_type'] == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    <option value="manager" <?php echo $user['user_type'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                    <option value="credit_officer" <?php echo $user['user_type'] == 'credit_officer' ? 'selected' : ''; ?>>Credit Officer</option>
                                    <option value="accountant" <?php echo $user['user_type'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch *</label>
                                <input type="text" name="branch" class="form-control" value="<?php echo $user['branch']; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo $user['email']; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo $user['phone']; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" class="form-control" id="passwordField" placeholder="Leave blank to keep current password">
                                    <button type="button" class="btn btn-outline-secondary" id="togglePassword">
                                        <i class="fas fa-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <div class="password-note">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Only enter a new password if you want to change it
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" required>
                                    <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $user['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Created Date</label>
                                <input type="text" class="form-control" value="<?php echo date('Y-m-d H:i:s', strtotime($user['created_at'])); ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Updated</label>
                                <input type="text" class="form-control" value="<?php echo date('Y-m-d H:i:s', strtotime($user['updated_at'])); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="update_user" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update User
                            </button>
                            <a href="user_management.php" class="btn btn-secondary">Cancel</a>
                            
                            <?php if($user['id'] != $_SESSION['user_id']): ?>
                                <a href="user_management.php?action=delete&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-danger float-end" 
                                   onclick="return confirm('Are you sure you want to delete this user?')">
                                    <i class="fas fa-trash me-1"></i>Delete User
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Password show/hide toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('passwordField');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                passwordIcon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                passwordIcon.className = 'fas fa-eye';
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('passwordField').value;
            const fullName = document.querySelector('input[name="full_name"]').value;
            const branch = document.querySelector('input[name="branch"]').value;
            
            // Basic validation
            if (!fullName.trim()) {
                alert('Please enter full name');
                e.preventDefault();
                return;
            }
            
            if (!branch.trim()) {
                alert('Please enter branch');
                e.preventDefault();
                return;
            }
            
            // Password strength check (if provided)
            if (password.trim() && password.length < 6) {
                alert('Password should be at least 6 characters long');
                e.preventDefault();
                return;
            }
        });

        // Auto-focus on full name field
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="full_name"]').focus();
        });
    </script>
</body>
</html>