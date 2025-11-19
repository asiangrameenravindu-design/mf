<?php
session_start();

// Include files
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize variables
$success = '';
$error = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    
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
            // Check if staff already exists
            $check_sql = "SELECT id FROM staff WHERE national_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $national_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Staff member with National ID $national_id already exists!";
            } else {
                // Generate short name
                $short_name = generateShortName($full_name);
                
                // Insert new staff
                $sql = "INSERT INTO staff (national_id, full_name, short_name, position, phone, email) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssss", $national_id, $full_name, $short_name, $position, $phone, $email);
                
                if ($stmt->execute()) {
                    $success = "Staff member $full_name registered successfully!";
                    // Clear form
                    $_POST = array();
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
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Registration -DTR_MF</title>
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
        .position-badge {
            font-size: 0.8rem;
            padding: 0.4em 0.8em;
        }
    </style>
</head>
<body>
    

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
                                    <li class="breadcrumb-item active" aria-current="page">Staff Registration</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-person-badge text-primary me-2"></i>Staff Registration
                            </h1>
                            <p class="text-muted mb-0">Add new staff members to the system</p>
                        </div>
                        <div class="col-auto">
                            <a href="view.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left me-2"></i>View Staff
                            </a>
                        </div>
                    </div>

                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-primary text-white py-3">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-person-badge-fill me-2"></i>Staff Information
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
                                                       value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>" 
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
                                                    <option value="manager" <?php echo ($_POST['position'] ?? '') == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                                    <option value="accountant" <?php echo ($_POST['position'] ?? '') == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                                    <option value="field_officer" <?php echo ($_POST['position'] ?? '') == 'field_officer' ? 'selected' : ''; ?>>Field Officer</option>
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
                                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                                       required 
                                                       oninput="generateShortName()"
                                                       placeholder="Enter full name">
                                            </div>

                                            <!-- Short Name (Auto-generated) -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Short Name</label>
                                                <div class="calculation-result" id="short_name_display">
                                                    Enter full name to generate short name
                                                </div>
                                                <input type="hidden" name="short_name" id="short_name">
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
                                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
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
                                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                                       placeholder="email@example.com">
                                                <div class="form-text">Optional email address</div>
                                            </div>
                                        </div>

                                        <!-- Form Actions -->
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <button type="reset" class="btn btn-outline-secondary" onclick="clearCalculations()">
                                                        <i class="bi bi-arrow-clockwise me-2"></i>Clear Form
                                                    </button>
                                                    <button type="submit" name="register" class="btn btn-primary">
                                                        <i class="bi bi-person-plus me-2"></i>Register Staff
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Position Information -->
                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="bi bi-info-circle text-primary me-2"></i>Position Descriptions
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="border rounded p-3 text-center">
                                                <span class="badge bg-primary position-badge mb-2">Manager</span>
                                                <p class="small mb-0">System administration and loan approvals</p>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="border rounded p-3 text-center">
                                                <span class="badge bg-success position-badge mb-2">Accountant</span>
                                                <p class="small mb-0">Financial management and reporting</p>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="border rounded p-3 text-center">
                                                <span class="badge bg-warning position-badge mb-2">Field Officer</span>
                                                <p class="small mb-0">Customer interaction and CBO management</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
            shortNameDisplay.innerHTML = 'Enter full name to generate short name';
            shortNameDisplay.style.backgroundColor = '#f8f9fa';
            shortNameDisplay.style.color = '#6c757d';
            shortNameInput.value = '';
        }
    }

    function clearCalculations() {
        document.getElementById('short_name_display').innerHTML = 'Enter full name to generate short name';
        document.getElementById('short_name_display').style.backgroundColor = '#f8f9fa';
        document.getElementById('short_name_display').style.color = '#6c757d';
        document.getElementById('short_name').value = '';
    }

    // Initialize calculations if form has values
    document.addEventListener('DOMContentLoaded', function() {
        const nameValue = document.getElementById('full_name').value;
        
        if (nameValue) {
            generateShortName();
        }
    });
    </script>
</body>
</html>