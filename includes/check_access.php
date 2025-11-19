<?php
// includes/check_access.php

function checkAccess($required_type = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Check if user has access to current page
    $current_page = $_SERVER['PHP_SELF'];
    if (!hasAccess($current_page)) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit();
    }
    
    if ($required_type && $_SESSION['user_type'] !== $required_type) {
        $_SESSION['error'] = "Access denied! You don't have permission to access this page.";
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit();
    }
    
    return true;
}

function hasAccess($page_path) {
    global $conn;
    
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    $user_type = $_SESSION['user_type'];
    
    // Admin users have access to all pages
    if ($user_type === 'admin') {
        return true;
    }
    
    // Check if user_permissions table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'user_permissions'");
    if ($table_check->num_rows == 0) {
        // If table doesn't exist, allow access (for initial setup)
        return true;
    }
    
    // Get the relative path from BASE_URL
    $relative_path = str_replace(BASE_URL, '', $page_path);
    if (empty($relative_path)) {
        $relative_path = $page_path;
    }
    
    // Check permission in database
    $sql = "SELECT can_access FROM user_permissions WHERE user_type = ? AND page_path = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $user_type, $relative_path);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (bool)$row['can_access'];
    }
    
    // Default access for pages not in permission table
    return true;
}

// Function to check if menu item should be shown
function showMenuItem($page_path) {
    return hasAccess($page_path);
}
?>