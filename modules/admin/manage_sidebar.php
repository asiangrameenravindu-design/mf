<?php
// modules/admin/manage_sidebar.php
session_start();

// Correct path to config.php
$config_path = __DIR__ . '/../../config/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die("Config file not found at: " . $config_path);
}

// Check if user is admin
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../../login.php");
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

function checkDuplicatePermission($user_type, $file_path) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id FROM permissions WHERE user_type = ? AND file_path = ?");
    $stmt->bind_param("ss", $user_type, $file_path);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
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
    
    // Check for duplicate before inserting
    if (checkDuplicatePermission($user_type, $file_path)) {
        $_SESSION['error'] = "Error: Menu item already exists for this user type and file path!";
        return;
    }
    
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
    
    // Check for duplicate (excluding current record)
    $stmt = $conn->prepare("SELECT id FROM permissions WHERE user_type = ? AND file_path = ? AND id != ?");
    $stmt->bind_param("ssi", $user_type, $file_path, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $_SESSION['error'] = "Error: Another menu item already exists with this user type and file path!";
        return;
    }
    
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
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --warning: #ff9e00;
            --danger: #ef476f;
            --info: #06d6a0;
            --dark: #2b2d42;
            --light: #f8f9fa;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar-preview {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .preview-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .preview-header i {
            font-size: 1.5rem;
            margin-right: 10px;
        }
        
        .preview-item {
            padding: 12px 15px;
            margin: 5px 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            border-left: 3px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        
        .preview-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }
        
        .preview-subitem {
            margin-left: 20px;
            border-left-color: var(--success);
        }
        
        .user-type-badge {
            font-size: 0.75em;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .badge-admin { background: var(--danger); }
        .badge-manager { background: var(--primary); }
        .badge-accountant { background: var(--info); }
        .badge-credit_officer { background: var(--warning); }
        .badge-user { background: var(--secondary); }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
        }
        
        .section-header i {
            color: var(--primary);
            font-size: 1.3rem;
            margin-right: 10px;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            color: var(--dark);
            text-decoration: none;
            display: block;
            margin-bottom: 15px;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
            color: var(--primary);
            transform: translateY(-2px);
        }
        
        .stats-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stats-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .menu-item-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .menu-item-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
        }
        
        .menu-item-card.submenu {
            border-left-color: var(--success);
            margin-left: 25px;
            background: #f8f9fa;
        }
        
        .action-buttons .btn {
            padding: 6px 12px;
            border-radius: 8px;
            margin: 2px;
        }
        
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border-left: 5px solid var(--primary);
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    $sidebar_path = __DIR__ . '/../../includes/sidebar.php';
    if (file_exists($sidebar_path)) {
        include $sidebar_path;
    } else {
        echo "<div class='alert alert-danger'>Sidebar not found at: " . $sidebar_path . "</div>";
    }
    ?>
    
    <!-- Main Content -->
    <div class="main-content" style="margin-left: 280px;">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="h3 mb-0">
                            <i class="bi bi-menu-button-wide text-primary me-2"></i>
                            Manage Sidebar Menu
                        </h1>
                        <p class="text-muted mb-0">Customize navigation menu for different user roles</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="dashboard.php" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success glass-card">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger glass-card">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left Column - Forms and Stats -->
                <div class="col-lg-5">
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="stats-card">
                                <div class="stats-number"><?php echo count($permissions); ?></div>
                                <div class="stats-label">Total Menu Items</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stats-card" style="background: linear-gradient(135deg, var(--info), var(--success));">
                                <div class="stats-number"><?php echo count(array_unique(array_column($permissions, 'user_type'))); ?></div>
                                <div class="stats-label">User Roles</div>
                            </div>
                        </div>
                    </div>

                    <!-- Add New Permission Form -->
                    <div class="form-section glass-card">
                        <div class="section-header">
                            <i class="bi bi-plus-circle"></i>
                            <h5 class="mb-0">Add New Menu Item</h5>
                        </div>
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">User Type *</label>
                                    <select name="user_type" class="form-select" required style="border-radius: 8px;">
                                        <option value="admin">👑 Admin</option>
                                        <option value="manager">💼 Manager</option>
                                        <option value="credit_officer">👨‍💼 Credit Officer</option>
                                        <option value="accountant">📊 Accountant</option>
                                        <option value="user">👤 User</option>
                                        <option value="customer">👥 Customer</option>
                                        <option value="staff">👨‍💻 Staff</option>
                                        <option value="cbo">🏢 CBO</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Menu Order *</label>
                                    <input type="number" name="menu_order" class="form-control" value="1" min="1" required style="border-radius: 8px;">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">File Path *</label>
                                <input type="text" name="file_path" class="form-control" placeholder="e.g., modules/admin/dashboard.php" required style="border-radius: 8px;">
                                <small class="text-muted">Relative path from root directory</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Description *</label>
                                <input type="text" name="description" class="form-control" placeholder="e.g., Dashboard" required style="border-radius: 8px;">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Icon</label>
                                <div class="input-group">
                                    <span class="input-group-text" style="border-radius: 8px 0 0 8px;">
                                        <i class="bi bi-palette"></i>
                                    </span>
                                    <input type="text" name="icon" class="form-control" placeholder="e.g., speedometer2" value="file-earmark" style="border-radius: 0 8px 8px 0;">
                                </div>
                                <small class="text-muted">Bootstrap Icons name (without 'bi-' prefix)</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Parent Menu</label>
                                    <select name="parent_id" class="form-select" style="border-radius: 8px;">
                                        <option value="0">🏠 Main Menu (No Parent)</option>
                                        <?php foreach ($main_menu_items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>">📁 <?php echo htmlspecialchars($item['description']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Menu Type</label>
                                    <select name="is_submenu" class="form-select" style="border-radius: 8px;">
                                        <option value="0">📋 Main Menu Item</option>
                                        <option value="1">📑 Sub Menu Item</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" name="add_permission" class="btn btn-primary w-100" style="border-radius: 8px; padding: 12px;">
                                <i class="bi bi-plus-circle me-2"></i>Add Menu Item
                            </button>
                            <small class="text-muted d-block mt-2 text-center">* Required fields</small>
                        </form>
                    </div>

                    <!-- Quick Actions -->
                    <div class="form-section glass-card">
                        <div class="section-header">
                            <i class="bi bi-lightning"></i>
                            <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <a href="#" class="quick-action-btn" onclick="addSampleData()">
                                    <i class="bi bi-magic display-6 text-info mb-3"></i>
                                    <h6 class="fw-bold mb-1">Add Sample Data</h6>
                                    <small class="text-muted">Predefined menu items</small>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="?clear_all=1" class="quick-action-btn" onclick="return confirm('Are you sure? This will delete all menu items!')">
                                    <i class="bi bi-trash display-6 text-danger mb-3"></i>
                                    <h6 class="fw-bold mb-1">Clear All</h6>
                                    <small class="text-muted">Remove all items</small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Preview and List -->
                <div class="col-lg-7">
                    <!-- Sidebar Preview -->
                    <div class="sidebar-preview glass-card">
                        <div class="preview-header">
                            <i class="bi bi-eye"></i>
                            <h5 class="mb-0">Sidebar Preview</h5>
                        </div>
                        <div class="preview-content">
                            <?php
                            // Show preview for admin
                            $_SESSION['user_type'] = 'admin';
                            echo generateSidebar();
                            ?>
                        </div>
                    </div>

                    <!-- Existing Menu Items -->
                    <div class="form-section glass-card">
                        <div class="section-header">
                            <i class="bi bi-list-check"></i>
                            <h5 class="mb-0">
                                Existing Menu Items 
                                <span class="badge bg-primary ms-2"><?php echo count($permissions); ?></span>
                            </h5>
                        </div>
                        
                        <?php if (empty($permissions)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-1 text-muted"></i>
                                <h5 class="text-muted mt-3">No menu items found</h5>
                                <p class="text-muted">Add your first menu item using the form</p>
                            </div>
                        <?php else: ?>
                            <div class="menu-items-list">
                                <?php foreach ($permissions as $permission): ?>
                                    <div class="menu-item-card <?php echo $permission['is_submenu'] ? 'submenu' : ''; ?>">
                                        <div class="row align-items-center">
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center">
                                                    <i class="<?php echo $permission['icon']; ?> me-3 text-primary fs-5"></i>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($permission['description']); ?></h6>
                                                        <small class="text-muted"><?php echo $permission['file_path']; ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <span class="badge user-type-badge badge-<?php echo $permission['user_type']; ?>">
                                                    <?php echo $permission['user_type']; ?>
                                                </span>
                                                <?php if ($permission['is_submenu']): ?>
                                                    <span class="badge bg-success ms-1">Sub</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info ms-1">Main</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-3 text-end action-buttons">
                                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="editPermission(<?php echo $permission['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $permission['id']; ?>">
                                                    <button type="submit" name="delete_permission" class="btn btn-outline-danger btn-sm" 
                                                        onclick="return confirm('Are you sure you want to delete this menu item?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header" style="border-radius: 15px 15px 0 0; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white;">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i>Edit Menu Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <input type="hidden" name="update_permission" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">User Type *</label>
                                <select name="user_type" id="edit_user_type" class="form-select" required style="border-radius: 8px;">
                                    <option value="admin">👑 Admin</option>
                                    <option value="manager">💼 Manager</option>
                                    <option value="credit_officer">👨‍💼 Credit Officer</option>
                                    <option value="accountant">📊 Accountant</option>
                                    <option value="user">👤 User</option>
                                    <option value="customer">👥 Customer</option>
                                    <option value="staff">👨‍💻 Staff</option>
                                    <option value="cbo">🏢 CBO</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Menu Order *</label>
                                <input type="number" name="menu_order" id="edit_menu_order" class="form-control" required min="1" style="border-radius: 8px;">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">File Path *</label>
                            <input type="text" name="file_path" id="edit_file_path" class="form-control" required style="border-radius: 8px;">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Description *</label>
                            <input type="text" name="description" id="edit_description" class="form-control" required style="border-radius: 8px;">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Icon</label>
                            <div class="input-group">
                                <span class="input-group-text" style="border-radius: 8px 0 0 8px;">
                                    <i class="bi bi-palette"></i>
                                </span>
                                <input type="text" name="icon" id="edit_icon" class="form-control" style="border-radius: 0 8px 8px 0;">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Parent Menu</label>
                                <select name="parent_id" id="edit_parent_id" class="form-select" style="border-radius: 8px;">
                                    <option value="0">🏠 Main Menu (No Parent)</option>
                                    <?php foreach ($main_menu_items as $item): ?>
                                        <option value="<?php echo $item['id']; ?>">📁 <?php echo htmlspecialchars($item['description']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Menu Type</label>
                                <select name="is_submenu" id="edit_is_submenu" class="form-select" style="border-radius: 8px;">
                                    <option value="0">📋 Main Menu Item</option>
                                    <option value="1">📑 Sub Menu Item</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" id="edit_status" class="form-select" style="border-radius: 8px;">
                                <option value="active">✅ Active</option>
                                <option value="inactive">❌ Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-radius: 0 0 15px 15px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
                        <button type="submit" class="btn btn-primary" style="border-radius: 8px;">
                            <i class="bi bi-check-circle me-2"></i>Update Menu Item
                        </button>
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
                } else {
                    alert('Error loading permission data');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading permission data');
            });
    }

    function addSampleData() {
        if (confirm('This will add sample menu items. Continue?')) {
            // You can implement sample data insertion here
            alert('Sample data feature coming soon!');
        }
    }

    // Clear all functionality
    <?php if (isset($_GET['clear_all'])): ?>
        if (confirm('Are you sure you want to delete ALL menu items? This cannot be undone!')) {
            window.location.href = 'clear_permissions.php';
        } else {
            window.location.href = 'manage_sidebar.php';
        }
    <?php endif; ?>
    </script>
</body>
</html>