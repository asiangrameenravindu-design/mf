<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$success = '';
$error = '';

// Get field officers for dropdown
$field_officers = getStaffByPosition('field_officer');

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cbo'])) {
    
    $name = trim($_POST['name'] ?? '');
    $staff_id = trim($_POST['staff_id'] ?? '');
    $meeting_day = trim($_POST['meeting_day'] ?? '');
    
    // Basic validation
    if (empty($name) || empty($staff_id) || empty($meeting_day)) {
        $error = "සියලුම ක්ෂේත්‍ර පුරවන්න!";
    } else {
        try {
            // Check if CBO name already exists
            $check_sql = "SELECT id FROM cbo WHERE name = ?";
            $check_stmt = $conn->prepare($check_sql);
            
            if ($check_stmt) {
                $check_stmt->bind_param("s", $name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error = "CBO නම '$name' දැනටමත් පවතී! වෙනත් නමක් තෝරන්න.";
                } else {
                    // Get the next CBO number
                    $next_cbo_number = getNextCBONumber();
                    
                    // Insert new CBO
                    $sql = "INSERT INTO cbo (cbo_number, name, staff_id, meeting_day, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    
                    if ($stmt) {
                        $stmt->bind_param("isis", $next_cbo_number, $name, $staff_id, $meeting_day);
                        
                        if ($stmt->execute()) {
                            $success = "CBO '$name' සාර්ථකව නිර්මාණය කළා! CBO අංකය: " . str_pad($next_cbo_number, 2, '0', STR_PAD_LEFT);
                            
                            // Redirect to avoid form resubmission
                            header("Location: new.php?success=" . urlencode($success));
                            exit();
                            
                        } else {
                            $error = "දත්ත සමුදාය දෝෂය: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "SQL දෝෂය: " . $conn->error;
                    }
                }
                $check_stmt->close();
            } else {
                $error = "Database preparation failed!";
            }
        } catch (Exception $e) {
            $error = "දෝෂය: " . $e->getMessage();
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get existing CBOs for display
$existing_cbos = getCBOs();
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New CBO - Micro Finance System</title>
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
        .cbo-number-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        .cbo-display-name {
            font-weight: 600;
            font-size: 1rem;
        }
        .cbo-display-number {
            font-size: 0.85rem;
            color: #6c757d;
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
                        <i class="bi bi-building text-primary me-2"></i>Create New CBO
                    </h1>
                    <p class="text-muted mb-0">Create new Community Based Organization</p>
                </div>
                <div class="col-auto">
                    <a href="overview.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-2"></i>View CBOs
                    </a>
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
                <!-- Create CBO Form -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building-fill me-2"></i>CBO Information
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" id="cboForm">
                                <input type="hidden" name="create_cbo" value="1">
                                
                                <div class="row g-3">
                                    <!-- CBO Name -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">
                                            CBO Name <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               name="name" 
                                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                               required 
                                               placeholder="Enter CBO name (e.g., Colombo Central CBO)">
                                        <div class="form-text">
                                            Each CBO must have a unique name
                                        </div>
                                    </div>

                                    <!-- Field Officer -->
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">
                                            Assigned Field Officer <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control" name="staff_id" required>
                                            <option value="">Select Field Officer</option>
                                            <?php 
                                            if ($field_officers && $field_officers->num_rows > 0):
                                                $field_officers->data_seek(0);
                                                while ($officer = $field_officers->fetch_assoc()): ?>
                                                    <option value="<?php echo $officer['id']; ?>" 
                                                        <?php echo (isset($_POST['staff_id']) && $_POST['staff_id'] == $officer['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($officer['full_name'] . ' (' . $officer['short_name'] . ')'); ?>
                                                    </option>
                                                <?php endwhile;
                                            else: ?>
                                                <option value="" disabled>No field officers available</option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text">
                                            Select the field officer responsible for this CBO
                                        </div>
                                    </div>

                                    <!-- Meeting Day -->
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">
                                            Meeting Day <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control" name="meeting_day" required>
                                            <option value="">Select Meeting Day</option>
                                            <option value="monday" <?php echo (isset($_POST['meeting_day']) && $_POST['meeting_day'] == 'monday') ? 'selected' : ''; ?>>Monday</option>
                                            <option value="tuesday" <?php echo (isset($_POST['meeting_day']) && $_POST['meeting_day'] == 'tuesday') ? 'selected' : ''; ?>>Tuesday</option>
                                            <option value="wednesday" <?php echo (isset($_POST['meeting_day']) && $_POST['meeting_day'] == 'wednesday') ? 'selected' : ''; ?>>Wednesday</option>
                                            <option value="thursday" <?php echo (isset($_POST['meeting_day']) && $_POST['meeting_day'] == 'thursday') ? 'selected' : ''; ?>>Thursday</option>
                                            <option value="friday" <?php echo (isset($_POST['meeting_day']) && $_POST['meeting_day'] == 'friday') ? 'selected' : ''; ?>>Friday</option>
                                        </select>
                                        <div class="form-text">
                                            Select the regular meeting day for this CBO
                                        </div>
                                    </div>
                                </div>

                                <!-- Auto-generated Info -->
                                <div class="alert alert-info mt-4">
                                    <h6 class="alert-heading mb-2">
                                        <i class="bi bi-info-circle me-2"></i>Auto-generated Information
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>CBO Number:</strong> 
                                            <span class="text-primary fw-bold">
                                                <?php echo str_pad(getNextCBONumber(), 2, '0', STR_PAD_LEFT); ?>
                                            </span>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Status:</strong> <span class="text-success fw-bold">Active</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button type="reset" class="btn btn-outline-secondary">
                                                <i class="bi bi-arrow-clockwise me-2"></i>Clear Form
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-building me-2"></i>Create CBO
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Existing CBOs -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-success text-white py-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-list-check me-2"></i>Existing CBOs
                                <span class="badge bg-light text-dark ms-2">
                                    <?php echo $existing_cbos ? $existing_cbos->num_rows : 0; ?>
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($existing_cbos && $existing_cbos->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $existing_cbos->data_seek(0);
                                    while ($cbo = $existing_cbos->fetch_assoc()): 
                                        $member_count = 0;
                                        $member_count_sql = "SELECT COUNT(*) as count FROM cbo_members WHERE cbo_id = ? AND status = 'active'";
                                        $member_count_stmt = $conn->prepare($member_count_sql);
                                        if ($member_count_stmt) {
                                            $member_count_stmt->bind_param("i", $cbo['id']);
                                            $member_count_stmt->execute();
                                            $member_count_result = $member_count_stmt->get_result();
                                            $member_count_data = $member_count_result->fetch_assoc();
                                            $member_count = $member_count_data['count'];
                                            $member_count_stmt->close();
                                        }
                                    ?>
                                    <a href="overview.php?cbo_id=<?php echo $cbo['id']; ?>" 
                                       class="list-group-item list-group-item-action d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <!-- CBO Number and Name displayed together -->
                                            <div class="cbo-display-name mb-1">
                                                <?php echo str_pad($cbo['cbo_number'], 2, '0', STR_PAD_LEFT) . ' - ' . htmlspecialchars($cbo['name']); ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar-event me-1"></i><?php echo ucfirst($cbo['meeting_day']); ?> • 
                                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($cbo['staff_name'] ?? 'N/A'); ?>
                                            </small>
                                            <div class="mt-1">
                                                <small class="text-primary">
                                                    <i class="bi bi-people me-1"></i><?php echo $member_count; ?> members
                                                </small>
                                            </div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted"></i>
                                    </a>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-building display-4 d-block mb-2"></i>
                                    <p>No CBOs created yet</p>
                                    <small>Create your first CBO to get started</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-body">
                            <h6 class="card-title mb-3">
                                <i class="bi bi-graph-up text-warning me-2"></i>CBO Statistics
                            </h6>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <h4 class="mb-0 text-primary">
                                            <?php echo $existing_cbos ? $existing_cbos->num_rows : 0; ?>
                                        </h4>
                                        <small class="text-muted">Total CBOs</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <h4 class="mb-0 text-success">
                                            <?php
                                            $total_members = 0;
                                            $total_members_result = $conn->query("SELECT COUNT(*) as count FROM cbo_members WHERE status = 'active'");
                                            if ($total_members_result) {
                                                $total_members_data = $total_members_result->fetch_assoc();
                                                $total_members = $total_members_data['count'];
                                            }
                                            echo $total_members;
                                            ?>
                                        </h4>
                                        <small class="text-muted">Active Members</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Include Footer -->
    <?php include '../../includes/footer.php'; ?>

    <script>
    // Simple form handling
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('cboForm');
        
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...';
                submitBtn.disabled = true;
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    </script>
</body>
</html>