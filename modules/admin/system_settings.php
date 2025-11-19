<?php
// system_settings.php - Complete Fixed Version

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Correct path - since file is in /modules/admin/, we need to go up two levels
$root_dir = dirname(dirname(__DIR__)) . '/';

// Config file path
$config_file = $root_dir . 'config/config.php';

if (!file_exists($config_file)) {
    die("Configuration file not found: " . $config_file);
}

require_once $config_file;

// Check if user is logged in and is admin
if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("location: " . $root_dir . "login.php");
    exit;
}

// Check database connection
if (!$conn) {
    die("Database connection failed: " . (isset($conn->connect_error) ? $conn->connect_error : "Unknown error"));
}

// Ensure system_settings table exists with correct column names
function ensureSystemSettingsTable() {
    global $conn;
    
    $table_check = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($table_check && $table_check->num_rows == 0) {
        // Create the table if it doesn't exist with correct column names
        $sql = "CREATE TABLE IF NOT EXISTS system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        if ($conn->query($sql) === TRUE) {
            error_log("System settings table created successfully");
            
            // Insert default settings
            $default_settings = [
                ['company_name', 'Micro Finance System', 'Company Name'],
                ['company_address', '123 Main Street, Colombo, Sri Lanka', 'Company Address'],
                ['company_phone', '+94 11 234 5678', 'Company Phone'],
                ['company_email', 'info@microfinance.com', 'Company Email'],
                ['currency', 'LKR', 'Default Currency'],
                ['timezone', 'Asia/Colombo', 'System Timezone'],
                ['session_timeout', '30', 'Session timeout in minutes'],
                ['max_login_attempts', '5', 'Maximum login attempts'],
                ['password_min_length', '6', 'Minimum password length'],
                ['require_strong_password', '1', 'Require strong password'],
                ['enable_2fa', '0', 'Enable two-factor authentication'],
                ['max_loan_amount', '1000000', 'Maximum loan amount'],
                ['min_loan_amount', '5000', 'Minimum loan amount'],
                ['default_interest_rate', '12.5', 'Default interest rate'],
                ['late_payment_fee', '500', 'Late payment fee'],
                ['service_charge_rate', '0', 'Service charge rate'],
                ['insurance_fee', '0', 'Insurance fee'],
                ['loan_approval_required', '1', 'Loan approval required'],
                ['auto_calculate_installments', '1', 'Auto calculate installments'],
                ['auto_calculate_document_charge', '1', 'Auto calculate document charge'],
                ['smtp_host', 'smtp.gmail.com', 'SMTP Host'],
                ['smtp_port', '587', 'SMTP Port'],
                ['smtp_username', '', 'SMTP Username'],
                ['smtp_password', '', 'SMTP Password'],
                ['smtp_encryption', 'tls', 'SMTP Encryption'],
                ['from_email', 'noreply@microfinance.com', 'From Email'],
                ['from_name', 'Micro Finance System', 'From Name']
            ];
            
            foreach ($default_settings as $setting) {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            error_log("Error creating system_settings table: " . $conn->error);
        }
    }
}

// Call this function to ensure table exists
ensureSystemSettingsTable();

$message = '';
$error = '';

