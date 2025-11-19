<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Initialize variables
$success = '';
$error = '';
$groups = [];
$cbo_members = [];
$selected_cbo_id = '';

// Get all CBOs
$cbos = getCBOs();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create new group
    if (isset($_POST['create_group'])) {
        $cbo_id = $_POST['cbo_id'] ?? '';
        $group_name = trim($_POST['group_name'] ?? '');
        
        if (empty($cbo_id) || empty($group_name)) {
            $error = "Please select CBO and enter group name!";
        } else {
            try {
                // Check if group name already exists in this CBO
                if (isGroupNameExistsInCBO($cbo_id, $group_name)) {
                    $error = "Group name '$group_name' already exists in this CBO!";
                } else {
                    // Get next available group number for this CBO
                    $next_group_number = getNextGroupNumber($cbo_id);
                    
                    // Create new group
                    $insert_sql = "INSERT INTO groups (cbo_id, group_number, group_name) 
                                  VALUES (?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iis", $cbo_id, $next_group_number, $group_name);
                    
                    if ($insert_stmt->execute()) {
                        $success = "Group '$group_name' created successfully! Group Number: $next_group_number";
                        $_POST['group_name'] = '';
                        $selected_cbo_id = $cbo_id; // Refresh the view
                    } else {
                        $error = "Failed to create group: " . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Delete group
    if (isset($_POST['delete_group'])) {
        $group_id = $_POST['group_id'] ?? '';
        
        if (!empty($group_id)) {
            try {
                // Check if group has members
                $member_check_sql = "SELECT COUNT(*) as member_count FROM group_members WHERE group_id = ?";
                $member_check_stmt = $conn->prepare($member_check_sql);
                $member_check_stmt->bind_param("i", $group_id);
                $member_check_stmt->execute();
                $member_check_result = $member_check_stmt->get_result();
                $member_count = $member_check_result->fetch_assoc()['member_count'];
                $member_check_stmt->close();
                
                if ($member_count > 0) {
                    $error = "Cannot delete group that has members! Remove all members first.";
                } else {
                    // Get CBO ID before deletion for refresh
                    $cbo_sql = "SELECT cbo_id FROM groups WHERE id = ?";
                    $cbo_stmt = $conn->prepare($cbo_sql);
                    $cbo_stmt->bind_param("i", $group_id);
                    $cbo_stmt->execute();
                    $cbo_result = $cbo_stmt->get_result();
                    $cbo_data = $cbo_result->fetch_assoc();
                    $selected_cbo_id = $cbo_data['cbo_id'];
                    $cbo_stmt->close();
                    
                    // Delete the group
                    $delete_sql = "DELETE FROM groups WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $group_id);
                    
                    if ($delete_stmt->execute()) {
                        $success = "Group deleted successfully!";
                    } else {
                        $error = "Database error: " . $delete_stmt->error;
                    }
                    $delete_stmt->close();
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Add member to group
    if (isset($_POST['add_to_group'])) {
        $group_id = $_POST['group_id'] ?? '';
        $customer_id = $_POST['customer_id'] ?? '';
        
        if (empty($group_id) || empty($customer_id)) {
            $error = "Please select group and customer!";
        } else {
            try {
                // Get CBO ID for refresh
                $cbo_sql = "SELECT cbo_id FROM groups WHERE id = ?";
                $cbo_stmt = $conn->prepare($cbo_sql);
                $cbo_stmt->bind_param("i", $group_id);
                $cbo_stmt->execute();
                $cbo_result = $cbo_stmt->get_result();
                $cbo_data = $cbo_result->fetch_assoc();
                $selected_cbo_id = $cbo_data['cbo_id'];
                $cbo_stmt->close();
                
                // Check group size (max 5 members)
                $size_sql = "SELECT COUNT(*) as member_count FROM group_members WHERE group_id = ?";
                $size_stmt = $conn->prepare($size_sql);
                $size_stmt->bind_param("i", $group_id);
                $size_stmt->execute();
                $size_result = $size_stmt->get_result();
                $member_count = $size_result->fetch_assoc()['member_count'];
                
                if ($member_count >= 5) {
                    $error = "Group is full! Maximum 5 members allowed per group.";
                } else {
                    // Check if customer is already in a group in this CBO
                    $check_sql = "SELECT gm.id, g.group_name 
                                 FROM group_members gm 
                                 JOIN groups g ON gm.group_id = g.id 
                                 WHERE gm.customer_id = ? AND g.cbo_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $customer_id, $selected_cbo_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $existing_group = $check_result->fetch_assoc();
                        $error = "Customer is already in group: " . $existing_group['group_name'] . "!";
                    } else {
                        // Add member to group
                        $insert_sql = "INSERT INTO group_members (group_id, customer_id, joined_date) 
                                      VALUES (?, ?, CURDATE())";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param("ii", $group_id, $customer_id);
                        
                        if ($insert_stmt->execute()) {
                            $success = "Customer added to group successfully!";
                        } else {
                            $error = "Database error: " . $insert_stmt->error;
                        }
                        $insert_stmt->close();
                    }
                    $check_stmt->close();
                }
                $size_stmt->close();
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Remove member from group
    if (isset($_POST['remove_from_group'])) {
        $member_id = $_POST['member_id'] ?? '';
        
        if (!empty($member_id)) {
            try {
                // Get group ID and CBO ID for refresh
                $info_sql = "SELECT gm.group_id, g.cbo_id 
                            FROM group_members gm 
                            JOIN groups g ON gm.group_id = g.id 
                            WHERE gm.id = ?";
                $info_stmt = $conn->prepare($info_sql);
                $info_stmt->bind_param("i", $member_id);
                $info_stmt->execute();
                $info_result = $info_stmt->get_result();
                $info_data = $info_result->fetch_assoc();
                $selected_cbo_id = $info_data['cbo_id'];
                $info_stmt->close();
                
                $delete_sql = "DELETE FROM group_members WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $member_id);
                
                if ($delete_stmt->execute()) {
                    $success = "Member removed from group successfully!";
                } else {
                    $error = "Database error: " . $delete_stmt->error;
                }
                $delete_stmt->close();
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get data based on selected CBO
if (isset($_GET['cbo_id']) || isset($_POST['cbo_id']) || !empty($selected_cbo_id)) {
    $selected_cbo_id = $_GET['cbo_id'] ?? $_POST['cbo_id'] ?? $selected_cbo_id;
    
    if (!empty($selected_cbo_id)) {
        // Get groups for this CBO
        $groups_sql = "SELECT g.*, 
                      (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
                      FROM groups g 
                      WHERE g.cbo_id = ? AND g.is_active = 1
                      ORDER BY g.group_number";
        $groups_stmt = $conn->prepare($groups_sql);
        $groups_stmt->bind_param("i", $selected_cbo_id);
        $groups_stmt->execute();
        $groups = $groups_stmt->get_result();
        $groups_stmt->close();
        
        // Get CBO members not in any group
        $members_sql = "SELECT c.* 
                       FROM cbo_members cm 
                       JOIN customers c ON cm.customer_id = c.id 
                       WHERE cm.cbo_id = ? AND cm.status = 'active'
                       AND c.id NOT IN (
                           SELECT gm.customer_id 
                           FROM group_members gm 
                           JOIN groups g ON gm.group_id = g.id 
                           WHERE g.cbo_id = ?
                       )
                       ORDER BY c.full_name";
        $members_stmt = $conn->prepare($members_sql);
        $members_stmt->bind_param("ii", $selected_cbo_id, $selected_cbo_id);
        $members_stmt->execute();
        $cbo_members = $members_stmt->get_result();
        $members_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Groups - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Custom styles for proper layout */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
        }
        
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                width: 100%;
            }
        }
        
        .group-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
            transition: transform 0.2s ease;
        }
        .group-card:hover {
            transform: translateY(-2px);
        }
        .member-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 0.8rem;
        }
        .available-member {
            border-left: 4px solid #28a745;
        }
        .group-badge {
            background: #667eea;
            color: white;
            border-radius: 15px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Page header styles */
        .page-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Ensure content doesn't overlap with sidebar */
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        
        .row {
            margin-left: 0;
            margin-right: 0;
        }
        
        .col-12 {
            padding-left: 0;
            padding-right: 0;
        }
        
        /* Card styles */
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: #4361ee;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
            border: none;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-2">
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php" class="text-decoration-none text-muted">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/modules/cbo/" class="text-decoration-none text-muted">CBO Management</a></li>
                                <li class="breadcrumb-item active text-primary fw-semibold">Manage Groups</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">
                            <i class="bi bi-diagram-3 text-primary me-2"></i>Manage Groups
                        </h1>
                        <p class="text-muted mb-0">Create and manage groups within CBOs</p>
                    </div>
                    <div class="col-auto">
                        <a href="overview.php" class="btn btn-outline-primary">
                            <i class="bi bi-eye me-2"></i>View CBOs
                        </a>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- CBO Selection and Group Creation -->
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building me-2"></i>Select CBO
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET">
                                <div class="mb-3">
                                    <label class="form-label">Select CBO</label>
                                    <select class="form-control" name="cbo_id" required 
                                            onchange="this.form.submit()">
                                        <option value="">Select CBO</option>
                                        <?php 
                                        $cbos->data_seek(0);
                                        while ($cbo = $cbos->fetch_assoc()): ?>
                                            <option value="<?php echo $cbo['id']; ?>" 
                                                <?php echo ($selected_cbo_id == $cbo['id']) ? 'selected' : ''; ?>>
                                                <?php echo $cbo['name'] . ' (#' . $cbo['cbo_number'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($selected_cbo_id)): ?>
                    <!-- Create New Group -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-plus-circle me-2"></i>Create New Group
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="createGroupForm">
                                <input type="hidden" name="cbo_id" value="<?php echo $selected_cbo_id; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Group Name</label>
                                    <input type="text" class="form-control" name="group_name" 
                                           value="<?php echo htmlspecialchars($_POST['group_name'] ?? ''); ?>" 
                                           required placeholder="Enter group name">
                                    <div class="form-text">Enter a unique name for this group</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Auto-generated Group Number</label>
                                    <input type="text" class="form-control bg-light" 
                                           value="<?php echo getNextGroupNumber($selected_cbo_id); ?>" 
                                           readonly>
                                    <div class="form-text">Group number is automatically generated</div>
                                </div>
                                <button type="submit" name="create_group" class="btn btn-success w-100">
                                    <i class="bi bi-plus-circle me-2"></i>Create Group
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Available Members -->
                    <?php if (!empty($selected_cbo_id)): ?>
                        <?php if ($cbo_members->num_rows > 0): ?>
                        <div class="card">
                            <div class="card-header bg-info text-white py-3">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-person-plus me-2"></i>Available Members
                                    <span class="badge bg-light text-dark ms-2"><?php echo $cbo_members->num_rows; ?></span>
                                </h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $cbo_members->data_seek(0);
                                    while ($member = $cbo_members->fetch_assoc()): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center available-member">
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($member['national_id']); ?></small>
                                            </div>
                                            <span class="badge bg-success">Available</span>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Groups and Members -->
                <div class="col-lg-8">
                    <?php if (empty($selected_cbo_id)): ?>
                        <!-- Empty State -->
                        <div class="card">
                            <div class="empty-state">
                                <i class="bi bi-building text-muted"></i>
                                <h4 class="text-muted">Select a CBO</h4>
                                <p class="text-muted">Please select a CBO to view and manage groups</p>
                            </div>
                        </div>
                    <?php elseif ($groups->num_rows == 0): ?>
                        <!-- No Groups State -->
                        <div class="card">
                            <div class="empty-state">
                                <i class="bi bi-diagram-3 text-muted"></i>
                                <h4 class="text-muted">No Groups Found</h4>
                                <p class="text-muted">Create your first group using the form on the left</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Groups List -->
                        <?php 
                        $groups->data_seek(0);
                        while ($group = $groups->fetch_assoc()): 
                            $group_members = getGroupMembers($group['id']);
                        ?>
                            <div class="group-card card mb-4">
                                <div class="card-header bg-white py-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <?php echo htmlspecialchars($group['group_name']); ?>
                                            </h5>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="group-badge">Group #<?php echo $group['group_number']; ?></span>
                                                <span class="badge bg-primary">
                                                    <?php echo $group_members->num_rows; ?>/5 Members
                                                </span>
                                            </div>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                    type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <?php if ($group_members->num_rows == 0): ?>
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                            <button type="submit" name="delete_group" 
                                                                    class="dropdown-item text-danger" 
                                                                    onclick="return confirm('Are you sure you want to delete this group?')">
                                                                <i class="bi bi-trash me-2"></i>Delete Group
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                <li>
                                                    <button class="dropdown-item" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#addMemberModal<?php echo $group['id']; ?>">
                                                        <i class="bi bi-person-plus me-2"></i>Add Member
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <!-- Group Members -->
                                    <?php if ($group_members->num_rows > 0): ?>
                                        <div class="row g-2">
                                            <?php 
                                            $group_members->data_seek(0);
                                            while ($member = $group_members->fetch_assoc()): ?>
                                                <div class="col-md-6">
                                                    <div class="d-flex align-items-center p-2 border rounded">
                                                        <div class="member-avatar me-3">
                                                            <?php echo strtoupper(substr($member['short_name'], 0, 2)); ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="fw-bold"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($member['national_id']); ?></small>
                                                        </div>
                                                        <form method="POST" class="ms-2">
                                                            <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                            <button type="submit" name="remove_from_group" 
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('Remove this member from group?')">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="bi bi-people display-4 d-block mb-2"></i>
                                            <p>No members in this group</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Add Member Modal -->
                            <div class="modal fade" id="addMemberModal<?php echo $group['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Add Member to <?php echo htmlspecialchars($group['group_name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Select Member</label>
                                                    <select class="form-control" name="customer_id" required>
                                                        <option value="">Select Member</option>
                                                        <?php 
                                                        $cbo_members->data_seek(0);
                                                        while ($member = $cbo_members->fetch_assoc()): ?>
                                                            <option value="<?php echo $member['id']; ?>">
                                                                <?php echo htmlspecialchars($member['full_name'] . ' - ' . $member['national_id']); ?>
                                                            </option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </div>
                                                <?php if ($group_members->num_rows >= 5): ?>
                                                    <div class="alert alert-warning">
                                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                                        This group is full (5/5 members). Cannot add more members.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="add_to_group" class="btn btn-primary"
                                                        <?php echo ($group_members->num_rows >= 5) ? 'disabled' : ''; ?>>
                                                    Add to Group
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>