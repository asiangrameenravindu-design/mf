<?php
// modules/admin/users.php
session_start();
require_once '../../config/database.php';

// Check if user is logged in and is admin
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("location: ../../login.php");
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
        $sql = "INSERT INTO users (username, password, full_name, user_type, email, phone) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        $stmt->bind_param("ssssss", $username, $password, $full_name, $user_type, $email, $phone);
        
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
$users_sql = "SELECT * FROM users ORDER BY created_at DESC";
$users_result = $conn->query($users_sql);

// Set base URL
$base_url = 'http://localhost/micro_finance_system';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
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
                        <i class="bi bi-people"></i>
                        User Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="bi bi-person-plus me-1"></i>Add New User
                        </button>
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

                <!-- Users Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul"></i>
                            All Users (<?php echo $users_result->num_rows; ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if($users_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover user-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th>User</th>
                                        <th>Full Name</th>
                                        <th>User Type</th>
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
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-2">
                                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                    <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                        <br><small class="badge bg-info">You</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td>
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
                                        </td>
                                        <td><?php echo $user['email'] ?: 'N/A'; ?></td>
                                        <td><?php echo $user['phone'] ?: 'N/A'; ?></td>
                                        <td>
                                            <span class="status-<?php echo $user['status']; ?>">
                                                <i class="bi bi-circle-fill me-1" style="font-size: 8px;"></i>
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td class="action-btns">
                                            <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Are you sure you want to delete this user?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-people display-1 text-muted"></i>
                            <h5 class="text-muted mt-3">No Users Found</h5>
                            <p class="text-muted">Create your first user using the button above.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus me-2"></i>Add New User
                    </h5>
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
                                        <i class="bi bi-eye" id="passwordIcon"></i>
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
                                    <option value="manager">Manager</option>
                                    <option value="credit_officer">Credit Officer</option>
                                    <option value="accountant">Accountant</option>
                                </select>
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
        // Password show/hide toggle for Add User Modal
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('passwordField');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                passwordIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                passwordIcon.className = 'bi bi-eye';
            }
        });

        // Reset password field when modal is closed
        document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
            const passwordField = document.getElementById('passwordField');
            const passwordIcon = document.getElementById('passwordIcon');
            
            passwordField.type = 'password';
            passwordIcon.className = 'bi bi-eye';
        });

        // Auto-focus on username field when modal opens
        document.getElementById('addUserModal').addEventListener('shown.bs.modal', function () {
            document.querySelector('input[name="username"]').focus();
        });
    </script>
</body>
</html>