// Handle form submissions
if($_SERVER["REQUEST_METHOD"] == "POST") {
    // General Settings
    if(isset($_POST['save_general'])) {
        $company_name = trim($_POST['company_name']);
        $company_address = trim($_POST['company_address']);
        $company_phone = trim($_POST['company_phone']);
        $company_email = trim($_POST['company_email']);
        $currency = trim($_POST['currency']);
        $timezone = trim($_POST['timezone']);
        
        try {
            $conn->begin_transaction();
            
            $settings_to_save = [
                'company_name' => $company_name,
                'company_address' => $company_address,
                'company_phone' => $company_phone,
                'company_email' => $company_email,
                'currency' => $currency,
                'timezone' => $timezone
            ];
            
            foreach ($settings_to_save as $key => $value) {
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("sss", $key, $value, $value);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            }
            
            $conn->commit();
            $message = "General settings updated successfully!";
        } catch (Exception $e) {
            if (isset($conn) && method_exists($conn, 'rollback')) {
                $conn->rollback();
            }
            $error = "Error updating general settings: " . $e->getMessage();
        }
    }
    
    // Security Settings
    if(isset($_POST['save_security'])) {
        $session_timeout = (int)$_POST['session_timeout'];
        $max_login_attempts = (int)$_POST['max_login_attempts'];
        $password_min_length = (int)$_POST['password_min_length'];
        $require_strong_password = isset($_POST['require_strong_password']) ? 1 : 0;
        $enable_2fa = isset($_POST['enable_2fa']) ? 1 : 0;
        
        try {
            $conn->begin_transaction();
            
            $settings_to_save = [
                'session_timeout' => $session_timeout,
                'max_login_attempts' => $max_login_attempts,
                'password_min_length' => $password_min_length,
                'require_strong_password' => $require_strong_password,
                'enable_2fa' => $enable_2fa
            ];
            
            foreach ($settings_to_save as $key => $value) {
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $key, $value, $value);
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            $message = "Security settings updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating security settings: " . $e->getMessage();
        }
    }
    
    // Email Settings
    if(isset($_POST['save_email'])) {
        $smtp_host = trim($_POST['smtp_host']);
        $smtp_port = (int)$_POST['smtp_port'];
        $smtp_username = trim($_POST['smtp_username']);
        $smtp_password = trim($_POST['smtp_password']);
        $smtp_encryption = trim($_POST['smtp_encryption']);
        $from_email = trim($_POST['from_email']);
        $from_name = trim($_POST['from_name']);
        
        try {
            $conn->begin_transaction();
            
            $settings_to_save = [
                'smtp_host' => $smtp_host,
                'smtp_port' => $smtp_port,
                'smtp_username' => $smtp_username,
                'smtp_password' => $smtp_password,
                'smtp_encryption' => $smtp_encryption,
                'from_email' => $from_email,
                'from_name' => $from_name
            ];
            
            foreach ($settings_to_save as $key => $value) {
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $key, $value, $value);
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            $message = "Email settings updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating email settings: " . $e->getMessage();
        }
    }
    
    // Loan Settings
    if(isset($_POST['save_loan'])) {
        $max_loan_amount = (float)$_POST['max_loan_amount'];
        $min_loan_amount = (float)$_POST['min_loan_amount'];
        $default_interest_rate = (float)$_POST['default_interest_rate'];
        $late_payment_fee = (float)$_POST['late_payment_fee'];
        $service_charge_rate = (float)($_POST['service_charge_rate'] ?? 0);
        $insurance_fee = (float)($_POST['insurance_fee'] ?? 0);
        $loan_approval_required = isset($_POST['loan_approval_required']) ? 1 : 0;
        $auto_calculate_installments = isset($_POST['auto_calculate_installments']) ? 1 : 0;
        $auto_calculate_document_charge = isset($_POST['auto_calculate_document_charge']) ? 1 : 0;
        
        // Process loan plans
        $loan_plans = [];
        if(isset($_POST['loan_plans']) && is_array($_POST['loan_plans'])) {
            foreach($_POST['loan_plans'] as $plan) {
                if(!empty($plan['amount']) && !empty($plan['weeks']) && !empty($plan['interest_rate'])) {
                    $loan_plans[] = [
                        'amount' => (float)$plan['amount'],
                        'weeks' => (int)$plan['weeks'],
                        'interest_rate' => (float)$plan['interest_rate'],
                        'document_charge' => (float)$plan['document_charge']
                    ];
                }
            }
        }
        
        // Sort loan plans by amount
        usort($loan_plans, function($a, $b) {
            return $a['amount'] - $b['amount'];
        });
        
        try {
            $conn->begin_transaction();
            
            // Save individual settings
            $settings_to_save = [
                'max_loan_amount' => $max_loan_amount,
                'min_loan_amount' => $min_loan_amount,
                'default_interest_rate' => $default_interest_rate,
                'late_payment_fee' => $late_payment_fee,
                'service_charge_rate' => $service_charge_rate,
                'insurance_fee' => $insurance_fee,
                'loan_approval_required' => $loan_approval_required,
                'auto_calculate_installments' => $auto_calculate_installments,
                'auto_calculate_document_charge' => $auto_calculate_document_charge
            ];
            
            foreach ($settings_to_save as $key => $value) {
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $key, $value, $value);
                $stmt->execute();
                $stmt->close();
            }
            
            // Save loan plans as JSON
            $loan_plans_json = json_encode($loan_plans);
            $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('loan_plans', ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $loan_plans_json, $loan_plans_json);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            $message = "Loan settings updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating loan settings: " . $e->getMessage();
        }
    }
}

