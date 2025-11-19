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

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant', ];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Temporary function definitions - remove after adding to functions.php
if (!function_exists('calculateBirthDate')) {
    function calculateBirthDate($nic) {
        $nic = trim($nic);
        
        if (strlen($nic) === 10) {
            // Old format: 901234567V
            $year = '19' . substr($nic, 0, 2);
            $days = intval(substr($nic, 2, 3));
        } elseif (strlen($nic) === 12) {
            // New format: 200012345678
            $year = substr($nic, 0, 4);
            $days = intval(substr($nic, 4, 3));
        } else {
            return '';
        }
        
        // Handle female IDs (days > 500)
        $isFemale = false;
        $actualDays = $days;
        if ($days > 500) {
            $isFemale = true;
            $actualDays = $days - 500;
        }
        
        // Validate days
        if ($actualDays >= 1 && $actualDays <= 366) {
            // Calculate birth date (January 1 + days - 1)
            $startDate = new DateTime($year . '-01-01');
            $startDate->modify('+' . ($actualDays - 1) . ' days');
            return $startDate->format('Y-m-d');
        }
        
        return '';
    }
}

if (!function_exists('generateShortName')) {
    function generateShortName($fullName) {
        $names = array_filter(explode(' ', trim($fullName)));
        $shortName = '';
        
        // Create short name format: H.V.A.RAVINDU
        $nameCount = count($names);
        for ($i = 0; $i < $nameCount - 1; $i++) {
            if (trim($names[$i]) !== '') {
                $shortName .= strtoupper(substr($names[$i], 0, 1)) . '.';
            }
        }
        
        if ($nameCount > 0) {
            $shortName .= strtoupper(end($names));
        }
        
        return $shortName;
    }
}

// Initialize variables
$success = '';
$error = '';
$customer_data = null;

// Get customer ID from URL
$customer_id = $_GET['customer_id'] ?? '';

if (empty($customer_id)) {
    header('Location: view.php');
    exit();
}

// Fetch customer data
$sql = "SELECT * FROM customers WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer_data = $result->fetch_assoc();
$stmt->close();

