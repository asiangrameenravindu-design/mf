<?php
// Start output buffering to prevent header errors
ob_start();

// modules/customer/register.php
session_start();

// Include files with correct paths
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user has permission (manager or field_officer)
$allowed_roles = ['manager', 'admin','accountant'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit();
}

// Initialize variables
$success = '';
$error = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    
    $national_id = trim($_POST['national_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    
    // Basic validation
    if (empty($national_id) || empty($full_name) || empty($phone) || empty($address)) {
        $error = "All fields are required!";
    } else {
        // Phone number validation - only digits and exactly 10 characters
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = "Phone number must contain exactly 10 digits!";
        } else {
            try {
                // Initialize statements
                $check_stmt = null;
                $check_phone_stmt = null;
                $stmt = null;
                
                // Check if customer already exists with national ID
                $check_sql = "SELECT id FROM customers WHERE national_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $national_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error = "Customer with National ID $national_id already exists!";
                } else {
                    // Check if phone number already exists
                    $check_phone_sql = "SELECT id, full_name FROM customers WHERE phone = ?";
                    $check_phone_stmt = $conn->prepare($check_phone_sql);
                    $check_phone_stmt->bind_param("s", $phone);
                    $check_phone_stmt->execute();
                    $check_phone_result = $check_phone_stmt->get_result();
                    
                    if ($check_phone_result->num_rows > 0) {
                        $existing_customer = $check_phone_result->fetch_assoc();
                        $error = "Phone number $phone already exists for customer: " . $existing_customer['full_name'];
                    } else {
                        // Insert new customer
                        $sql = "INSERT INTO customers (national_id, full_name, short_name, birth_date, phone, address) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssssss", $national_id, $full_name, $short_name, $birth_date, $phone, $address);
                        
                        if ($stmt->execute()) {
                            $success = "Customer $full_name registered successfully!";
                            // Clear form
                            $_POST = array();
                        } else {
                            $error = "Database error: " . $stmt->error;
                        }
                    }
                }
                
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            } finally {
                // Close all statements safely
                if (isset($check_stmt) && is_object($check_stmt)) {
                    $check_stmt->close();
                }
                if (isset($check_phone_stmt) && is_object($check_phone_stmt)) {
                    $check_phone_stmt->close();
                }
                if (isset($stmt) && is_object($stmt)) {
                    $stmt->close();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration - Micro Finance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --dark: #1d3557;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
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
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
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
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .info-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }
        
        .step-line {
            flex: 1;
            height: 3px;
            background: #e9ecef;
            margin: 0 1rem;
        }
        
        .step.active {
            background: var(--success);
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
    </style>
</head>
<body>
    <!-- Include Header -->
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>
   

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid py-4">
            <!-- Page Header -->
            <div class="row align-items-center mb-4">
                <div class="col">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary rounded-circle p-3 me-3">
                            <i class="bi bi-person-plus-fill text-white fs-4"></i>
                        </div>
                        <div>
                            <h1 class="h3 mb-1">Register New Customer</h1>
                            <p class="text-muted mb-0">Add new customers to the micro finance system</p>
                        </div>
                    </div>
                </div>
                <div class="col-auto">
                    <a href="view.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Customers
                    </a>
                </div>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step active">1</div>
                <div class="step-line"></div>
                <div class="step">2</div>
                <div class="step-line"></div>
                <div class="step">3</div>
            </div>

            <div class="row justify-content-center">
                <div class="col-xl-10">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-badge me-2"></i>Customer Registration Form
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
                                <div class="row g-4">
                                    <!-- National ID -->
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-card-checklist me-2"></i>National ID Number <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control form-control-lg" 
                                               name="national_id" 
                                               id="national_id"
                                               value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>" 
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
                                            <i class="bi bi-clock me-2"></i>Enter NIC to calculate birth date
                                        </div>
                                        <input type="hidden" name="birth_date" id="birth_date">
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
                                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
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
                                            <i class="bi bi-clock me-2"></i>Enter full name to generate short name
                                        </div>
                                        <input type="hidden" name="short_name" id="short_name">
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
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
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
                                                  rows="3" 
                                                  required 
                                                  placeholder="Enter complete address with street, city, and postal code"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="d-flex gap-3 justify-content-end border-top pt-4">
                                            <button type="reset" class="btn btn-outline-secondary px-4" onclick="clearCalculations()">
                                                <i class="bi bi-arrow-clockwise me-2"></i>Clear Form
                                            </button>
                                            <button type="submit" name="register" class="btn btn-primary px-4" id="submitBtn">
                                                <i class="bi bi-person-plus me-2"></i>Register Customer
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Information Card -->
                    <div class="card info-card mt-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="card-title mb-3">
                                        <i class="bi bi-lightbulb me-2"></i>Quick Tips
                                    </h5>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <ul class="list-unstyled mb-0">
                                                <li class="mb-2">
                                                    <i class="bi bi-check-circle me-2"></i>
                                                    <strong>Birth Date:</strong> Auto-calculated from NIC
                                                </li>
                                                <li class="mb-2">
                                                    <i class="bi bi-check-circle me-2"></i>
                                                    <strong>Short Name:</strong> Auto-generated from full name
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <ul class="list-unstyled mb-0">
                                                <li class="mb-2">
                                                    <i class="bi bi-star me-2"></i>
                                                    <strong>Phone Number:</strong> Exactly 10 digits required
                                                </li>
                                                <li class="mb-0">
                                                    <i class="bi bi-star me-2"></i>
                                                    <strong>Unique Phone:</strong> Each number can be used only once
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <i class="bi bi-person-check display-4 opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
                // New format: 200302802081
                year = nic.substring(0, 4);
                days = parseInt(nic.substring(4, 7));
            }
            
            // Check if female (days > 500)
            let isFemale = false;
            let actualDays = days;
            if (days > 500) {
                isFemale = true;
                actualDays = days - 500; // For date calculation, subtract 500 for females
            }
            
            // Validate days
            if (actualDays >= 1 && actualDays <= 366) {
                // Calculate birth date correctly - COMPLETELY FIXED VERSION
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
                birthDateDisplay.innerHTML = `<i class="bi bi-calendar-check me-2"></i>${formattedDate} (${day} ${month}) ${isFemale ? '<span class="badge bg-pink">Female</span>' : '<span class="badge bg-blue">Male</span>'}`;
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

    function clearCalculations() {
        document.getElementById('birth_date_display').innerHTML = '<i class="bi bi-clock me-2"></i>Enter NIC to calculate birth date';
        document.getElementById('birth_date_display').style.backgroundColor = '#f8f9fa';
        document.getElementById('birth_date_display').style.color = '#6c757d';
        document.getElementById('birth_date').value = '';
        
        document.getElementById('short_name_display').innerHTML = '<i class="bi bi-clock me-2"></i>Enter full name to generate short name';
        document.getElementById('short_name_display').style.backgroundColor = '#f8f9fa';
        document.getElementById('short_name_display').style.color = '#6c757d';
        document.getElementById('short_name').value = '';
        
        // Reset phone validation
        document.getElementById('phoneValidation').innerHTML = '<i class="bi bi-phone me-1"></i>10-digit mobile number (digits only)';
        document.getElementById('phoneValidation').className = 'phone-validation';
        document.getElementById('phoneExistsWarning').style.display = 'none';
        document.getElementById('submitBtn').disabled = false;
    }

    // Initialize calculations if form has values
    document.addEventListener('DOMContentLoaded', function() {
        const nicValue = document.getElementById('national_id').value;
        const nameValue = document.getElementById('full_name').value;
        const phoneValue = document.getElementById('phone').value;
        
        if (nicValue) {
            calculateFromNIC();
        }
        if (nameValue) {
            generateShortName();
        }
        if (phoneValue) {
            validatePhoneNumber();
        }
        
        // Add CSS for badges
        const style = document.createElement('style');
        style.textContent = `
            .badge.bg-pink { background: linear-gradient(135deg, #f72585, #b5179e); }
            .badge.bg-blue { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        `;
        document.head.appendChild(style);
        
        // Prevent non-digit input in phone field
        document.getElementById('phone').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
        });
    });
    </script>
</body>
</html>
<?php
// Clean (erase) the output buffer and turn off output buffering
ob_end_flush();
?>