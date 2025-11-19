<?php
// modules/admin/manage_permissions.php
require_once __DIR__ . '/../../config/config.php';

// Only admin can access this page
checkAccess('admin');

// Include auto permissions functions
require_once __DIR__ . '/../../includes/auto_permissions.php';

// User types (from your users table)
$user_types = ['admin', 'manager', 'field_officer', 'accountant'];

// Handle manual scan request
if (isset($_GET['scan'])) {
    scanAndAddNewFiles();
    $_SESSION['success'] = "File system scanned successfully! New files added to permissions.";
    header('Location: manage_permissions.php');
    exit();
}

// Handle form submission for adding/editing permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['user_type']) && isset($_POST['page_path'])) {
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
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Permission updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating permission: " . $conn->error;
            }
        } else {
            // Insert new permission
            $insert_sql = "INSERT INTO user_permissions (user_type, page_path, can_access) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssi", $user_type, $page_path, $can_access);
            if ($insert_stmt->execute()) {
                $_SESSION['success'] = "Permission added successfully!";
            } else {
                $_SESSION['error'] = "Error adding permission: " . $conn->error;
            }
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action'])) {
        $bulk_user_types = $_POST['bulk_user_types'] ?? [];
        $bulk_page_paths = $_POST['bulk_page_paths'] ?? [];
        $bulk_access = $_POST['bulk_access'] ?? 1;
        
        foreach ($bulk_user_types as $user_type) {
            foreach ($bulk_page_paths as $page_path) {
                // Check if permission exists
                $check_sql = "SELECT id FROM user_permissions WHERE user_type = ? AND page_path = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ss", $user_type, $page_path);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Update existing
                    $update_sql = "UPDATE user_permissions SET can_access = ? WHERE user_type = ? AND page_path = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("iss", $bulk_access, $user_type, $page_path);
                    $update_stmt->execute();
                } else {
                    // Insert new
                    $insert_sql = "INSERT INTO user_permissions (user_type, page_path, can_access) VALUES (?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ssi", $user_type, $page_path, $bulk_access);
                    $insert_stmt->execute();
                }
            }
        }
        $_SESSION['success'] = "Bulk permissions updated successfully!";
    }
}

// Handle delete permission
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $delete_sql = "DELETE FROM user_permissions WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $id);
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Permission deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting permission: " . $conn->error;
    }
    header('Location: manage_permissions.php');
    exit();
}

// Get all pages from database
function getAllSystemPages() {
    global $conn;
    
    $pages = [];
    
    // Get pages from database
    $sql = "SELECT DISTINCT page_path FROM user_permissions ORDER BY page_path";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $path = $row['page_path'];
        $pages[$path] = getDisplayName($path, basename($path));
    }
    
    // If no pages found, scan automatically
    if (empty($pages)) {
        scanAndAddNewFiles();
        // Get pages again after scan
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $path = $row['page_path'];
            $pages[$path] = getDisplayName($path, basename($path));
        }
    }
    
    return $pages;
}

// Get current permissions
$permissions = [];
$perm_sql = "SELECT id, user_type, page_path, can_access FROM user_permissions ORDER BY page_path, user_type";
$perm_result = $conn->query($perm_sql);
while ($row = $perm_result->fetch_assoc()) {
    $permissions[$row['page_path']][$row['user_type']] = [
        'can_access' => $row['can_access'],
        'id' => $row['id']
    ];
}

