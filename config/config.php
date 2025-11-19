<?php
// config/config.php

// Start session only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'dtrmfslh_micro_finance');
define('DB_PASS', 'M?2H.14}L9@AL4rS');
define('DB_NAME', 'dtrmfslh_micro_finance');

// Base URL
define('BASE_URL', 'http://dtrmf20251019.slhosted.lk');

// Site configuration
define('SITE_NAME', 'Beyond Investment (PVT) Limited');
define('SITE_VERSION', '1.0.0');

// SMS Configuration
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'textlk');
define('SMS_USE_OAUTH', true);
define('SMS_API_URL', 'https://app.text.lk/api/v3');
define('SMS_HTTP_API_URL', 'https://app.text.lk/api/http');
define('SMS_API_TOKEN', '1938|H2Ygz6gaK8H9BCKSu6nqNS2xDHt1hp9KIbAqQEE38cd7796c');
define('SMS_SENDER_ID', 'BEYOND_INVE');
define('SMS_TEST_MODE', false);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Asia/Colombo');

// Database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Auto logout after 30 minutes of inactivity
$timeout = 1800;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}

if (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Include check_access.php if it exists
$check_access_path = __DIR__ . '/../includes/check_access.php';
if (file_exists($check_access_path)) {
    require_once $check_access_path;
} else {
    function checkAccess() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ../login.php");
            exit;
        }
    }
}

// Include functions.php for common functions
$functions_path = __DIR__ . '/../includes/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

// Include auto permissions scanner
$auto_permissions_path = __DIR__ . '/../includes/auto_permissions.php';
$file_monitor_path = __DIR__ . '/../includes/file_monitor.php';

if (file_exists($auto_permissions_path) && file_exists($file_monitor_path)) {
    require_once $auto_permissions_path;
    require_once $file_monitor_path;
    
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        checkAutoScan();
    }
}

// Include sidebar generator
$sidebar_generator_path = __DIR__ . '/../includes/sidebar_generator.php';
if (file_exists($sidebar_generator_path)) {
    require_once $sidebar_generator_path;
    
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        $new_files = autoUpdateMenuStructure();
        if (!empty($new_files)) {
            error_log("Auto-menu: New files detected that need permissions: " . count($new_files));
        }
    }
}

// Include SMS functions if they exist
$sms_functions_path = __DIR__ . '/../includes/sms_functions.php';
$loan_sms_functions_path = __DIR__ . '/../includes/loan_sms_functions.php';

if (file_exists($sms_functions_path)) {
    require_once $sms_functions_path;
}

if (file_exists($loan_sms_functions_path)) {
    require_once $loan_sms_functions_path;
}

// Auto-create necessary tables if they don't exist
function createRequiredTables() {
    global $conn;
    
    $tables = [
        "CREATE TABLE IF NOT EXISTS sms_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            phone_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(100) NOT NULL,
            sent_at DATETIME NOT NULL,
            response_text TEXT NULL,
            loan_id INT NULL,
            customer_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(255) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL
        )",
        
        "CREATE TABLE IF NOT EXISTS system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            value TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        )"
    ];
    
    foreach ($tables as $sql) {
        $conn->query($sql);
    }
}

// Run table creation on admin access
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    createRequiredTables();
}

// Close database connection on script end
register_shutdown_function(function() {
    global $conn;
    if (isset($conn)) {
        $conn->close();
    }
});