<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$success = '';
$error = '';
$staff_data = null;

// Get staff ID from URL
$staff_id = $_GET['staff_id'] ?? '';

if (empty($staff_id)) {
    header('Location: view.php');
    exit();
}

// Fetch staff data
$sql = "SELECT * FROM staff WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$result = $stmt->get_result();
$staff_data = $result->fetch_assoc();
$stmt->close();

if (!$staff_data) {
    $error = "Staff member not found!";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    
    $national_id = trim($_POST['national_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Basic validation
    if (empty($national_id) || empty($full_name) || empty($position) || empty($phone)) {
        $error = "All required fields must be filled!";
    } else {
        try {
            // Check if national ID already exists for another staff
            $check_sql = "SELECT id FROM staff WHERE national_id = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("si", $national_id, $staff_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Another staff member with National ID $national_id already exists!";
            } else {
                // Generate short name
                $short_name = generateShortName($full_name);
                
                // Update staff
                $update_sql = "UPDATE staff SET 
                              national_id = ?, 
                              full_name = ?, 
                              short_name = ?, 
                              position = ?, 
                              phone = ?, 
                              email = ? 
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssssi", $national_id, $full_name, $short_name, $position, $phone, $email, $staff_id);
                
                if ($update_stmt->execute()) {
                    $success = "Staff information updated successfully!";
                    // Refresh staff data
                    $sql = "SELECT * FROM staff WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $staff_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $staff_data = $result->fetch_assoc();
                    $stmt->close();
                } else {
                    $error = "Database error: " . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $check_stmt->close();
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
    <title>Edit Staff - Micro Finance System</title>
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
        .calculation-result {
            background-color: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 5px;
            padding: 8px 12px;
            font-weight: 500;
            color: #2e7d32;
        }
        .staff-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            color: white;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    
     <!-- Sidebar -->
            <!-- Include Header -->
    <?php include '../../includes/header.php'; ?>

    <!-- Include Sidebar -->
    <?php include '../../includes/sidebar.php'; ?>


            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row align-items-center mb-4">
                        <div class="col">
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-0">
                                    <li class="breadcrumb-item"><a href="../../dashboard.php" class="text-decoration-none">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="view.php" class="text-decoration-none">Staff</a></li>
                                    <li class="breadcrumb-item active" aria-current="page">Edit Staff</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-pencil-square text-primary me-2"></i>Edit Staff Member
                            </h1>
                            <p class="text-muted mb-0">Update staff member information</p>
                        </div>
                        <div class="col-auto">
                            <a href="view.php?staff_id=<?php echo $staff_id; ?>" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Profile
                            </a>
                        </div>
                    </div>

                    <?php if ($error && !$staff_data): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($staff_data): ?>
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-warning text-dark py-3">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-pencil-fill me-2"></i>Edit Staff Information
                                    </h5>
                                </div>
                                <div class="card-body p-4">
                                    <!-- Success Message -->
                                    <?php if ($success): ?>
                                        <div class="alert alert-success alert-dismissible fade show">
                                            <i class="bi bi-check-circle-fill me-2"></i>
                                            <?php echo $success; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Error Message -->
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger alert-dismissible fade show">
                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                            <?php echo $error; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Staff Summary -->
                                    <div class="text-center mb-4">
                                        <div class="staff-avatar mb-3">
                                            <?php echo strtoupper(substr($staff_data['full_name'], 0, 1)); ?>
                                        </div>
                                        <h5><?php echo $staff_data['full_name']; ?></h5>
                                        <p class="text-muted"><?php echo $staff_data['short_name']; ?></p>
                                        <?php
                                        $position_badge = [
                                            'manager' => 'bg-primary',
                                            'accountant' => 'bg-success',
                                            'field_officer' => 'bg-warning'
                                        ][$staff_data['position']];
                                        ?>
                                        <span class="badge <?php echo $position_badge; ?>">
                                            <?php echo ucfirst($staff_data['position']); ?>
                                        </span>
                                    </div>

                                    <form method="POST" id="staffForm">
                                        <div class="row g-3">
                                            <!-- National ID -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    National ID Number <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" 
                                                       class="form-control" 
                                                       name="national_id" 
                                                       value="<?php echo htmlspecialchars($staff_data['national_id']); ?>" 
                                                       required 
                                                       placeholder="901234567V or 200012345678">
                                                <div class="form-text">
                                                    Enter 10-digit or 12-digit NIC
                                                </div>
                                            </div>

                                            <!-- Position -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    Position <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-control" name="position" required>
                                                    <option value="">Select Position</option>
                                                    <option value="manager" <?php echo $staff_data['position'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                                    <option value="accountant" <?php echo $staff_data['position'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                                    <option value="field_officer" <?php echo $staff_data['position'] == 'field_officer' ? 'selected' : ''; ?>>Field Officer</option>
                                                </select>
                                            </div>

                                            <!-- Full Name -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    Full Name <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" 
                                                       class="form-control" 
                                                       name="full_name" 
                                                       id="full_name"
                                                       value="<?php echo htmlspecialchars($staff_data['full_name']); ?>" 
                                                       required 
                                                       oninput="generateShortName()"
                                                       placeholder="Enter full name">
                                            </div>

                                            <!-- Short Name (Auto-generated) -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Short Name</label>
                                                <div class="calculation-result" id="short_name_display">
                                                    <i class="bi bi-person-badge me-2"></i><?php echo $staff_data['short_name']; ?>
                                                </div>
                                                <input type="hidden" name="short_name" id="short_name" value="<?php echo $staff_data['short_name']; ?>">
                                                <div class="form-text">Automatically generated from full name</div>
                                            </div>

                                            <!-- Phone Number -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    Phone Number <span class="text-danger">*</span>
                                                </label>
                                                <input type="tel" 
                                                       class="form-control" 
                                                       name="phone" 
                                                       value="<?php echo htmlspecialchars($staff_data['phone']); ?>" 
                                                       required 
                                                       placeholder="0778969190">
                                                <div class="form-text">10-digit mobile number</div>
                                            </div>

                                            <!-- Email -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Email Address</label>
                                                <input type="email" 
                                                       class="form-control" 
                                                       name="email" 
                                                       value="<?php echo htmlspecialchars($staff_data['email']); ?>" 
                                                       placeholder="email@example.com">
                                                <div class="form-text">Optional email address</div>
                                            </div>
                                        </div>

                                        <!-- Form Actions -->
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <a href="view.php?staff_id=<?php echo $staff_id; ?>" class="btn btn-outline-secondary">
                                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                                    </a>
                                                    <button type="submit" name="update" class="btn btn-warning">
                                                        <i class="bi bi-check-circle me-2"></i>Update Staff
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Audit Information -->
                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="bi bi-clock-history text-info me-2"></i>Audit Information
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted">Created On</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y g:i A', strtotime($staff_data['created_at'])); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">Last Updated</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y g:i A', strtotime($staff_data['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Real-time Calculation JavaScript -->
    <script>
    function generateShortName() {
        const fullName = document.getElementById('full_name').value.trim();
        const shortNameDisplay = document.getElementById('short_name_display');
        const shortNameInput = document.getElementById('short_name');
        
        if (fullName) {
            const names = fullName.split(' ').filter(name => name.trim() !== '');
            let shortName = '';
            
            // Create short name format: H.V.A.RAVINDU
            for (let i = 0; i < names.length - 1; i++) {
                if (names[i].trim() !== '') {
                    shortName += names[i].charAt(0).toUpperCase() + '.';
                }
            }
            
            if (names.length > 0) {
                shortName += names[names.length - 1].toUpperCase();
            }
            
            // Display result
            shortNameDisplay.innerHTML = `<i class="bi bi-person-badge me-2"></i>${shortName}`;
            shortNameDisplay.style.backgroundColor = '#e8f5e8';
            shortNameDisplay.style.color = '#2e7d32';
            shortNameInput.value = shortName;
        } else {
            shortNameDisplay.innerHTML = '<span class="text-muted">Enter full name to generate short name</span>';
            shortNameDisplay.style.backgroundColor = '#f8f9fa';
            shortNameDisplay.style.color = '#6c757d';
            shortNameInput.value = '';
        }
    }

    // Initialize calculations on page load
    document.addEventListener('DOMContentLoaded', function() {
        generateShortName();
    });
    </script>
</body>
</html>