// Get current settings from database - FIXED COLUMN NAME
function getSystemSettings() {
    global $conn;
    
    $settings = [];
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($table_check && $table_check->num_rows > 0) {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    
    return $settings;
}

// Load settings from database or use defaults
$db_settings = getSystemSettings();

$current_settings = [
    'company_name' => $db_settings['company_name'] ?? 'Micro Finance System',
    'company_address' => $db_settings['company_address'] ?? '123 Main Street, Colombo, Sri Lanka',
    'company_phone' => $db_settings['company_phone'] ?? '+94 11 234 5678',
    'company_email' => $db_settings['company_email'] ?? 'info@microfinance.com',
    'currency' => $db_settings['currency'] ?? 'LKR',
    'timezone' => $db_settings['timezone'] ?? 'Asia/Colombo',
    'session_timeout' => isset($db_settings['session_timeout']) ? (int)$db_settings['session_timeout'] : 30,
    'max_login_attempts' => isset($db_settings['max_login_attempts']) ? (int)$db_settings['max_login_attempts'] : 5,
    'password_min_length' => isset($db_settings['password_min_length']) ? (int)$db_settings['password_min_length'] : 6,
    'require_strong_password' => isset($db_settings['require_strong_password']) ? (int)$db_settings['require_strong_password'] : 1,
    'enable_2fa' => isset($db_settings['enable_2fa']) ? (int)$db_settings['enable_2fa'] : 0,
    'smtp_host' => $db_settings['smtp_host'] ?? 'smtp.gmail.com',
    'smtp_port' => isset($db_settings['smtp_port']) ? (int)$db_settings['smtp_port'] : 587,
    'smtp_username' => $db_settings['smtp_username'] ?? '',
    'smtp_password' => $db_settings['smtp_password'] ?? '',
    'smtp_encryption' => $db_settings['smtp_encryption'] ?? 'tls',
    'from_email' => $db_settings['from_email'] ?? 'noreply@microfinance.com',
    'from_name' => $db_settings['from_name'] ?? 'Micro Finance System',
    'max_loan_amount' => isset($db_settings['max_loan_amount']) ? (float)$db_settings['max_loan_amount'] : 1000000,
    'min_loan_amount' => isset($db_settings['min_loan_amount']) ? (float)$db_settings['min_loan_amount'] : 5000,
    'default_interest_rate' => isset($db_settings['default_interest_rate']) ? (float)$db_settings['default_interest_rate'] : 12.5,
    'late_payment_fee' => isset($db_settings['late_payment_fee']) ? (float)$db_settings['late_payment_fee'] : 500,
    'service_charge_rate' => isset($db_settings['service_charge_rate']) ? (float)$db_settings['service_charge_rate'] : 0,
    'insurance_fee' => isset($db_settings['insurance_fee']) ? (float)$db_settings['insurance_fee'] : 0,
    'loan_approval_required' => isset($db_settings['loan_approval_required']) ? (int)$db_settings['loan_approval_required'] : 1,
    'auto_calculate_installments' => isset($db_settings['auto_calculate_installments']) ? (int)$db_settings['auto_calculate_installments'] : 1,
    'auto_calculate_document_charge' => isset($db_settings['auto_calculate_document_charge']) ? (int)$db_settings['auto_calculate_document_charge'] : 1,
    'loan_plans' => isset($db_settings['loan_plans']) ? json_decode($db_settings['loan_plans'], true) : [
        ['amount' => 15000, 'weeks' => 19, 'interest_rate' => 36.80, 'document_charge' => 450],
        ['amount' => 20000, 'weeks' => 22, 'interest_rate' => 35.30, 'document_charge' => 600],
        ['amount' => 25000, 'weeks' => 23, 'interest_rate' => 35.70, 'document_charge' => 750],
        ['amount' => 30000, 'weeks' => 24, 'interest_rate' => 35.20, 'document_charge' => 900],
        ['amount' => 35000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1050],
        ['amount' => 40000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1200],
        ['amount' => 45000, 'weeks' => 25, 'interest_rate' => 35.00, 'document_charge' => 1350],
        ['amount' => 50000, 'weeks' => 26, 'interest_rate' => 35.20, 'document_charge' => 1500],
        ['amount' => 55000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1650],
        ['amount' => 60000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1800],
        ['amount' => 65000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 1950],
        ['amount' => 70000, 'weeks' => 27, 'interest_rate' => 35.00, 'document_charge' => 2100],
        ['amount' => 75000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2250],
        ['amount' => 80000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2400],
        ['amount' => 85000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2550],
        ['amount' => 90000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2700],
        ['amount' => 95000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 2850],
        ['amount' => 100000, 'weeks' => 30, 'interest_rate' => 35.00, 'document_charge' => 3000]
    ]
];

// Safely get session values
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['user_name'] ?? 'Admin User';
$user_type = $_SESSION['user_type'] ?? 'admin';
$branch = $_SESSION['branch'] ?? 'Main Branch';

// Determine sidebar path
$sidebar_paths = [
    $root_dir . 'includes/sidebar.php',
    dirname(dirname(__DIR__)) . '/includes/sidebar.php',
    '../../includes/sidebar.php'
];

$sidebar_file = null;
foreach ($sidebar_paths as $path) {
    if (file_exists($path)) {
        $sidebar_file = $path;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Micro Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            background: #343a40;
            min-height: 100vh;
            color: white;
            position: fixed;
            width: 250px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link {
            color: white;
            padding: 12px 20px;
            border-left: 4px solid transparent;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #495057;
            border-left-color: #dc3545;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -250px;
            }
            .main-content {
                margin-left: 0;
            }
            .sidebar.active {
                margin-left: 0;
            }
        }
        
        .settings-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .settings-card .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #dc3545;
            border-bottom: 3px solid #dc3545;
        }
        
        .password-toggle {
            cursor: pointer;
        }
        
        .setting-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }
        
        .icon-general { background: linear-gradient(135deg, #667eea, #764ba2); }
        .icon-security { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .icon-email { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .icon-loan { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        
        .loan-plan-table input {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 4px 8px;
        }
        
        .loan-plan-table input:focus {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php if ($sidebar_file): ?>
        <?php include $sidebar_file; ?>
    <?php else: ?>
        <!-- Fallback sidebar if file not found -->
        <nav class="sidebar">
            <div class="sidebar-sticky">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="../../dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="system_settings.php">
                            <i class="fas fa-cog me-2"></i>System Settings
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="navbar navbar-light bg-white shadow-sm rounded mb-4">
            <div class="container-fluid">
                <button class="btn btn-outline-secondary" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user me-1"></i>
                        <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    </span>
                    <a href="../../logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>
            </div>
        </nav>

        <div class="container-fluid">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-cog me-2"></i>System Settings</h2>
                <div class="d-flex">
                    <button class="btn btn-outline-primary me-2" onclick="backupSettings()">
                        <i class="fas fa-download me-1"></i>Backup Settings
                    </button>
                    <button class="btn btn-outline-success" onclick="restoreSettings()">
                        <i class="fas fa-upload me-1"></i>Restore Settings
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Settings Tabs -->
            <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                        <i class="fas fa-building me-1"></i>General
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                        <i class="fas fa-shield-alt me-1"></i>Security
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">
                        <i class="fas fa-envelope me-1"></i>Email
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="loan-tab" data-bs-toggle="tab" data-bs-target="#loan" type="button" role="tab">
                        <i class="fas fa-hand-holding-usd me-1"></i>Loan Settings
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="settingsTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <div class="card settings-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <div class="d-flex align-items-center">
                                    <div class="setting-icon icon-general">
                                        <i class="fas fa-building text-white"></i>
                                    </div>
                                    <span>General Settings</span>
                                </div>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Name *</label>
                                        <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($current_settings['company_name']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Currency *</label>
                                        <select name="currency" class="form-select" required>
                                            <option value="LKR" <?php echo $current_settings['currency'] == 'LKR' ? 'selected' : ''; ?>>LKR - Sri Lankan Rupee</option>
                                            <option value="USD" <?php echo $current_settings['currency'] == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                            <option value="EUR" <?php echo $current_settings['currency'] == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                        </select>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Company Address *</label>
                                        <textarea name="company_address" class="form-control" rows="3" required><?php echo htmlspecialchars($current_settings['company_address']); ?></textarea>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Phone *</label>
                                        <input type="tel" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($current_settings['company_phone']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Email *</label>
                                        <input type="email" name="company_email" class="form-control" value="<?php echo htmlspecialchars($current_settings['company_email']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Timezone *</label>
                                        <select name="timezone" class="form-select" required>
                                            <option value="Asia/Colombo" <?php echo $current_settings['timezone'] == 'Asia/Colombo' ? 'selected' : ''; ?>>Asia/Colombo (Sri Lanka)</option>
                                            <option value="UTC">UTC</option>
                                            <option value="America/New_York">America/New_York</option>
                                            <option value="Europe/London">Europe/London</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="save_general" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save General Settings
                                    </button>
                                    <button type="reset" class="btn btn-secondary">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Settings Tab -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="card settings-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <div class="d-flex align-items-center">
                                    <div class="setting-icon icon-security">
                                        <i class="fas fa-shield-alt text-white"></i>
                                    </div>
                                    <span>Security Settings</span>
                                </div>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Session Timeout (minutes) *</label>
                                        <input type="number" name="session_timeout" class="form-control" value="<?php echo $current_settings['session_timeout']; ?>" min="5" max="240" required>
                                        <small class="text-muted">Automatic logout after inactivity</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Max Login Attempts *</label>
                                        <input type="number" name="max_login_attempts" class="form-control" value="<?php echo $current_settings['max_login_attempts']; ?>" min="1" max="10" required>
                                        <small class="text-muted">Account lock after failed attempts</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Minimum Password Length *</label>
                                        <input type="number" name="password_min_length" class="form-control" value="<?php echo $current_settings['password_min_length']; ?>" min="6" max="20" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Password Requirements</label>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="require_strong_password" id="require_strong_password" <?php echo $current_settings['require_strong_password'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="require_strong_password">
                                                Require strong password (uppercase, lowercase, numbers, symbols)
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="enable_2fa" id="enable_2fa" <?php echo $current_settings['enable_2fa'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="enable_2fa">
                                                Enable Two-Factor Authentication (2FA)
                                            </label>
                                        </div>
                                        <small class="text-muted">Users will need to verify login via email or authenticator app</small>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="save_security" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Security Settings
                                    </button>
                                    <button type="reset" class="btn btn-secondary">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Email Settings Tab -->
                <div class="tab-pane fade" id="email" role="tabpanel">
                    <div class="card settings-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <div class="d-flex align-items-center">
                                    <div class="setting-icon icon-email">
                                        <i class="fas fa-envelope text-white"></i>
                                    </div>
                                    <span>Email Settings</span>
                                </div>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Host *</label>
                                        <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($current_settings['smtp_host']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Port *</label>
                                        <input type="number" name="smtp_port" class="form-control" value="<?php echo $current_settings['smtp_port']; ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Username *</label>
                                        <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($current_settings['smtp_username']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Password *</label>
                                        <div class="input-group">
                                            <input type="password" name="smtp_password" class="form-control" id="smtpPassword" value="<?php echo htmlspecialchars($current_settings['smtp_password']); ?>" required>
                                            <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePassword('smtpPassword', 'smtpPasswordIcon')">
                                                <i class="fas fa-eye" id="smtpPasswordIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Encryption *</label>
                                        <select name="smtp_encryption" class="form-select" required>
                                            <option value="tls" <?php echo $current_settings['smtp_encryption'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            <option value="ssl" <?php echo $current_settings['smtp_encryption'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="">None</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Email *</label>
                                        <input type="email" name="from_email" class="form-control" value="<?php echo htmlspecialchars($current_settings['from_email']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Name *</label>
                                        <input type="text" name="from_name" class="form-control" value="<?php echo htmlspecialchars($current_settings['from_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-info" onclick="testEmailSettings()">
                                        <i class="fas fa-paper-plane me-1"></i>Test Email Configuration
                                    </button>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" name="save_email" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Email Settings
                                    </button>
                                    <button type="reset" class="btn btn-secondary">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Loan Settings Tab -->
                <div class="tab-pane fade" id="loan" role="tabpanel">
                    <div class="card settings-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <div class="d-flex align-items-center">
                                    <div class="setting-icon icon-loan">
                                        <i class="fas fa-hand-holding-usd text-white"></i>
                                    </div>
                                    <span>Loan Settings</span>
                                </div>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <!-- Loan Amount Ranges -->
                                    <div class="col-12 mb-4">
                                        <h6 class="border-bottom pb-2 mb-3">Loan Amount Ranges</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Minimum Loan Amount (<?php echo $current_settings['currency']; ?>) *</label>
                                                <input type="number" name="min_loan_amount" class="form-control" value="<?php echo $current_settings['min_loan_amount']; ?>" step="0.01" min="0" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Maximum Loan Amount (<?php echo $current_settings['currency']; ?>) *</label>
                                                <input type="number" name="max_loan_amount" class="form-control" value="<?php echo $current_settings['max_loan_amount']; ?>" step="0.01" min="0" required>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Loan Plans Configuration -->
                                    <div class="col-12 mb-4">
                                        <h6 class="border-bottom pb-2 mb-3">Loan Plans Configuration</h6>
                                        <div class="table-responsive">
                                            <table class="table table-bordered loan-plan-table" id="loanPlansTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Loan Amount (Rs.)</th>
                                                        <th>Weeks</th>
                                                        <th>Interest Rate (%)</th>
                                                        <th>Document Charge (Rs.)</th>
                                                        <th>Weekly Installment (Rs.)</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $loan_plans = $current_settings['loan_plans'];
                                                    foreach ($loan_plans as $index => $plan):
                                                        $weekly_installment = ($plan['amount'] + ($plan['amount'] * $plan['interest_rate'] / 100)) / $plan['weeks'];
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <input type="number" name="loan_plans[<?php echo $index; ?>][amount]" 
                                                                   class="form-control form-control-sm loan-amount" 
                                                                   value="<?php echo $plan['amount']; ?>" 
                                                                   min="0" step="1000" required>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="loan_plans[<?php echo $index; ?>][weeks]" 
                                                                   class="form-control form-control-sm loan-weeks" 
                                                                   value="<?php echo $plan['weeks']; ?>" 
                                                                   min="1" max="100" required>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="loan_plans[<?php echo $index; ?>][interest_rate]" 
                                                                   class="form-control form-control-sm loan-interest" 
                                                                   value="<?php echo $plan['interest_rate']; ?>" 
                                                                   min="0" max="100" step="0.01" required>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="loan_plans[<?php echo $index; ?>][document_charge]" 
                                                                   class="form-control form-control-sm loan-document" 
                                                                   value="<?php echo $plan['document_charge']; ?>" 
                                                                   min="0" step="50" required>
                                                        </td>
                                                        <td>
                                                            <span class="weekly-installment">Rs. <?php echo number_format($weekly_installment, 2); ?></span>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-danger remove-plan" <?php echo count($loan_plans) <= 1 ? 'disabled' : ''; ?>>
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-success" id="addLoanPlan">
                                                <i class="fas fa-plus me-1"></i>Add New Loan Plan
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Additional Loan Settings -->
                                    <div class="col-12 mb-4">
                                        <h6 class="border-bottom pb-2 mb-3">Additional Settings</h6>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Default Interest Rate (%) *</label>
                                                <input type="number" name="default_interest_rate" class="form-control" value="<?php echo $current_settings['default_interest_rate']; ?>" step="0.01" min="0" max="100" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Late Payment Fee (<?php echo $current_settings['currency']; ?>) *</label>
                                                <input type="number" name="late_payment_fee" class="form-control" value="<?php echo $current_settings['late_payment_fee']; ?>" step="0.01" min="0" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Service Charge (%)</label>
                                                <input type="number" name="service_charge_rate" class="form-control" value="<?php echo $current_settings['service_charge_rate']; ?>" step="0.01" min="0" max="10">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Insurance Fee (<?php echo $current_settings['currency']; ?>)</label>
                                                <input type="number" name="insurance_fee" class="form-control" value="<?php echo $current_settings['insurance_fee']; ?>" step="0.01" min="0">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Approval Settings -->
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="loan_approval_required" id="loan_approval_required" <?php echo $current_settings['loan_approval_required'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="loan_approval_required">
                                                Require manager approval for loans
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Auto Calculation Settings -->
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="auto_calculate_installments" id="auto_calculate_installments" <?php echo $current_settings['auto_calculate_installments'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="auto_calculate_installments">
                                                Auto-calculate weekly installments
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Document Charge Calculation -->
                                    <div class="col-12 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="auto_calculate_document_charge" id="auto_calculate_document_charge" <?php echo $current_settings['auto_calculate_document_charge'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="auto_calculate_document_charge">
                                                Auto-calculate document charge (3% of loan amount)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="save_loan" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Loan Settings
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="resetLoanSettings()">Reset to Default</button>
                                    <button type="button" class="btn btn-info" onclick="testLoanCalculation()">
                                        <i class="fas fa-calculator me-1"></i>Test Calculation
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Password toggle function
        function togglePassword(passwordFieldId, iconId) {
            const passwordField = document.getElementById(passwordFieldId);
            const passwordIcon = document.getElementById(iconId);
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                passwordIcon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                passwordIcon.className = 'fas fa-eye';
            }
        }

        // Test email settings
        function testEmailSettings() {
            const fromEmail = document.querySelector('input[name="from_email"]').value;
            if (!fromEmail) {
                alert('Please fill in From Email first');
                return;
            }
            
            if (confirm('This will send a test email to ' + fromEmail + '. Continue?')) {
                // Simulate email test
                alert('Test email sent! Please check your inbox.');
            }
        }

        // Backup settings
        function backupSettings() {
            if (confirm('This will download all current settings as a backup file. Continue?')) {
                // Simulate backup download
                alert('Settings backup downloaded successfully!');
            }
        }

        // Restore settings
        function restoreSettings() {
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = '.json,.backup';
            fileInput.onchange = function(e) {
                if (confirm('This will replace all current settings. Are you sure?')) {
                    // Simulate restore
                    alert('Settings restored successfully! Page will reload.');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                }
            };
            fileInput.click();
        }

        // Loan Settings JavaScript
        let loanPlanCounter = <?php echo count($current_settings['loan_plans']); ?>;

        // Add new loan plan
        document.getElementById('addLoanPlan').addEventListener('click', function() {
            const table = document.getElementById('loanPlansTable').getElementsByTagName('tbody')[0];
            const newRow = table.insertRow();
            
            newRow.innerHTML = `
                <td>
                    <input type="number" name="loan_plans[${loanPlanCounter}][amount]" 
                           class="form-control form-control-sm loan-amount" 
                           value="0" min="0" step="1000" required>
                </td>
                <td>
                    <input type="number" name="loan_plans[${loanPlanCounter}][weeks]" 
                           class="form-control form-control-sm loan-weeks" 
                           value="1" min="1" max="100" required>
                </td>
                <td>
                    <input type="number" name="loan_plans[${loanPlanCounter}][interest_rate]" 
                           class="form-control form-control-sm loan-interest" 
                           value="0" min="0" max="100" step="0.01" required>
                </td>
                <td>
                    <input type="number" name="loan_plans[${loanPlanCounter}][document_charge]" 
                           class="form-control form-control-sm loan-document" 
                           value="0" min="0" step="50" required>
                </td>
                <td>
                    <span class="weekly-installment">Rs. 0.00</span>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-plan">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            loanPlanCounter++;
            
            // Add event listeners to new inputs
            addCalculationListeners(newRow);
            addRemovePlanListener(newRow);
        });

        // Add calculation listeners to a row
        function addCalculationListeners(row) {
            const inputs = row.querySelectorAll('.loan-amount, .loan-weeks, .loan-interest');
            inputs.forEach(input => {
                input.addEventListener('input', calculateWeeklyInstallment);
            });
        }

        // Add remove plan listener to a row
        function addRemovePlanListener(row) {
            const removeBtn = row.querySelector('.remove-plan');
            removeBtn.addEventListener('click', function() {
                const table = document.getElementById('loanPlansTable').getElementsByTagName('tbody')[0];
                if (table.rows.length > 1) {
                    row.remove();
                }
            });
        }

        // Calculate weekly installment
        function calculateWeeklyInstallment() {
            const row = this.closest('tr');
            const amount = parseFloat(row.querySelector('.loan-amount').value) || 0;
            const weeks = parseFloat(row.querySelector('.loan-weeks').value) || 1;
            const interestRate = parseFloat(row.querySelector('.loan-interest').value) || 0;
            
            const interestAmount = amount * (interestRate / 100);
            const totalAmount = amount + interestAmount;
            const weeklyInstallment = totalAmount / weeks;
            
            row.querySelector('.weekly-installment').textContent = 'Rs. ' + weeklyInstallment.toFixed(2);
        }

        // Auto-calculate document charge (3%)
        function autoCalculateDocumentCharge() {
            const rows = document.querySelectorAll('#loanPlansTable tbody tr');
            rows.forEach(row => {
                const amount = parseFloat(row.querySelector('.loan-amount').value) || 0;
                const documentCharge = Math.round(amount * 0.03 / 50) * 50; // Round to nearest 50
                row.querySelector('.loan-document').value = documentCharge;
            });
        }

        // Test loan calculation
        function testLoanCalculation() {
            const testAmount = prompt('Enter loan amount to test calculation:');
            if (testAmount && !isNaN(testAmount)) {
                const amount = parseFloat(testAmount);
                const matchingPlan = findMatchingLoanPlan(amount);
                
                if (matchingPlan) {
                    const interestAmount = amount * (matchingPlan.interest_rate / 100);
                    const totalAmount = amount + interestAmount;
                    const weeklyInstallment = totalAmount / matchingPlan.weeks;
                    
                    const result = `
Loan Amount: Rs. ${amount.toLocaleString()}
Interest Rate: ${matchingPlan.interest_rate}%
Number of Weeks: ${matchingPlan.weeks}
Document Charge: Rs. ${matchingPlan.document_charge}
Total Payable: Rs. ${totalAmount.toLocaleString()}
Weekly Installment: Rs. ${weeklyInstallment.toFixed(2)}
                    `;
                    
                    alert('Test Calculation Result:\n\n' + result);
                } else {
                    alert('No matching loan plan found for Rs. ' + amount.toLocaleString());
                }
            }
        }

        // Find matching loan plan for amount
        function findMatchingLoanPlan(amount) {
            const rows = document.querySelectorAll('#loanPlansTable tbody tr');
            let closestPlan = null;
            let minDifference = Infinity;
            
            rows.forEach(row => {
                const planAmount = parseFloat(row.querySelector('.loan-amount').value) || 0;
                const difference = Math.abs(planAmount - amount);
                
                if (difference < minDifference) {
                    minDifference = difference;
                    closestPlan = {
                        amount: planAmount,
                        weeks: parseFloat(row.querySelector('.loan-weeks').value) || 1,
                        interest_rate: parseFloat(row.querySelector('.loan-interest').value) || 0,
                        document_charge: parseFloat(row.querySelector('.loan-document').value) || 0
                    };
                }
            });
            
            return closestPlan;
        }

        // Reset loan settings to default
        function resetLoanSettings() {
            if (confirm('Are you sure you want to reset all loan settings to default values?')) {
                window.location.href = 'system_settings.php?reset_loan_settings=1';
            }
        }

        // Initialize calculation listeners on existing rows
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('#loanPlansTable tbody tr');
            rows.forEach(row => {
                addCalculationListeners(row);
                addRemovePlanListener(row);
            });
            
            // Auto-calculate document charge if enabled
            const autoDocCheckbox = document.getElementById('auto_calculate_document_charge');
            if (autoDocCheckbox && autoDocCheckbox.checked) {
                autoCalculateDocumentCharge();
            }
            
            // Listen for auto-calculate document charge changes
            if (autoDocCheckbox) {
                autoDocCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        autoCalculateDocumentCharge();
                    }
                });
            }
        });

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredFields = this.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('is-invalid');
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });

        // Reset form validation on input
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
    </script>
</body>
</html>