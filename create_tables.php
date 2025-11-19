<?php
// create_tables.php
require_once 'config/database.php';

echo "<h3>Creating Database Tables</h3>";

$queries = [
    // User roles table
    "CREATE TABLE IF NOT EXISTS user_roles (
        id INT PRIMARY KEY AUTO_INCREMENT,
        role_name VARCHAR(50) UNIQUE NOT NULL,
        permissions JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    // Insert default roles
    "INSERT IGNORE INTO user_roles (role_name, permissions) VALUES 
    ('admin', '[\"all\"]'),
    ('manager', '[\"users.view\", \"users.create\", \"users.edit\", \"loans.view\", \"loans.approve\", \"reports.view\"]'),
    ('credit_officer', '[\"customers.view\", \"customers.create\", \"loans.view\", \"loans.create\", \"collections.view\", \"collections.create\"]'),
    ('accountant', '[\"transactions.view\", \"transactions.create\", \"reports.view\", \"collections.view\"]')",
    
    // Add role_id column to users table if not exists
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT AFTER user_type",
    
    // Update existing users with role_ids
    "UPDATE users SET role_id = 1 WHERE user_type = 'admin'",
    "UPDATE users SET role_id = 2 WHERE user_type = 'manager'",
    "UPDATE users SET role_id = 3 WHERE user_type = 'credit_officer'",
    "UPDATE users SET role_id = 4 WHERE user_type = 'accountant'"
];

foreach($queries as $query) {
    if($conn->query($query)) {
        echo "✅ Query executed successfully<br>";
    } else {
        echo "❌ Error: " . $conn->error . "<br>";
    }
}

echo "<h3>Tables created successfully!</h3>";
echo "<a href='user_management.php' class='btn btn-primary'>Go to User Management</a>";
?>