$pages = getAllSystemPages();
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        .folder-row {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .file-row {
            background-color: #ffffff;
        }
        .file-row td:first-child {
            padding-left: 40px;
        }
        .permission-cell {
            min-width: 120px;
        }
        .bulk-section {
            background-color: #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/admin/">Admin</a></li>
                            <li class="breadcrumb-item active">Manage Permissions</li>
                        </ol>
                    </nav>
                    <h1 class="h3 fw-bold text-dark">Manage User Permissions</h1>
                    <p class="text-muted">Control access to system pages and files for different user types</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Auto Scanner Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-search me-2"></i>Auto File Scanner
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <p class="mb-1">Automatically scans for new PHP files and adds them to permissions system.</p>
                                    <small class="text-muted">
                                        Scans: customers, loans, groups, cbo, reports, users, admin folders
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <a href="manage_permissions.php?scan=1" class="btn btn-warning">
                                        <i class="bi bi-search me-2"></i>Scan for New Files
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-gear me-2"></i>Bulk Permission Management
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="bulk_action" value="1">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">User Types</label>
                                        <?php foreach ($user_types as $type): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="bulk_user_types[]" value="<?php echo $type; ?>" id="bulk_<?php echo $type; ?>">
                                                <label class="form-check-label" for="bulk_<?php echo $type; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Pages/Files</label>
                                        <select class="form-select" name="bulk_page_paths[]" multiple size="5">
                                            <?php foreach ($pages as $path => $name): ?>
                                                <option value="<?php echo $path; ?>"><?php echo $name; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Hold Ctrl to select multiple</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Action</label>
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="bulk_access" value="1" id="bulk_allow" checked>
                                                <label class="form-check-label text-success fw-bold" for="bulk_allow">
                                                    Allow Access
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="bulk_access" value="0" id="bulk_deny">
                                                <label class="form-check-label text-danger fw-bold" for="bulk_deny">
                                                    Deny Access
                                                </label>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-save me-2"></i>Apply Bulk Changes
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permissions Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-shield-lock me-2"></i>Current Permissions
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pages)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-folder-x display-4 text-muted"></i>
                                    <p class="mt-3 text-muted">No permissions found. Run the auto-scanner to add files.</p>
                                    <a href="manage_permissions.php?scan=1" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Scan Files
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Page / File</th>
                                                <?php foreach ($user_types as $type): ?>
                                                    <th class="text-center permission-cell">
                                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                                    </th>
                                                <?php endforeach; ?>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pages as $path => $name): ?>
                                                <?php 
                                                $is_folder = !str_contains($name, '-');
                                                $row_class = $is_folder ? 'folder-row' : 'file-row';
                                                ?>
                                                <tr class="<?php echo $row_class; ?>">
                                                    <td class="fw-bold">
                                                        <?php if (!$is_folder): ?>
                                                            <i class="bi bi-file-text me-1 text-muted"></i>
                                                        <?php endif; ?>
                                                        <?php echo $name; ?>
                                                        <br>
                                                        <small class="text-muted"><?php echo $path; ?></small>
                                                    </td>
                                                    
                                                    <?php foreach ($user_types as $type): ?>
                                                        <td class="text-center permission-cell">
                                                            <?php 
                                                            $has_access = true;
                                                            $permission_id = null;
                                                            
                                                            if (isset($permissions[$path][$type])) {
                                                                $has_access = (bool)$permissions[$path][$type]['can_access'];
                                                                $permission_id = $permissions[$path][$type]['id'];
                                                            }
                                                            ?>
                                                            <span class="badge <?php echo $has_access ? 'bg-success' : 'bg-danger'; ?>">
                                                                <?php echo $has_access ? 'Allowed' : 'Denied'; ?>
                                                            </span>
                                                        </td>
                                                    <?php endforeach; ?>
                                                    
                                                    <td class="text-center">
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-outline-primary" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#editPermissionModal"
                                                                    data-path="<?php echo $path; ?>"
                                                                    data-name="<?php echo $name; ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <?php 
                                                            // Check if this path has any permissions
                                                            $has_permissions = isset($permissions[$path]);
                                                            if ($has_permissions): 
                                                            ?>
                                                            <a href="manage_permissions.php?delete=<?php echo $permission_id; ?>" 
                                                               class="btn btn-outline-danger"
                                                               onclick="return confirm('Are you sure you want to delete permissions for <?php echo $name; ?>?')">
                                                                <i class="bi bi-trash"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add/Edit Permission Card -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-plus-circle me-2"></i>Add/Edit Single Permission
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="permissionForm">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">User Type</label>
                                    <select class="form-select" name="user_type" required id="user_type">
                                        <option value="">Select User Type</option>
                                        <?php foreach ($user_types as $type): ?>
                                            <option value="<?php echo $type; ?>"><?php echo ucfirst(str_replace('_', ' ', $type)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Page/File</label>
                                    <select class="form-select" name="page_path" required id="page_path">
                                        <option value="">Select Page or File</option>
                                        <?php foreach ($pages as $path => $name): ?>
                                            <option value="<?php echo $path; ?>"><?php echo $name; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="can_access" id="can_access" checked role="switch">
                                    <label class="form-check-label fw-semibold" for="can_access">Allow Access</label>
                                </div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-save me-2"></i>Save Permission
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Permission Guide
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6>Default Permission Rules:</h6>
                            <ul class="list-unstyled">
                                <li><span class="badge bg-success">Allowed</span> - User can access the page</li>
                                <li><span class="badge bg-danger">Denied</span> - User cannot access the page</li>
                            </ul>
                            
                            <h6>Folder-based Defaults:</h6>
                            <ul class="small">
                                <li><strong>Admin folders:</strong> Admin only</li>
                                <li><strong>Loan management:</strong> All except admin</li>
                                <li><strong>CBO management:</strong> All except manager</li>
                                <li><strong>Others:</strong> All users allowed</li>
                            </ul>
                            
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-lightbulb me-2"></i>
                                <strong>Tip:</strong> Use bulk actions for quick permission updates across multiple users and pages.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Permission Modal -->
    <div class="modal fade" id="editPermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Permissions for <span id="modalPageName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="bulkEditForm">
                        <input type="hidden" name="bulk_page_paths[]" id="modalPagePath">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Set permissions for:</label>
                            <?php foreach ($user_types as $type): ?>
                                <div class="form-check">
                                    <input class="form-check-input user-type-check" type="checkbox" name="bulk_user_types[]" value="<?php echo $type; ?>" id="modal_<?php echo $type; ?>">
                                    <label class="form-check-label" for="modal_<?php echo $type; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Access Level:</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulk_access" value="1" id="modal_allow" checked>
                                <label class="form-check-label text-success fw-bold" for="modal_allow">
                                    Allow Access
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="bulk_access" value="0" id="modal_deny">
                                <label class="form-check-label text-danger fw-bold" for="modal_deny">
                                    Deny Access
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('bulkEditForm').submit();">
                        <i class="bi bi-save me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit Modal functionality
        const editPermissionModal = document.getElementById('editPermissionModal');
        if (editPermissionModal) {
            editPermissionModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const path = button.getAttribute('data-path');
                const name = button.getAttribute('data-name');
                
                document.getElementById('modalPageName').textContent = name;
                document.getElementById('modalPagePath').value = path;
                
                // Check all user types by default
                document.querySelectorAll('.user-type-check').forEach(checkbox => {
                    checkbox.checked = true;
                });
            });
        }

        // Quick select all for bulk actions
        function selectAllUserTypes(selectAll) {
            document.querySelectorAll('input[name="bulk_user_types[]"]').forEach(checkbox => {
                checkbox.checked = selectAll;
            });
        }

        // Quick actions
        function quickAction(action) {
            switch(action) {
                case 'allow_all_admin':
                    selectAllUserTypes(false);
                    document.querySelector('input[value="admin"]').checked = true;
                    document.querySelectorAll('select[name="bulk_page_paths[]"] option').forEach(option => {
                        option.selected = true;
                    });
                    document.getElementById('bulk_allow').checked = true;
                    break;
                case 'deny_loans_admin':
                    selectAllUserTypes(false);
                    document.querySelector('input[value="admin"]').checked = true;
                    const loanOptions = document.querySelectorAll('select[name="bulk_page_paths[]"] option');
                    loanOptions.forEach(option => {
                        option.selected = option.textContent.includes('Loan') || option.textContent.includes('loan');
                    });
                    document.getElementById('bulk_deny').checked = true;
                    break;
            }
        }
    </script>
</body>
</html>