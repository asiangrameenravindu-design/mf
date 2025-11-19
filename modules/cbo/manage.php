[file name]: manage.php
[file content begin]
<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$success = '';
$error = '';

// Get all CBOs
$cbos = getCBOs();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update CBO details
    if (isset($_POST['update_cbo'])) {
        $cbo_id = $_POST['cbo_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $staff_id = trim($_POST['staff_id'] ?? '');
        $meeting_day = trim($_POST['meeting_day'] ?? '');
        
        if (empty($cbo_id) || empty($name) || empty($staff_id) || empty($meeting_day)) {
            $error = "All fields are required!";
        } else {
            try {
                // Check if CBO name already exists (excluding current CBO)
                $check_sql = "SELECT id FROM cbo WHERE name = ? AND id != ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("si", $name, $cbo_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error = "CBO with name '$name' already exists!";
                } else {
                    // Update CBO
                    $sql = "UPDATE cbo SET name = ?, staff_id = ?, meeting_day = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sisi", $name, $staff_id, $meeting_day, $cbo_id);
                    
                    if ($stmt->execute()) {
                        $success = "CBO updated successfully!";
                    } else {
                        $error = "Database error: " . $stmt->error;
                    }
                    $stmt->close();
                }
                $check_stmt->close();
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Delete CBO
    if (isset($_POST['delete_cbo'])) {
        $cbo_id = $_POST['cbo_id'] ?? '';
        
        if (!empty($cbo_id)) {
            try {
                // Check if CBO has active members
                $member_check_sql = "SELECT COUNT(*) as member_count FROM cbo_members WHERE cbo_id = ? AND status = 'active'";
                $member_check_stmt = $conn->prepare($member_check_sql);
                $member_check_stmt->bind_param("i", $cbo_id);
                $member_check_stmt->execute();
                $member_check_result = $member_check_stmt->get_result();
                $member_count = $member_check_result->fetch_assoc()['member_count'];
                $member_check_stmt->close();
                
                if ($member_count > 0) {
                    $error = "Cannot delete CBO that has active members! Remove all members first.";
                } else {
                    // Check if CBO has active groups
                    $group_check_sql = "SELECT COUNT(*) as group_count FROM groups WHERE cbo_id = ? AND is_active = TRUE";
                    $group_check_stmt = $conn->prepare($group_check_sql);
                    $group_check_stmt->bind_param("i", $cbo_id);
                    $group_check_stmt->execute();
                    $group_check_result = $group_check_stmt->get_result();
                    $group_count = $group_check_result->fetch_assoc()['group_count'];
                    $group_check_stmt->close();
                    
                    if ($group_count > 0) {
                        $error = "Cannot delete CBO that has active groups! Delete all groups first.";
                    } else {
                        // Check if CBO has associated loans
                        $loan_check_sql = "SELECT COUNT(*) as loan_count FROM loans WHERE cbo_id = ?";
                        $loan_check_stmt = $conn->prepare($loan_check_sql);
                        $loan_check_stmt->bind_param("i", $cbo_id);
                        $loan_check_stmt->execute();
                        $loan_check_result = $loan_check_stmt->get_result();
                        $loan_count = $loan_check_result->fetch_assoc()['loan_count'];
                        $loan_check_stmt->close();
                        
                        if ($loan_count > 0) {
                            $error = "Cannot delete CBO that has associated loans!";
                        } else {
                            // Delete CBO (this will also delete cbo_members due to foreign key constraints)
                            $delete_sql = "DELETE FROM cbo WHERE id = ?";
                            $delete_stmt = $conn->prepare($delete_sql);
                            $delete_stmt->bind_param("i", $cbo_id);
                            
                            if ($delete_stmt->execute()) {
                                $success = "CBO deleted successfully!";
                            } else {
                                $error = "Database error: " . $delete_stmt->error;
                            }
                            $delete_stmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get field officers for dropdown
$field_officers = getStaffByPosition('field_officer');

// Get selected CBO details
$selected_cbo = null;
if (isset($_GET['cbo_id'])) {
    $selected_cbo_id = $_GET['cbo_id'];
    $selected_cbo = getCBOById($selected_cbo_id);
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage CBO - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .main-content { 
            margin-left: 280px; 
            padding: 20px; 
            margin-top: 56px; 
            background-color: #f8f9fa; 
            min-height: calc(100vh - 56px); 
        }
        @media (max-width: 768px) { 
            .main-content { 
                margin-left: 0; 
            } 
        }
        .cbo-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            transition: transform 0.2s ease;
        }
        .cbo-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- Include Header -->
    <?php include '../../includes/header.php'; ?>

    <!-- Include Sidebar -->
    <?php include '../../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container-fluid py-4">
            <!-- Page Header -->
            <div class="row align-items-center mb-4">
                <div class="col">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-gear text-primary me-2"></i>Manage CBO
                    </h1>
                    <p class="text-muted mb-0">Update or delete CBO information</p>
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
                <!-- CBO Selection -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building me-2"></i>Select CBO
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET">
                                <div class="mb-3">
                                    <label class="form-label">Select CBO to Manage</label>
                                    <select class="form-control" name="cbo_id" required 
                                            onchange="this.form.submit()">
                                        <option value="">Select CBO</option>
                                        <?php while ($cbo = $cbos->fetch_assoc()): ?>
                                            <option value="<?php echo $cbo['id']; ?>" 
                                                <?php echo (isset($selected_cbo) && $selected_cbo['id'] == $cbo['id']) ? 'selected' : ''; ?>>
                                                <?php echo $cbo['name'] . ' (#' . $cbo['cbo_number'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- CBO List -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-list-check me-2"></i>All CBOs
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($cbos->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $cbos->data_seek(0);
                                    while ($cbo = $cbos->fetch_assoc()): 
                                        // Get member count for this CBO
                                        $member_count_sql = "SELECT COUNT(*) as count FROM cbo_members WHERE cbo_id = ? AND status = 'active'";
                                        $member_count_stmt = $conn->prepare($member_count_sql);
                                        $member_count_stmt->bind_param("i", $cbo['id']);
                                        $member_count_stmt->execute();
                                        $member_count_result = $member_count_stmt->get_result();
                                        $member_count = $member_count_result->fetch_assoc()['count'];
                                        $member_count_stmt->close();
                                    ?>
                                    <a href="manage.php?cbo_id=<?php echo $cbo['id']; ?>" 
                                       class="list-group-item list-group-item-action d-flex align-items-center 
                                              <?php echo (isset($selected_cbo) && $selected_cbo['id'] == $cbo['id']) ? 'active' : ''; ?>">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?php echo $cbo['name']; ?></h6>
                                            <small class="<?php echo (isset($selected_cbo) && $selected_cbo['id'] == $cbo['id']) ? 'text-light' : 'text-muted'; ?>">
                                                #<?php echo $cbo['cbo_number']; ?> • 
                                                <?php echo ucfirst($cbo['meeting_day']); ?>
                                            </small>
                                            <div class="mt-1">
                                                <small class="<?php echo (isset($selected_cbo) && $selected_cbo['id'] == $cbo['id']) ? 'text-light' : 'text-primary'; ?>">
                                                    <i class="bi bi-people me-1"></i><?php echo $member_count; ?> members
                                                </small>
                                            </div>
                                        </div>
                                        <i class="bi bi-chevron-right <?php echo (isset($selected_cbo) && $selected_cbo['id'] == $cbo['id']) ? 'text-light' : 'text-muted'; ?>"></i>
                                    </a>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-building display-4 d-block mb-2"></i>
                                    <p>No CBOs created yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- CBO Management -->
                <div class="col-lg-8">
                    <?php if ($selected_cbo): ?>
                        <!-- Update CBO Form -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-warning text-dark py-3">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pencil-square me-2"></i>Update CBO Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="cbo_id" value="<?php echo $selected_cbo['id']; ?>">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">CBO Name</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="name" 
                                                   value="<?php echo htmlspecialchars($selected_cbo['name']); ?>" 
                                                   required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Field Officer</label>
                                            <select class="form-control" name="staff_id" required>
                                                <option value="">Select Field Officer</option>
                                                <?php while ($officer = $field_officers->fetch_assoc()): ?>
                                                    <option value="<?php echo $officer['id']; ?>" 
                                                        <?php echo ($selected_cbo['staff_id'] == $officer['id']) ? 'selected' : ''; ?>>
                                                        <?php echo $officer['full_name'] . ' (' . $officer['short_name'] . ')'; ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Meeting Day</label>
                                            <select class="form-control" name="meeting_day" required>
                                                <option value="monday" <?php echo ($selected_cbo['meeting_day'] == 'monday') ? 'selected' : ''; ?>>Monday</option>
                                                <option value="tuesday" <?php echo ($selected_cbo['meeting_day'] == 'tuesday') ? 'selected' : ''; ?>>Tuesday</option>
                                                <option value="wednesday" <?php echo ($selected_cbo['meeting_day'] == 'wednesday') ? 'selected' : ''; ?>>Wednesday</option>
                                                <option value="thursday" <?php echo ($selected_cbo['meeting_day'] == 'thursday') ? 'selected' : ''; ?>>Thursday</option>
                                                <option value="friday" <?php echo ($selected_cbo['meeting_day'] == 'friday') ? 'selected' : ''; ?>>Friday</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" name="update_cbo" class="btn btn-warning w-100">
                                                <i class="bi bi-check-circle me-2"></i>Update CBO
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Delete CBO -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-danger text-white py-3">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-trash me-2"></i>Delete CBO
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <h6 class="alert-heading">
                                        <i class="bi bi-exclamation-triangle me-2"></i>Warning
                                    </h6>
                                    <p class="mb-2">This action cannot be undone. Before deleting this CBO, ensure that:</p>
                                    <ul class="mb-2">
                                        <li>All members have been removed from the CBO</li>
                                        <li>All groups have been deleted</li>
                                        <li>No loans are associated with this CBO</li>
                                    </ul>
                                    <p class="mb-0"><strong>This will permanently delete the CBO and all its membership records.</strong></p>
                                </div>
                                
                                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this CBO? This action cannot be undone!');">
                                    <input type="hidden" name="cbo_id" value="<?php echo $selected_cbo['id']; ?>">
                                    <button type="submit" name="delete_cbo" class="btn btn-danger w-100">
                                        <i class="bi bi-trash me-2"></i>Delete CBO Permanently
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- CBO Statistics -->
                        <div class="card border-0 shadow-sm mt-4">
                            <div class="card-header bg-info text-white py-3">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>CBO Statistics
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="border rounded p-3">
                                            <?php
                                            $active_members = $conn->query("
                                                SELECT COUNT(*) as count FROM cbo_members 
                                                WHERE cbo_id = " . $selected_cbo['id'] . " AND status = 'active'
                                            ")->fetch_assoc()['count'];
                                            ?>
                                            <h3 class="text-primary mb-1"><?php echo $active_members; ?></h3>
                                            <small class="text-muted">Active Members</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-3">
                                            <?php
                                            $active_groups = $conn->query("
                                                SELECT COUNT(*) as count FROM groups 
                                                WHERE cbo_id = " . $selected_cbo['id'] . " AND is_active = TRUE
                                            ")->fetch_assoc()['count'];
                                            ?>
                                            <h3 class="text-success mb-1"><?php echo $active_groups; ?></h3>
                                            <small class="text-muted">Active Groups</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-3">
                                            <?php
                                            $total_loans = $conn->query("
                                                SELECT COUNT(*) as count FROM loans 
                                                WHERE cbo_id = " . $selected_cbo['id'] . "
                                            ")->fetch_assoc()['count'];
                                            ?>
                                            <h3 class="text-warning mb-1"><?php echo $total_loans; ?></h3>
                                            <small class="text-muted">Total Loans</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-building display-1 text-muted mb-3"></i>
                            <h5 class="text-muted">Select a CBO</h5>
                            <p class="text-muted">Please select a CBO to manage</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Include Footer -->
    <?php include '../../includes/footer.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
[file content end]