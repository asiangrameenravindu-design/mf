<?php
require_once '../../config/config.php';
header('Content-Type: application/json');

if (!isset($_GET['center_id']) || empty($_GET['center_id'])) {
    echo json_encode([]);
    exit;
}

$center_id = (int)$_GET['center_id'];

try {
    $query = "SELECT DISTINCT c.id, c.full_name, c.nic 
              FROM customers c
              INNER JOIN cbo_members cm ON c.id = cm.customer_id
              WHERE cm.cbo_id = ? AND cm.status = 'active'
              ORDER BY c.full_name";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $center_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }

    echo json_encode($customers);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>