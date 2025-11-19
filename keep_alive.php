<?php
// keep_alive.php
require_once 'config/config.php';

header('Content-Type: application/json');

// Check if session is still valid
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'timeout']);
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

echo json_encode(['status' => 'success', 'message' => 'Session kept alive']);
?>