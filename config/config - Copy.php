<?php
// config/config.php

// Start session only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'micro_finance');

// Base URL - Adjust this according to your project path
define('BASE_URL', 'http://localhost/micro_finance_system');

// Site configuration
define('SITE_NAME', 'Micro Finance Management System');
define('SITE_VERSION', '1.0.0');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Asia/Colombo');

// Database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Set charset
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Auto logout after 30 minutes of inactivity
$timeout = 1800; // 30 minutes in seconds

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    // Last request was more than 30 minutes ago
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}

// Update last activity time
if (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Include check_access.php if it exists
$check_access_path = __DIR__ . '/../includes/check_access.php';
if (file_exists($check_access_path)) {
    require_once $check_access_path;
}

// config/config.php එකේ අන්තිමට add කරන්න

// Include auto permissions scanner
$auto_permissions_path = __DIR__ . '/../includes/auto_permissions.php';
if (file_exists($auto_permissions_path)) {
    require_once $auto_permissions_path;
    
    // Run auto-scan on every page load (only for admin users)
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        scanAndAddNewFiles();
    }
}

// config/config.php එකේ auto permissions section එක

// Include auto permissions scanner
$auto_permissions_path = __DIR__ . '/../includes/auto_permissions.php';
$file_monitor_path = __DIR__ . '/../includes/file_monitor.php';

if (file_exists($auto_permissions_path) && file_exists($file_monitor_path)) {
    require_once $auto_permissions_path;
    require_once $file_monitor_path;
    
    // Run auto-scan check on every page load (only for admin users)
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        checkAutoScan();
    }
}
// config/config.php එකේ අන්තිමට add කරන්න

// Include sidebar generator
$sidebar_generator_path = __DIR__ . '/../includes/sidebar_generator.php';
if (file_exists($sidebar_generator_path)) {
    require_once $sidebar_generator_path;
    
    // Auto-update menu structure on admin pages
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        $new_files = autoUpdateMenuStructure();
        if (!empty($new_files)) {
            // Log new files found (you can add notification here)
            error_log("Auto-menu: New files detected that need permissions: " . count($new_files));
        }
    }
}
?>