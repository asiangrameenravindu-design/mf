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
$cbo_details = null;
$cbo_groups = [];
$cbo_members = [];

// Get all CBOs
$cbos = getCBOs();

// Handle CBO selection
if (isset($_GET['cbo_id']) || isset($_POST['cbo_id'])) {
    $selected_cbo_id = $_GET['cbo_id'] ?? $_POST['cbo_id'] ?? '';
    
    if (!empty($selected_cbo_id)) {
        // Get CBO details
        $cbo_details = getCBOById($selected_cbo_id);
        
        if ($cbo_details) {
            // Get groups for this CBO
            $cbo_groups = getCBOActiveGroups($selected_cbo_id);
            
            // Get CBO members
            $cbo_members = getCBOActiveMembers($selected_cbo_id);
        }
    }
}

// Handle member transfer between groups
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_member'])) {
    $member_id = $_POST['member_id'] ?? '';
    $new_group_id = $_POST['new_group_id'] ?? '';
    $cbo_id = $_POST['cbo_id'] ?? '';
    
    if (empty($member_id) || empty($new_group_id) || empty($cbo_id)) {
        $error = "Please select member and target group!";
    } else {
        try {
            // Get customer ID from member ID
            $customer_sql = "SELECT customer_id FROM cbo_members WHERE id = ?";
            $customer_stmt = $conn->prepare($customer_sql);
            $customer_stmt->bind_param("i", $member_id);
            $customer_stmt->execute();
            $customer_result = $customer_stmt->get_result();
            $customer_id = $customer_result->fetch_assoc()['customer_id'];
            $customer_stmt->close();
            
            // Remove member from current group
            removeCustomerFromCBOGroups($customer_id, $cbo_id);
            
            // Add member to new group
            $insert_sql = "INSERT INTO group_members (group_id, customer_id, joined_date) 
                          VALUES (?, ?, CURDATE())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $new_group_id, $customer_id);
            
            if ($insert_stmt->execute()) {
                $success = "Member transferred to new group successfully!";
            } else {
                $error = "Database error: " . $insert_stmt->error;
            }
            $insert_stmt->close();
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBO Overview - Micro Finance System</title>
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
        
        .cbo-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .group-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .member-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 0.9rem;
        }
        .group-badge {
            background: #667eea;
            color: white;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.8rem;
        }
        
        /* Page header styles */
        .page-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
                                <li class="breadcrumb-item active text-primary fw-semibold">CBO Overview</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1 fw-bold text-dark">
                            <i class="bi bi-eye text-primary me-2"></i>CBO Overview
                        </h1>
                        <p class="text-muted mb-0">Comprehensive view of CBO details and members</p>
                    </div>
                    <div class="col-auto">
                        <a href="new.php" class="btn btn-primary">
                            <i class="bi bi-building me-2"></i>New CBO
                        </a>
                    </div>
                </div>
            </div>

            <!-- CBO Selection -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-building me-2"></i>Select CBO
                    </h5>
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <select class="form-control" name="cbo_id" required 
                                    onchange="this.form.submit()">
                                <option value="">Select CBO</option>
                                <?php while ($cbo = $cbos->fetch_assoc()): ?>
                                    <option value="<?php echo $cbo['id']; ?>" 
                                        <?php echo (isset($selected_cbo_id) && $selected_cbo_id == $cbo['id']) ? 'selected' : ''; ?>>
                                        <?php echo $cbo['name'] . ' (#' . $cbo['cbo_number'] . ')'; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-2"></i>View CBO
                            </button>
                        </div>
                    </form>
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

            <?php if ($cbo_details): ?>
            <!-- CBO Header -->
            <div class="cbo-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="h2 mb-2"><?php echo $cbo_details['name']; ?></h1>
                        <p class="mb-1 opacity-75">
                            <i class="bi bi-hash me-2"></i>CBO Number: <?php echo $cbo_details['cbo_number']; ?>
                        </p>
                        <p class="mb-1 opacity-75">
                            <i class="bi bi-calendar3 me-2"></i>Meeting Day: <?php echo ucfirst($cbo_details['meeting_day']); ?>
                        </p>
                        <p class="mb-0 opacity-75">
                            <i class="bi bi-person-badge me-2"></i>Field Officer: <?php echo $cbo_details['staff_name']; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="cbo-number display-4 fw-bold opacity-75">
                            #<?php echo $cbo_details['cbo_number']; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <i class="bi bi-people fs-1 text-primary d-block mb-2"></i>
                        <h3 class="mb-1"><?php echo $cbo_members->num_rows; ?></h3>
                        <p class="text-muted mb-0">Total Members</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <i class="bi bi-diagram-3 fs-1 text-success d-block mb-2"></i>
                        <h3 class="mb-1"><?php echo $cbo_groups->num_rows; ?></h3>
                        <p class="text-muted mb-0">Groups</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <i class="bi bi-cash-coin fs-1 text-warning d-block mb-2"></i>
                        <h3 class="mb-1">
                            <?php
                            $loans_count = $conn->query("
                                SELECT COUNT(*) as count 
                                FROM loans l 
                                JOIN cbo_members cm ON l.customer_id = cm.customer_id 
                                WHERE cm.cbo_id = " . $selected_cbo_id . " AND cm.status = 'active'
                            ")->fetch_assoc()['count'];
                            echo $loans_count;
                            ?>
                        </h3>
                        <p class="text-muted mb-0">Total Loans</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <i class="bi bi-telephone fs-1 text-info d-block mb-2"></i>
                        <h6 class="mb-1">
                            <?php 
                            $staff_phone = getStaffById($cbo_details['staff_id'])['phone'] ?? 'N/A';
                            echo $staff_phone;
                            ?>
                        </h6>
                        <p class="text-muted mb-0">Contact</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Groups Section -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-diagram-3 me-2"></i>Groups
                                <span class="badge bg-light text-dark ms-2">
                                    <?php echo $cbo_groups->num_rows; ?>
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($cbo_groups->num_rows > 0): ?>
                                <div class="row">
                                    <?php 
                                    $cbo_groups->data_seek(0);
                                    while ($group = $cbo_groups->fetch_assoc()): 
                                        // Get group members
                                        $group_members = getGroupMembers($group['id']);
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="group-card p-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0"><?php echo $group['group_name']; ?></h6>
                                                <span class="group-badge">Group <?php echo $group['group_number']; ?></span>
                                            </div>
                                            <small class="text-muted d-block mb-2">
                                                <?php echo $group['member_count']; ?> members
                                            </small>
                                            <?php if ($group_members->num_rows > 0): ?>
                                                <div class="member-list">
                                                    <?php while ($member = $group_members->fetch_assoc()): ?>
                                                        <div class="d-flex align-items-center mb-1">
                                                            <div class="member-avatar me-2" style="width: 25px; height: 25px; font-size: 0.7rem;">
                                                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                                            </div>
                                                            <small class="text-muted"><?php echo $member['full_name']; ?></small>
                                                        </div>
                                                    <?php endwhile; ?>
                                                </div>
                                            <?php else: ?>
                                                <small class="text-muted">No members</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-diagram-3 display-4 text-muted d-block mb-2"></i>
                                    <p class="text-muted">No groups created</p>
                                    <a href="groups.php?cbo_id=<?php echo $selected_cbo_id; ?>" class="btn btn-primary btn-sm">
                                        Create Groups
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Members Section -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-success text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-people-fill me-2"></i>Members
                                <span class="badge bg-light text-dark ms-2">
                                    <?php echo $cbo_members->num_rows; ?>
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($cbo_members->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Member</th>
                                                <th>NIC</th>
                                                <th>Group</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($member = $cbo_members->fetch_assoc()): 
                                                $customer_group = getCustomerGroupInCBO($member['customer_id'], $selected_cbo_id);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="member-avatar me-2">
                                                            <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-semibold small"><?php echo $member['full_name']; ?></div>
                                                            <small class="text-muted"><?php echo $member['short_name']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo $member['national_id']; ?></td>
                                                <td>
                                                    <?php if ($customer_group): ?>
                                                        <span class="badge bg-primary">Group <?php echo $customer_group['group_number']; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No Group</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($cbo_groups->num_rows > 0): ?>
                                                    <form method="POST" class="d-flex">
                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                        <input type="hidden" name="cbo_id" value="<?php echo $selected_cbo_id; ?>">
                                                        <select class="form-control form-control-sm me-2" name="new_group_id" required>
                                                            <option value="">Move to...</option>
                                                            <?php 
                                                            $cbo_groups->data_seek(0);
                                                            while ($group = $cbo_groups->fetch_assoc()): ?>
                                                                <option value="<?php echo $group['id']; ?>">
                                                                    Group <?php echo $group['group_number']; ?>
                                                                </option>
                                                            <?php endwhile; ?>
                                                        </select>
                                                        <button type="submit" name="transfer_member" class="btn btn-warning btn-sm">
                                                            <i class="bi bi-arrow-left-right"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-people display-4 text-muted d-block mb-2"></i>
                                    <p class="text-muted">No members in this CBO</p>
                                    <a href="add_member.php?cbo_id=<?php echo $selected_cbo_id; ?>" class="btn btn-success btn-sm">
                                        Add Members
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-building display-1 text-muted mb-3"></i>
                    <h5 class="text-muted">Select a CBO</h5>
                    <p class="text-muted">Please select a CBO to view details</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>