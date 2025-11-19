<?php
// user_management.php
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is admin
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("location: login.php");
    exit;
}

// Handle form actions
$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Add new user
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $full_name = trim($_POST['full_name']);
    $user_type = $_POST['user_type'];
    $branch = trim($_POST['branch']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    // Check if username exists
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    
    if($check_stmt->get_result()->num_rows > 0) {
        $error = "Username already exists!";
    } else {
        // Insert new user
        $sql = "INSERT INTO users (username, password, full_name, user_type, branch, email, phone, role_id, permissions) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Set permissions based on user type
        $permissions_map = [
            'admin' => '["all"]',
            'manager' => '["users.view", "users.create", "users.edit", "loans.view", "loans.approve", "reports.view"]',
            'credit_officer' => '["customers.view", "customers.create", "loans.view", "loans.create", "collections.view", "collections.create"]',
            'accountant' => '["transactions.view", "transactions.create", "reports.view", "collections.view"]'
        ];
        
        $permissions = $permissions_map[$user_type] ?? '[]';
        $role_id = array_search($user_type, ['admin', 'manager', 'credit_officer', 'accountant']) + 1;
        
        $stmt->bind_param("sssssssis", $username, $hashed_password, $full_name, $user_type, $branch, $email, $phone, $role_id, $permissions);
        
        if($stmt->execute()) {
            $message = "User added successfully!";
        } else {
            $error = "Error adding user: " . $conn->error;
        }
    }
}

// Delete user
if($action == 'delete' && isset($_GET['id'])) {
    $user_id = $_GET['id'];
    
    // Prevent self-deletion
    if($user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        
        if($stmt->execute()) {
            $message = "User deleted successfully!";
        } else {
            $error = "Error deleting user: " . $conn->error;
        }
    }
}

// Get all users
$users_sql = "SELECT u.*, r.role_name FROM users u 
              LEFT JOIN user_roles r ON u.role_id = r.id 
              ORDER BY u.created_at DESC";
$users_result = $conn->query($users_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .user-table th {
            background: #343a40;
            color: white;
        }
        .status-active { color: #28a745; }
        .status-inactive { color: #dc3545; }
        .action-btns .btn { margin: 2px; }
        
        /* Password toggle button styling */
        .input-group .btn-outline-secondary {
            border-color: #ced4da;
        }
        .input-group .btn-outline-secondary:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        /* Sidebar styles */
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
                <h2><i class="fas fa-users me-2"></i>User Management</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-user-plus me-1"></i>Add New User
                </button>
            </div>

            <!-- Messages -->
            <?php if($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Users</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>User Type</th>
                                    <th>Branch</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($user = $users_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <strong><?php echo $user['username']; ?></strong>
                                        <?php if($user['id'] == $_SESSION['user_id']): ?>
                                            <span class="badge bg-info">You</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $user['full_name']; ?></td>
                                    <td>
                                        <span class="badge 
                                            <?php 
                                            switch($user['user_type']) {
                                                case 'admin': echo 'bg-danger'; break;
                                                case 'manager': echo 'bg-warning'; break;
                                                case 'credit_officer': echo 'bg-success'; break;
                                                default: echo 'bg-primary';
                                            }
                                            ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['branch']; ?></td>
                                    <td><?php echo $user['email'] ?: 'N/A'; ?></td>
                                    <td><?php echo $user['phone'] ?: 'N/A'; ?></td>
                                    <td>
                                        <span class="status-<?php echo $user['status']; ?>">
                                            <i class="fas fa-circle me-1"></i>
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td class="action-btns">
                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if($user['id'] != $_SESSION['user_id']): ?>
                                            <a href="user_management.php?action=delete&id=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Are you sure you want to delete this user?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" name="password" class="form-control" id="passwordField" required>
                                    <button type="button" class="btn btn-outline-secondary" id="togglePassword">
                                        <i class="fas fa-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">User Type *</label>
                                <select name="user_type" class="form-select" required>
                                    <option value="">Select User Type</option>
                                    <option value="admin">Administrator</option>
                                    <option value="manager">Manager</option>
                                    <option value="credit_officer">Credit Officer</option>
                                    <option value="accountant">Accountant</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch *</label>
                                <input type="text" name="branch" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Password show/hide toggle for Add User Modal
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

        // Reset password field when modal is closed
        document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
            const passwordField = document.getElementById('passwordField');
            const passwordIcon = document.getElementById('passwordIcon');
            
            passwordField.type = 'password';
            passwordIcon.className = 'fas fa-eye';
        });

        // Auto-focus on username field when modal opens
        document.getElementById('addUserModal').addEventListener('shown.bs.modal', function () {
            document.querySelector('input[name="username"]').focus();
        });
    </script>
</body>
</html>