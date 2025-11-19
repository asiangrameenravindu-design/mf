<?php
// includes/auto_permissions.php

/**
 * Auto file scanner for permissions system
 * Scans ALL modules directories and adds new PHP files to permissions table
 */

function scanAndAddNewFiles() {
    global $conn;
    
    // Get ALL directories in modules folder
    $modules_base = __DIR__ . '/../modules/';
    $scan_directories = ['modules/']; // Start with base modules directory
    
    // Add all subdirectories in modules
    if (is_dir($modules_base)) {
        $module_folders = scandir($modules_base);
        foreach ($module_folders as $folder) {
            if ($folder != '.' && $folder != '..' && is_dir($modules_base . $folder)) {
                $scan_directories[] = 'modules/' . $folder . '/';
            }
        }
    }
    
    // Also include other important directories
    $additional_directories = [
        'dashboard/',
        'reports/',
        'includes/',
        'templates/',
        'assets/php/'
    ];
    
    $scan_directories = array_merge($scan_directories, $additional_directories);
    
    $found_files = [];
    
    // Scan each directory
    foreach ($scan_directories as $directory) {
        $full_path = __DIR__ . '/../' . $directory;
        
        if (is_dir($full_path)) {
            echo "Scanning directory: $directory<br>"; // Debug line
            $files = scanDirectoryForPHPFiles($full_path, $directory);
            $found_files = array_merge($found_files, $files);
        }
    }
    
    // Remove duplicates and add to database
    $found_files = array_unique($found_files);
    $added_count = 0;
    
    foreach ($found_files as $file_path) {
        if (addFileToPermissions($file_path)) {
            $added_count++;
        }
    }
    
    return $added_count;
}

/**
 * Recursively scan directory for PHP files
 */
function scanDirectoryForPHPFiles($directory, $base_path = '') {
    $php_files = [];
    
    try {
        $items = scandir($directory);
        
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $full_path = $directory . $item;
            
            if (is_dir($full_path)) {
                // Recursively scan subdirectories
                $sub_files = scanDirectoryForPHPFiles($full_path . '/', $base_path . $item . '/');
                $php_files = array_merge($php_files, $sub_files);
            } elseif (is_file($full_path) && pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                // Skip files that are likely not pages (like class files, includes)
                if (shouldIncludeFile($item, $base_path)) {
                    $relative_path = $base_path . $item;
                    $php_files[] = $relative_path;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error scanning directory {$directory}: " . $e->getMessage());
    }
    
    return $php_files;
}

/**
 * Determine if a file should be included in permissions
 */
function shouldIncludeFile($filename, $path) {
    // Files to exclude (utility files, classes, etc.)
    $exclude_patterns = [
        'config.php',
        'database.php',
        'functions.php',
        'header.php',
        'footer.php',
        'sidebar.php',
        'class.',
        'abstract.',
        'interface.',
        'trait.',
        'helper.php',
        'util.php',
        'ajax_',
        'api_',
        'test.php',
        'example.php'
    ];
    
    foreach ($exclude_patterns as $pattern) {
        if (strpos($filename, $pattern) !== false) {
            return false;
        }
    }
    
    // Include files that are likely pages
    $include_patterns = [
        'manage_',
        'add_',
        'edit_',
        'view_',
        'list_',
        'report_',
        'dashboard',
        'index.php'
    ];
    
    foreach ($include_patterns as $pattern) {
        if (strpos($filename, $pattern) !== false) {
            return true;
        }
    }
    
    // Default: include all PHP files in modules directories
    return strpos($path, 'modules/') === 0;
}

/**
 * Add a file to permissions table if it doesn't exist
 */
function addFileToPermissions($file_path) {
    global $conn;
    
    // Clean file path
    $file_path = trim($file_path);
    
    // Check if file already exists in permissions
    $check_sql = "SELECT id FROM user_permissions WHERE page_path = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $file_path);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        // File doesn't exist, add it with default permissions
        $default_permissions = getDefaultPermissions($file_path);
        
        foreach ($default_permissions as $user_type => $can_access) {
            $insert_sql = "INSERT INTO user_permissions (user_type, page_path, can_access) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssi", $user_type, $file_path, $can_access);
            $insert_stmt->execute();
        }
        
        return true;
    }
    
    return false;
}

/**
 * Get default permissions based on file path and type
 */
