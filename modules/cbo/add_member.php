<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$success = '';
$error = '';
$cbo_members = [];

// Get all CBOs
$cbos = getCBOs();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add member to CBO
    if (isset($_POST['add_member'])) {
        $cbo_id = $_POST['cbo_id'] ?? '';
        $customer_nic = trim($_POST['customer_nic'] ?? '');
        
        if (empty($cbo_id) || empty($customer_nic)) {
            $error = "Please select CBO and enter customer NIC!";
        } else {
            try {
                // Get customer ID from NIC
                $customer_sql = "SELECT id FROM customers WHERE national_id = ?";
                $customer_stmt = $conn->prepare($customer_sql);
                $customer_stmt->bind_param("s", $customer_nic);
                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();
                
                if ($customer_result->num_rows === 0) {
                    $error = "Customer with NIC $customer_nic not found!";
                } else {
                    $customer_id = $customer_result->fetch_assoc()['id'];
                    
                    // Check if customer can change CBO (no active loans)
                    $loan_check = canCustomerChangeCBO($customer_id);
                    if (!$loan_check['can_change']) {
                        $error = $loan_check['reason'];
                    } else {
                        // Check if customer is already active in ANY CBO
                        $active_cbo_sql = "SELECT cbo_id FROM cbo_members 
                                          WHERE customer_id = ? AND status = 'active'";
                        $active_cbo_stmt = $conn->prepare($active_cbo_sql);
                        $active_cbo_stmt->bind_param("i", $customer_id);
                        $active_cbo_stmt->execute();
                        $active_cbo_result = $active_cbo_stmt->get_result();
                        
                        if ($active_cbo_result->num_rows > 0) {
                            $existing_cbo = $active_cbo_result->fetch_assoc()['cbo_id'];
                            $error = "Customer is already active in CBO ID: $existing_cbo! Cannot join another CBO.";
                        } else {
                            // Check if customer was previously in this CBO (inactive)
                            $check_sql = "SELECT id, status FROM cbo_members 
                                         WHERE cbo_id = ? AND customer_id = ?";
                            $check_stmt = $conn->prepare($check_sql);
                            $check_stmt->bind_param("ii", $cbo_id, $customer_id);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            
                            if ($check_result->num_rows > 0) {
                                $existing_member = $check_result->fetch_assoc();
                                
                                if ($existing_member['status'] === 'inactive') {
                                    // Reactivate existing membership
                                    if (reactivateCBOMembership($cbo_id, $customer_id)) {
                                        $success = "Customer re-joined CBO successfully!";
                                        $_POST['customer_nic'] = '';
                                    } else {
                                        $error = "Failed to reactivate CBO membership!";
                                    }
                                } else {
                                    $error = "Customer is already an active member of this CBO!";
                                }
                            } else {
                                // Add new member to CBO
                                $insert_sql = "INSERT INTO cbo_members (cbo_id, customer_id, joined_date, status) 
                                              VALUES (?, ?, CURDATE(), 'active')";
                                $insert_stmt = $conn->prepare($insert_sql);
                                $insert_stmt->bind_param("ii", $cbo_id, $customer_id);
                                
                                if ($insert_stmt->execute()) {
                                    $success = "Customer successfully added to CBO!";
                                    $_POST['customer_nic'] = '';
                                } else {
                                    // Check if it's a duplicate entry error
                                    if ($conn->errno === 1062) {
                                        $error = "Customer is already active in another CBO!";
                                    } else {
                                        $error = "Database error: " . $insert_stmt->error;
                                    }
                                }
                                $insert_stmt->close();
                            }
                            $check_stmt->close();
                        }
                        $active_cbo_stmt->close();
                    }
                }
                $customer_stmt->close();
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Remove member from CBO
    if (isset($_POST['remove_member'])) {
        $member_id = $_POST['member_id'] ?? '';
        $leave_reason = trim($_POST['leave_reason'] ?? '');
        
        if (!empty($member_id)) {
            // Get customer ID from member ID
            $customer_sql = "SELECT customer_id FROM cbo_members WHERE id = ?";
            $customer_stmt = $conn->prepare($customer_sql);
            $customer_stmt->bind_param("i", $member_id);
            $customer_stmt->execute();
            $customer_result = $customer_stmt->get_result();
            
            if ($customer_result->num_rows > 0) {
                $customer_id = $customer_result->fetch_assoc()['customer_id'];
                
                // Check if customer can leave CBO (no active loans)
                $loan_check = canCustomerChangeCBO($customer_id);
                if (!$loan_check['can_change']) {
                    $error = $loan_check['reason'];
                } else {
                    // Remove from any groups in this CBO first
                    $cbo_sql = "SELECT cbo_id FROM cbo_members WHERE id = ?";
                    $cbo_stmt = $conn->prepare($cbo_sql);
                    $cbo_stmt->bind_param("i", $member_id);
                    $cbo_stmt->execute();
                    $cbo_result = $cbo_stmt->get_result();
                    $cbo_id = $cbo_result->fetch_assoc()['cbo_id'];
                    
                    removeCustomerFromCBOGroups($customer_id, $cbo_id);
                    
                    // Mark as inactive in CBO
                    $update_sql = "UPDATE cbo_members 
                                  SET status = 'inactive', left_date = CURDATE(), left_reason = ?
                                  WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("si", $leave_reason, $member_id);
                    
                    if ($update_stmt->execute()) {
                        $success = "Member removed from CBO successfully!";
                    } else {
                        $error = "Database error: " . $update_stmt->error;
                    }
                    $update_stmt->close();
                    $cbo_stmt->close();
                }
            }
            $customer_stmt->close();
        }
    }
}

// Get CBO members for display
if (isset($_GET['cbo_id'])) {
    $selected_cbo_id = $_GET['cbo_id'];
    $cbo_members = getCBOActiveMembers($selected_cbo_id);
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add CBO Members - Micro Finance System</title>
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
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
        <div class="container-fluid py-4">
            <!-- Page Header -->
            <div class="row align-items-center mb-4">
                <div class="col">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-people text-primary me-2"></i>Manage CBO Members
                    </h1>
                    <p class="text-muted mb-0">Add or remove members from CBOs</p>
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
                <!-- Add Member Form -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-plus me-2"></i>Add Member to CBO
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Select CBO</label>
                                    <select class="form-control" name="cbo_id" required>
                                        <option value="">Select CBO</option>
                                        <?php while ($cbo = $cbos->fetch_assoc()): ?>
                                            <option value="<?php echo $cbo['id']; ?>" 
                                                <?php echo (isset($_POST['cbo_id']) && $_POST['cbo_id'] == $cbo['id']) ? 'selected' : ''; ?>>
                                                <?php echo $cbo['name'] . ' (#' . $cbo['cbo_number'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Customer NIC</label>
                                    <input type="text" class="form-control" name="customer_nic" 
                                           value="<?php echo htmlspecialchars($_POST['customer_nic'] ?? ''); ?>" 
                                           required placeholder="Enter customer NIC">
                                    <div class="form-text">
                                        Enter the exact NIC number of the customer
                                    </div>
                                </div>
                                
                                <button type="submit" name="add_member" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus me-2"></i>Add to CBO
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Important Notes -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-info text-white py-3">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Important Rules
                            </h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success me-2"></i>
                                    One customer can only be active in ONE CBO at a time
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success me-2"></i>
                                    Customer can re-join previously left CBOs
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-x-circle text-danger me-2"></i>
                                    Cannot change CBO if customer has active loans
                                </li>
                                <li class="mb-0">
                                    <i class="bi bi-x-circle text-danger me-2"></i>
                                    Cannot leave CBO if customer has active loans
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Member List -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-success text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-people-fill me-2"></i>CBO Members
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- CBO Selection for Members -->
                            <form method="GET" class="mb-3">
                                <div class="input-group">
                                    <select class="form-control" name="cbo_id" required>
                                        <option value="">Select CBO to view members</option>
                                        <?php 
                                        $cbos->data_seek(0); // Reset pointer
                                        while ($cbo = $cbos->fetch_assoc()): ?>
                                            <option value="<?php echo $cbo['id']; ?>" 
                                                <?php echo (isset($_GET['cbo_id']) && $_GET['cbo_id'] == $cbo['id']) ? 'selected' : ''; ?>>
                                                <?php echo $cbo['name'] . ' (#' . $cbo['cbo_number'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </form>

                            <?php if (isset($_GET['cbo_id']) && $cbo_members->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>NIC</th>
                                                <th>Joined</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($member = $cbo_members->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $member['full_name']; ?></div>
                                                    <small class="text-muted"><?php echo $member['short_name']; ?></small>
                                                </td>
                                                <td><?php echo $member['national_id']; ?></td>
                                                <td><?php echo $member['joined_date']; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#removeModal<?php echo $member['id']; ?>">
                                                        <i class="bi bi-person-dash"></i>
                                                    </button>
                                                    
                                                    <!-- Remove Member Modal -->
                                                    <div class="modal fade" id="removeModal<?php echo $member['id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Remove Member</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST">
                                                                    <div class="modal-body">
                                                                        <p>Are you sure you want to remove <strong><?php echo $member['full_name']; ?></strong> from this CBO?</p>
                                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Reason for leaving</label>
                                                                            <input type="text" class="form-control" name="leave_reason" 
                                                                                   placeholder="Optional reason for leaving">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" name="remove_member" class="btn btn-danger">Remove</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif (isset($_GET['cbo_id'])): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-people display-4 d-block mb-2"></i>
                                    <p>No active members in this CBO</p>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-search display-4 d-block mb-2"></i>
                                    <p>Select a CBO to view members</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>