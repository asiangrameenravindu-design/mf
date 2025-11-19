<?php
// debug_claim_fixed.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug - Fixed Version</h1>";

// Step 1: Load config
echo "<h3>Step 1: Loading Config</h3>";
try {
    require_once '../../config/config.php';
    echo "✓ Config loaded successfully<br>";
    
    // Check which database variable exists
    if (isset($conn)) {
        echo "✓ MySQLi connection found (\$conn)<br>";
    } 
    if (isset($db)) {
        echo "✓ PDO connection found (\$db)<br>";
    }
    
} catch (Exception $e) {
    echo "✗ Config error: " . $e->getMessage() . "<br>";
    exit;
}

// Step 2: Test database connection
echo "<h3>Step 2: Database Test</h3>";
try {
    if ($conn->ping()) {
        echo "✓ Database connection active<br>";
    } else {
        echo "✗ Database connection failed<br>";
    }
} catch (Exception $e) {
    echo "✗ Database test error: " . $e->getMessage() . "<br>";
}

// Step 3: Test insurance_claims table
echo "<h3>Step 3: Table Test</h3>";
$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 1;
echo "Testing claim ID: $claim_id<br>";

try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM insurance_claims WHERE id = ?");
    $stmt->bind_param("i", $claim_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if ($data['count'] > 0) {
        echo "✓ Claim found in database<br>";
        
        // Get claim details
        $stmt = $conn->prepare("SELECT * FROM insurance_claims WHERE id = ?");
        $stmt->bind_param("i", $claim_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $claim = $result->fetch_assoc();
        
        echo "✓ Claim details loaded:<br>";
        echo "- Voucher: " . htmlspecialchars($claim['voucher_no']) . "<br>";
        echo "- Customer ID: " . $claim['customer_id'] . "<br>";
        echo "- Status: " . $claim['status'] . "<br>";
        
    } else {
        echo "✗ No claim found with ID: $claim_id<br>";
    }
    
} catch (Exception $e) {
    echo "✗ Table test error: " . $e->getMessage() . "<br>";
}

echo "<hr><h3>Debug Complete</h3>";
?>