function getDefaultPermissions($file_path) {
    $user_types = ['admin', 'manager', 'field_officer', 'accountant'];
    $default_permissions = [];
    
    // Admin files - only admin can access
    if (strpos($file_path, 'modules/admin/') === 0 || 
        strpos($file_path, 'admin/') === 0 ||
        basename($file_path) === 'manage_permissions.php' ||
        basename($file_path) === 'user_management.php' ||
        strpos($file_path, 'user_management') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = ($type === 'admin') ? 1 : 0;
        }
        return $default_permissions;
    }
    
    // Loan management files - all except admin
    if (strpos($file_path, 'modules/loans/') === 0 || 
        strpos($file_path, 'loans/') === 0 ||
        strpos($file_path, 'loan_') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = ($type === 'admin') ? 0 : 1;
        }
        return $default_permissions;
    }
    
    // Customer management - all users
    if (strpos($file_path, 'modules/customers/') === 0 || 
        strpos($file_path, 'customers/') === 0 ||
        strpos($file_path, 'customer_') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = 1;
        }
        return $default_permissions;
    }
    
    // Reports - all users can access
    if (strpos($file_path, 'modules/reports/') === 0 || 
        strpos($file_path, 'reports/') === 0 ||
        strpos($file_path, 'report_') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = 1;
        }
        return $default_permissions;
    }
    
    // Groups - all users can access
    if (strpos($file_path, 'modules/groups/') === 0 || 
        strpos($file_path, 'groups/') === 0 ||
        strpos($file_path, 'group_') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = 1;
        }
        return $default_permissions;
    }
    
    // CBO management - all except manager
    if (strpos($file_path, 'modules/cbo/') === 0 || 
        strpos($file_path, 'cbo/') === 0 ||
        strpos($file_path, 'cbo_') !== false) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = ($type === 'manager') ? 0 : 1;
        }
        return $default_permissions;
    }
    
    // Dashboard - all users can access
    if (strpos($file_path, 'dashboard.php') !== false || 
        strpos($file_path, 'dashboard/') === 0) {
        
        foreach ($user_types as $type) {
            $default_permissions[$type] = 1;
        }
        return $default_permissions;
    }
    
    // Default: all users can access
    foreach ($user_types as $type) {
        $default_permissions[$type] = 1;
    }
    
    return $default_permissions;
}

/**
 * Get display name for file path
 */
function getDisplayName($file_path, $default_name) {
    $path_mappings = [
        'modules/customers/' => 'Customers - ',
        'modules/loans/' => 'Loans - ',
        'modules/groups/' => 'Groups - ',
        'modules/cbo/' => 'CBO - ',
        'modules/reports/' => 'Reports - ',
        'modules/users/' => 'Users - ',
        'modules/admin/' => 'Admin - ',
        'modules/savings/' => 'Savings - ',
        'modules/transactions/' => 'Transactions - ',
        'modules/settings/' => 'Settings - ',
        'modules/analytics/' => 'Analytics - ',
        'dashboard/' => 'Dashboard - ',
        'reports/' => 'Reports - ',
        'includes/' => 'Includes - '
    ];
    
    foreach ($path_mappings as $path => $prefix) {
        if (strpos($file_path, $path) === 0) {
            $file_name = str_replace($path, '', $file_path);
            $file_name = str_replace(['.php', '_'], ['', ' '], $file_name);
            return $prefix . ucwords($file_name);
        }
    }
    
    // For any other modules
    if (strpos($file_path, 'modules/') === 0) {
        $parts = explode('/', $file_path);
        if (count($parts) >= 2) {
            $module_name = $parts[1];
            $file_name = str_replace(['.php', '_'], ['', ' '], $parts[count($parts)-1]);
            return ucfirst($module_name) . ' - ' . ucwords($file_name);
        }
    }
    
    // For files not in mapped directories
    $clean_name = str_replace(['.php', '_'], ['', ' '], $default_name);
    return ucwords($clean_name);
}

/**
 * Manual scan function that can be called from any page
 */
function manualScanNewFiles() {
    $new_files_count = scanAndAddNewFiles();
    
    if ($new_files_count > 0) {
        $_SESSION['success'] = "Auto-scanner found and added {$new_files_count} new files to permissions system.";
    } else {
        $_SESSION['info'] = "No new files found. All files are already in permissions system.";
    }
    
    return $new_files_count;
}

/**
 * Get list of all modules dynamically
 */
function getAllModules() {
    $modules_base = __DIR__ . '/../modules/';
    $modules = [];
    
    if (is_dir($modules_base)) {
        $items = scandir($modules_base);
        foreach ($items as $item) {
            if ($item != '.' && $item != '..' && is_dir($modules_base . $item)) {
                $modules[] = $item;
            }
        }
    }
    
    return $modules;
}

// Auto-scan on admin pages (optional - can be disabled)
if (defined('AUTO_SCAN_PERMISSIONS') && AUTO_SCAN_PERMISSIONS === true) {
    register_shutdown_function('scanAndAddNewFiles');
}
?>