<?php
// admin/manage_sidebar.php
session_start();
require_once __DIR__ . '/../config/config.php';

// Check if user is admin
if ($_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_permission'])) {
        addNewPermission();
    } elseif (isset($_POST['update_permission'])) {
        updatePermission();
    } elseif (isset($_POST['delete_permission'])) {
        deletePermission();
    }
}

function addNewPermission() {
    global $conn;
    
    $user_type = $_POST['user_type'];
    $file_path = $_POST['file_path'];
    $description = $_POST['description'];
    $icon = $_POST['icon'];
    $parent_id = $_POST['parent_id'];
    $is_submenu = $_POST['is_submenu'];
    $menu_order = $_POST['menu_order'];
    
    $stmt = $conn->prepare("
        INSERT INTO permissions (user_type, file_path, description, icon, parent_id, is_submenu, menu_order) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssiii", $user_type, $file_path, $description, $icon, $parent_id, $is_submenu, $menu_order);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Permission added successfully!";
    } else {
        $_SESSION['error'] = "Error adding permission: " . $stmt->error;
    }
}

function updatePermission() {
    global $conn;
    
    $id = $_POST['id'];
    $user_type = $_POST['user_type'];
    $file_path = $_POST['file_path'];
    $description = $_POST['description'];
    $icon = $_POST['icon'];
    $parent_id = $_POST['parent_id'];
    $is_submenu = $_POST['is_submenu'];
    $menu_order = $_POST['menu_order'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("
        UPDATE permissions 
        SET user_type=?, file_path=?, description=?, icon=?, parent_id=?, is_submenu=?, menu_order=?, status=?
        WHERE id=?
    ");
    $stmt->bind_param("ssssiiisi", $user_type, $file_path, $description, $icon, $parent_id, $is_submenu, $menu_order, $status, $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Permission updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating permission: " . $stmt->error;
    }
}

function deletePermission() {
    global $conn;
    
    $id = $_POST['id'];
    
    $stmt = $conn->prepare("DELETE FROM permissions WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Permission deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting permission: " . $stmt->error;
    }
}

// Get all permissions
function getAllPermissions() {
    global $conn;
    
    $result = $conn->query("
        SELECT p.*, 
               (SELECT description FROM permissions pp WHERE pp.id = p.parent_id) as parent_name
        FROM permissions p 
        ORDER BY p.user_type, p.parent_id, p.menu_order
    ");
    
    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row;
    }
    
    return $permissions;
}

// Get main menu items for dropdown
function getMainMenuItems($user_type = '') {
    global $conn;
    
    $sql = "SELECT id, description FROM permissions WHERE parent_id = 0 AND is_submenu = 0";
    if ($user_type) {
        $sql .= " AND user_type = '$user_type'";
    }
    $sql .= " ORDER BY description";
    
    $result = $conn->query($sql);
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    return $items;
}

$permissions = getAllPermissions();
$main_menu_items = getMainMenuItems();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sidebar Menu - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar-preview {
            background: linear-gradient(180deg, #0d6efd, #0dcaf0);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .permission-item {
            border-left: 4px solid #0d6efd;
            margin-bottom: 10px;
        }
        .submenu-item {
            border-left-color: #20c997;
            margin-left: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Include Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Manage Sidebar Menu</h1>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- Add New Permission Form -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Add New Menu Item</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">User Type</label>
                                            <select name="user_type" class="form-select" required>
                                                <option value="admin">Admin</option>
                                                <option value="manager">Manager</option>
                                                <option value="credit_officer">Credit Officer</option>
                                                <option value="accountant">Accountant</option>
                                                <option value="user">User</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Menu Order</label>
                                            <input type="number" name="menu_order" class="form-control" value="1" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">File Path</label>
                                        <input type="text" name="file_path" class="form-control" placeholder="e.g., admin/dashboard.php" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" name="description" class="form-control" placeholder="e.g., Dashboard" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Icon</label>
                                        <input type="text" name="icon" class="form-control" placeholder="e.g., bi bi-speedometer2" value="bi bi-file-earmark">
                                        <small class="text-muted">Use Bootstrap Icons class names</small>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Parent Menu</label>
                                            <select name="parent_id" class="form-select">
                                                <option value="0">Main Menu (No Parent)</option>
                                                <?php foreach ($main_menu_items as $item): ?>
                                                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['description']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Menu Type</label>
                                            <select name="is_submenu" class="form-select">
                                                <option value="0">Main Menu Item</option>
                                                <option value="1">Sub Menu Item</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" name="add_permission" class="btn btn-primary">Add Menu Item</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Preview and Existing Items -->
                    <div class="col-md-6">
                        <!-- Quick Preview -->
                        <div class="sidebar-preview">
                            <h6><i class="bi bi-eye me-2"></i>Sidebar Preview</h6>
                            <div class="mt-3">
                                <?php
                                // Show preview for admin
                                $_SESSION['user_type'] = 'admin';
                                echo generateSidebar();
                                ?>
                            </div>
                        </div>

                        <!-- Existing Permissions -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Existing Menu Items</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($permissions as $permission): ?>
                                    <div class="permission-item <?php echo $permission['is_submenu'] ? 'submenu-item' : ''; ?> p-3 bg-light">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?php echo htmlspecialchars($permission['description']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="<?php echo $permission['icon']; ?> me-1"></i>
                                                    <?php echo $permission['file_path']; ?> 
                                                    | <?php echo $permission['user_type']; ?>
                                                    | Order: <?php echo $permission['menu_order']; ?>
                                                    <?php if ($permission['is_submenu']): ?>
                                                        | Submenu of: <?php echo $permission['parent_name'] ?: 'N/A'; ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editPermission(<?php echo $permission['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $permission['id']; ?>">
                                                    <button type="submit" name="delete_permission" class="btn btn-sm btn-outline-danger" 
                                                        onclick="return confirm('Are you sure?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <input type="hidden" name="update_permission" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">User Type</label>
                                <select name="user_type" id="edit_user_type" class="form-select" required>
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manager</option>
                                    <option value="credit_officer">Credit Officer</option>
                                    <option value="accountant">Accountant</option>
                                    <option value="user">User</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu Order</label>
                                <input type="number" name="menu_order" id="edit_menu_order" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">File Path</label>
                            <input type="text" name="file_path" id="edit_file_path" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" id="edit_description" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Icon</label>
                            <input type="text" name="icon" id="edit_icon" class="form-control">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parent Menu</label>
                                <select name="parent_id" id="edit_parent_id" class="form-select">
                                    <option value="0">Main Menu (No Parent)</option>
                                    <?php foreach ($main_menu_items as $item): ?>
                                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['description']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menu Type</label>
                                <select name="is_submenu" id="edit_is_submenu" class="form-select">
                                    <option value="0">Main Menu Item</option>
                                    <option value="1">Sub Menu Item</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Menu Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function editPermission(id) {
        // Fetch permission data via AJAX
        fetch('get_permission.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('edit_id').value = data.permission.id;
                    document.getElementById('edit_user_type').value = data.permission.user_type;
                    document.getElementById('edit_file_path').value = data.permission.file_path;
                    document.getElementById('edit_description').value = data.permission.description;
                    document.getElementById('edit_icon').value = data.permission.icon;
                    document.getElementById('edit_parent_id').value = data.permission.parent_id;
                    document.getElementById('edit_is_submenu').value = data.permission.is_submenu;
                    document.getElementById('edit_menu_order').value = data.permission.menu_order;
                    document.getElementById('edit_status').value = data.permission.status;
                    
                    var editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    editModal.show();
                }
            })
            .catch(error => console.error('Error:', error));
    }
    </script>
</body>
</html>