if (!$customer_data) {
    $error = "Customer not found!";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    
    $national_id = trim($_POST['national_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Basic validation
    if (empty($national_id) || empty($full_name) || empty($phone) || empty($address)) {
        $error = "All fields are required!";
    } else {
        try {
            // Check if national ID already exists for another customer
            $check_sql = "SELECT id FROM customers WHERE national_id = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("si", $national_id, $customer_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Another customer with National ID $national_id already exists!";
            } else {
                // Generate short name and birth date
                $short_name = generateShortName($full_name);
                $birth_date = calculateBirthDate($national_id);
                
                // Update customer
                $update_sql = "UPDATE customers SET 
                              national_id = ?, 
                              full_name = ?, 
                              short_name = ?, 
                              birth_date = ?, 
                              phone = ?, 
                              address = ? 
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssssi", $national_id, $full_name, $short_name, $birth_date, $phone, $address, $customer_id);
                
                if ($update_stmt->execute()) {
                    $success = "Customer information updated successfully!";
                    // Refresh customer data
                    $sql = "SELECT * FROM customers WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $customer_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $customer_data = $result->fetch_assoc();
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
    <title>Edit Customer - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --dark: #1d3557;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
        }
        
        .main-content { 
            margin-left: 280px; 
            padding: 20px; 
            margin-top: 56px; 
            background-color: #f8f9fa; 
            min-height: calc(100vh - 56px); 
            transition: margin-left 0.3s ease;
        }
        
        @media (max-width: 768px) { 
            .main-content { 
                margin-left: 0; 
            } 
        }
        
        .calculation-result {
            background-color: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 12px 15px;
            font-weight: 500;
            color: #2e7d32;
            min-height: 50px;
            display: flex;
            align-items: center;
        }
        
        .customer-avatar {
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
        
        .info-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            border: none;
            padding: 1.5rem;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f9a826, #f37121);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 2rem;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #f37121, #e55039);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 113, 33, 0.3);
        }
        
        .phone-validation {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .phone-valid {
            color: #198754;
        }
        
        .phone-invalid {
            color: #dc3545;
        }
        
        .info-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .badge.bg-pink { 
            background: linear-gradient(135deg, #f72585, #b5179e); 
        }
        
        .badge.bg-blue { 
            background: linear-gradient(135deg, #4361ee, #3a0ca3); 
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../../dashboard.php">
                <i class="bi bi-bank"></i> Micro Finance System
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="bi bi-person-circle"></i> 
                    <?php echo $_SESSION['user_name'] ?? 'User'; ?>
                </span>
                <a href="../../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
   <?php include '../../includes/header.php'; ?>
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
                                    <li class="breadcrumb-item"><a href="view.php" class="text-decoration-none">Customers</a></li>
                                    <li class="breadcrumb-item active" aria-current="page">Edit Customer</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-pencil-square text-primary me-2"></i>Edit Customer
                            </h1>
                            <p class="text-muted mb-0">Update customer information</p>
                        </div>
                        <div class="col-auto">
                            <a href="view.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Customers
                            </a>
                        </div>
                    </div>

                    <?php if ($error && !$customer_data): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($customer_data): ?>
                    <div class="row">
                        <!-- Customer Summary -->
                        <div class="col-lg-4">
                            <div class="info-card">
                                <div class="text-center mb-4">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($customer_data['full_name'], 0, 1)); ?>
                                    </div>
                                    <h5 class="mt-3 mb-1"><?php echo $customer_data['full_name']; ?></h5>
                                    <p class="text-muted mb-2"><?php echo $customer_data['short_name']; ?></p>
                                    <span class="badge bg-primary"><?php echo $customer_data['national_id']; ?></span>
                                </div>
                                
                                <div class="customer-info">
                                    <div class="info-item d-flex align-items-center mb-3">
                                        <div class="info-icon bg-light rounded-circle p-2 me-3">
                                            <i class="bi bi-calendar3 text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <small class="text-muted">Birth Date</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y', strtotime($customer_data['birth_date'])); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item d-flex align-items-center mb-3">
                                        <div class="info-icon bg-light rounded-circle p-2 me-3">
                                            <i class="bi bi-gender-ambiguous text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <small class="text-muted">Gender</small>
                                            <div class="fw-semibold">
                                                <?php 
                                                $days = substr($customer_data['national_id'], 2, 3);
                                                echo ($days > 500) ? 'Female' : 'Male'; 
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item d-flex align-items-center mb-3">
                                        <div class="info-icon bg-light rounded-circle p-2 me-3">
                                            <i class="bi bi-telephone text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <small class="text-muted">Phone Number</small>
                                            <div class="fw-semibold"><?php echo $customer_data['phone']; ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item d-flex align-items-start">
                                        <div class="info-icon bg-light rounded-circle p-2 me-3">
                                            <i class="bi bi-geo-alt text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <small class="text-muted">Address</small>
                                            <div class="fw-semibold"><?php echo $customer_data['address']; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="info-card">
                                <h6 class="card-title mb-3">
                                    <i class="bi bi-lightning text-warning me-2"></i>Quick Actions
                                </h6>
                                <div class="d-grid gap-2">
                                    <a href="../loans/new.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-cash-coin me-2"></i>Create New Loan
                                    </a>
                                    <a href="../cbo/add_member.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-building me-2"></i>Add to CBO
                                    </a>
                                    <a href="view.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-info btn-sm">
                                        <i class="bi bi-eye me-2"></i>View Profile
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Edit Form -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-pencil-fill me-2"></i>Edit Customer Information
                                    </h5>
                                </div>
                                <div class="card-body p-4">
                                    <!-- Success Message -->
                                    <?php if ($success): ?>
                                        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center">
                                            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                                            <div class="flex-grow-1">
                                                <?php echo $success; ?>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Error Message -->
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center">
                                            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                                            <div class="flex-grow-1">
                                                <?php echo $error; ?>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" id="customerForm">
                                        <div class="row g-3">
                                            <!-- National ID -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-card-checklist me-2"></i>National ID Number <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" 
                                                       class="form-control form-control-lg" 
                                                       name="national_id" 
                                                       id="national_id"
                                                       value="<?php echo htmlspecialchars($customer_data['national_id']); ?>" 
                                                       required 
                                                       oninput="calculateFromNIC()"
                                                       placeholder="901234567V or 200012345678">
                                                <div class="form-text text-muted">
                                                    <i class="bi bi-info-circle me-1"></i>Enter 10-digit (901234567V) or 12-digit (200012345678) NIC
                                                </div>
                                            </div>

                                            <!-- Birth Date (Auto-calculated) -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-calendar-event me-2"></i>Birth Date
                                                </label>
                                                <div class="calculation-result" id="birth_date_display">
                                                    <i class="bi bi-calendar-check me-2"></i><?php echo $customer_data['birth_date']; ?>
                                                </div>
                                                <input type="hidden" name="birth_date" id="birth_date" value="<?php echo $customer_data['birth_date']; ?>">
                                                <div class="form-text text-muted">
                                                    <i class="bi bi-magic me-1"></i>Automatically calculated from NIC
                                                </div>
                                            </div>

                                            <!-- Full Name -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-person me-2"></i>Full Name <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" 
                                                       class="form-control form-control-lg" 
                                                       name="full_name" 
                                                       id="full_name"
                                                       value="<?php echo htmlspecialchars($customer_data['full_name']); ?>" 
                                                       required 
                                                       oninput="generateShortName()"
                                                       placeholder="HAMMANA VIDANA ARACHCHIGE RAVINDU">
                                            </div>

                                            <!-- Short Name (Auto-generated) -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-person-badge me-2"></i>Short Name
                                                </label>
                                                <div class="calculation-result" id="short_name_display">
                                                    <i class="bi bi-person-badge me-2"></i><?php echo $customer_data['short_name']; ?>
                                                </div>
                                                <input type="hidden" name="short_name" id="short_name" value="<?php echo $customer_data['short_name']; ?>">
                                                <div class="form-text text-muted">
                                                    <i class="bi bi-magic me-1"></i>Automatically generated from full name
                                                </div>
                                            </div>

                                            <!-- Phone Number -->
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-telephone me-2"></i>Phone Number <span class="text-danger">*</span>
                                                </label>
                                                <input type="tel" 
                                                       class="form-control form-control-lg" 
                                                       name="phone" 
                                                       id="phone"
                                                       value="<?php echo htmlspecialchars($customer_data['phone']); ?>" 
                                                       required 
                                                       oninput="validatePhoneNumber()"
                                                       maxlength="10"
                                                       placeholder="0778969190">
                                                <div class="phone-validation" id="phoneValidation">
                                                    <i class="bi bi-phone me-1"></i>10-digit mobile number (digits only)
                                                </div>
                                                <div id="phoneExistsWarning" class="phone-validation phone-invalid" style="display: none;">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>This phone number already exists in the system!
                                                </div>
                                            </div>

                                            <!-- Address -->
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">
                                                    <i class="bi bi-geo-alt me-2"></i>Address <span class="text-danger">*</span>
                                                </label>
                                                <textarea class="form-control" 
                                                          name="address" 
                                                          id="address"
                                                          rows="3" 
                                                          required 
                                                          placeholder="Enter complete address with street, city, and postal code"><?php echo htmlspecialchars($customer_data['address']); ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Form Actions -->
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <div class="d-flex gap-3 justify-content-end border-top pt-4">
                                                    <a href="view.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary px-4">
                                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                                    </a>
                                                    <button type="submit" name="update" class="btn btn-warning px-4" id="submitBtn">
                                                        <i class="bi bi-check-circle me-2"></i>Update Customer
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Audit Information -->
                            <div class="card mt-4">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="bi bi-clock-history text-info me-2"></i>Audit Information
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted">Created On</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y g:i A', strtotime($customer_data['created_at'])); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">Last Updated</small>
                                            <div class="fw-semibold"><?php echo date('F j, Y g:i A', strtotime($customer_data['updated_at'] ?? $customer_data['created_at'])); ?></div>
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
    function calculateFromNIC() {
        const nic = document.getElementById('national_id').value.trim();
        const birthDateDisplay = document.getElementById('birth_date_display');
        const birthDateInput = document.getElementById('birth_date');
        
        if (nic.length === 10 || nic.length === 12) {
            // Calculate birth date from NIC
            let year, days;
            
            if (nic.length === 10) {
                // Old format: 901234567V
                year = '19' + nic.substring(0, 2);
                days = parseInt(nic.substring(2, 5));
            } else {
                // New format: 200012345678
                year = nic.substring(0, 4);
                days = parseInt(nic.substring(4, 7));
            }
            
            // Handle female IDs (days > 500)
            let isFemale = false;
            let actualDays = days;
            if (days > 500) {
                isFemale = true;
                actualDays = days - 500;
            }
            
            // Validate days
            if (actualDays >= 1 && actualDays <= 366) {
                // Calculate birth date correctly
                const startDate = new Date(year, 0, 1); // January 1st of the year
                
                // Create new date object and add the days
                const birthDate = new Date(startDate);
                birthDate.setDate(startDate.getDate() + (actualDays - 1));
                
                // Format date as YYYY-MM-DD
                const formattedDate = birthDate.getFullYear() + '-' + 
                                    String(birthDate.getMonth() + 1).padStart(2, '0') + '-' + 
                                    String(birthDate.getDate()).padStart(2, '0');
                
                // Get day and month for display
                const day = birthDate.getDate();
                const monthNames = ["January", "February", "March", "April", "May", "June",
                                  "July", "August", "September", "October", "November", "December"];
                const month = monthNames[birthDate.getMonth()];
                
                // Display result
                birthDateDisplay.innerHTML = `<i class="bi bi-calendar-check me-2"></i>${formattedDate} (${day} ${month}) ${isFemale ? '<span class="badge bg-pink ms-2">Female</span>' : '<span class="badge bg-blue ms-2">Male</span>'}`;
                birthDateDisplay.style.backgroundColor = '#e8f5e8';
                birthDateDisplay.style.color = '#2e7d32';
                birthDateInput.value = formattedDate;
            } else {
                birthDateDisplay.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Invalid NIC days</span>';
                birthDateDisplay.style.backgroundColor = '#fff3cd';
                birthDateDisplay.style.color = '#856404';
                birthDateInput.value = '';
            }
        } else {
            birthDateDisplay.innerHTML = '<i class="bi bi-clock me-2"></i>Enter NIC to calculate birth date';
            birthDateDisplay.style.backgroundColor = '#f8f9fa';
            birthDateDisplay.style.color = '#6c757d';
            birthDateInput.value = '';
        }
    }

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
            shortNameDisplay.innerHTML = '<i class="bi bi-clock me-2"></i>Enter full name to generate short name';
            shortNameDisplay.style.backgroundColor = '#f8f9fa';
            shortNameDisplay.style.color = '#6c757d';
            shortNameInput.value = '';
        }
    }

    function validatePhoneNumber() {
        const phoneInput = document.getElementById('phone');
        const phoneValidation = document.getElementById('phoneValidation');
        const phoneExistsWarning = document.getElementById('phoneExistsWarning');
        const submitBtn = document.getElementById('submitBtn');
        
        // Remove non-digit characters
        phoneInput.value = phoneInput.value.replace(/\D/g, '');
        
        // Validate length and format
        if (phoneInput.value.length === 10) {
            phoneValidation.innerHTML = '<i class="bi bi-check-circle me-1"></i>Valid phone number';
            phoneValidation.className = 'phone-validation phone-valid';
            phoneExistsWarning.style.display = 'none';
            submitBtn.disabled = false;
        } else if (phoneInput.value.length > 0) {
            phoneValidation.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Phone number must be exactly 10 digits';
            phoneValidation.className = 'phone-validation phone-invalid';
            phoneExistsWarning.style.display = 'none';
            submitBtn.disabled = true;
        } else {
            phoneValidation.innerHTML = '<i class="bi bi-phone me-1"></i>10-digit mobile number (digits only)';
            phoneValidation.className = 'phone-validation';
            phoneExistsWarning.style.display = 'none';
            submitBtn.disabled = false;
        }
    }

    function checkPhoneExists() {
        const phone = document.getElementById('phone').value;
        const customerId = '<?php echo $customer_id; ?>';
        
        if (phone.length === 10) {
            // AJAX call to check if phone exists for other customers
            fetch('check_phone.php?phone=' + phone + '&customer_id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    const phoneExistsWarning = document.getElementById('phoneExistsWarning');
                    const submitBtn = document.getElementById('submitBtn');
                    
                    if (data.exists) {
                        phoneExistsWarning.style.display = 'block';
                        submitBtn.disabled = true;
                    } else {
                        phoneExistsWarning.style.display = 'none';
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => console.error('Error:', error));
        }
    }

    // Initialize calculations on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateFromNIC();
        generateShortName();
        validatePhoneNumber();
        
        // Prevent non-digit input in phone field
        document.getElementById('phone').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
        });
        
        // Add real-time phone validation
        document.getElementById('phone').addEventListener('blur', checkPhoneExists);
    });
    </script>
</body>
</html>