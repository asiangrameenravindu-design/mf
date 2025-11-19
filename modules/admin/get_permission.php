<?php
// modules/admin/get_permission.php
session_start();

// Correct path to config.php
require_once __DIR__ . '/../../config/config.php';

if ($_SESSION['user_type'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID required']);
    exit();
}

$id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT * FROM permissions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $permission = $result->fetch_assoc();
    echo json_encode(['success' => true, 'permission' => $permission]);
} else {
    echo json_encode(['success' => false, 'message' => 'Permission not found']);
}
?>