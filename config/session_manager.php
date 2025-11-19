<?php
// includes/session_manager.php

function checkSessionTimeout() {
    $timeout = 1800; // 30 minutes
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        // Session expired
        session_unset();
        session_destroy();
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'timeout']);
            exit;
        } else {
            header("Location: login.php?timeout=1");
            exit;
        }
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

function resetSessionTimer() {
    $_SESSION['last_activity'] = time();
}
?>