<?php
// modules/admin/permissions.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Only admin can access this page
if ($_SESSION['user_type'] !== 'admin') {
    $_SESSION['error'] = "Access denied!";
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Get all pages in the system
$pages = [
    '/modules/reports/center_report.php' => 'Center Report',
    '/modules/reports/generate_center_report.php' => 'Generate Center Report',
    '/modules/customers/' => 'Customer Management',
    '/modules/loans/' => 'Loan Management',
    '/modules/groups/' => 'Group Management',
    '/modules/cbo/' => 'CBO Management',
    '/modules/staff/' => 'Staff Management',
    '/modules/admin/' => 'Admin Panel'
];

// User types
$user_types = ['field_officer', 'manager', 'accountant', 'admin'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = $_POST['user_type'];
    $page_path = $_POST['page_path'];
    $can_access = isset($_POST['can_access']) ? 1 : 0;
    
    // Check if permission already exists
    $check_sql = "SELECT id FROM user_permissions WHERE user_type = ? AND page_path = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ss", $user_type, $page_path);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update existing permission
        $update_sql = "UPDATE user_permissions SET can_access = ? WHERE user_type = ? AND page_path = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("iss", $can_access, $user_type, $page_path);
        $update_stmt->execute();
        $_SESSION['success'] = "Permission updated successfully!";
    } else {
        // Insert new permission
        $insert_sql = "INSERT INTO user_permissions (user_type, page_path, can_access) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ssi", $user_type, $page_path, $can_access);
        $insert_stmt->execute();
        $_SESSION['success'] = "Permission added successfully!";
    }
}

// Get current permissions
$permissions = [];
$perm_sql = "SELECT user_type, page_path, can_access FROM user_permissions";
$perm_result = $conn->query($perm_sql);
while ($row = $perm_result->fetch_assoc()) {
    $permissions[$row['user_type']][$row['page_path']] = $row['can_access'];
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - Micro Finance Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../../includes/header.php'; ?>
    <?php include '../../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 fw-bold text-dark">Manage User Permissions</h1>
                    <p class="text-muted">Control access to system pages for different user types</p>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Current Permissions</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Page</th>
                                            <?php foreach ($user_types as $type): ?>
                                                <th class="text-center"><?php echo ucfirst($type); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pages as $path => $name): ?>
                                            <tr>
                                                <td><?php echo $name; ?></td>
                                                <?php foreach ($user_types as $type): ?>
                                                    <td class="text-center">
                                                        <?php 
                                                        $has_access = true;
                                                        if (isset($permissions[$type][$path])) {
                                                            $has_access = (bool)$permissions[$type][$path];
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $has_access ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo $has_access ? 'Allowed' : 'Denied'; ?>
                                                        </span>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Add/Edit Permission</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">User Type</label>
                                    <select class="form-select" name="user_type" required>
                                        <option value="">Select User Type</option>
                                        <?php foreach ($user_types as $type): ?>
                                            <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Page</label>
                                    <select class="form-select" name="page_path" required>
                                        <option value="">Select Page</option>
                                        <?php foreach ($pages as $path => $name): ?>
                                            <option value="<?php echo $path; ?>"><?php echo $name; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="can_access" id="can_access" checked>
                                    <label class="form-check-label" for="can_access">Allow Access</label>
                                </div>
                                <button type="submit" class="btn btn-primary">Save Permission</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>