<?php
// config/database.php

$host = 'localhost';
$username = 'dtrmfslh_micro_finance';
$password = 'M?2H.14}L9@AL4rS';
$database = 'dtrmfslh_micro_finance';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset("utf8");

/**
 * Get database connection
 */
function getDatabaseConnection() {
    global $conn;
    return $conn;
}

/**
 * Close database connection
 */
function closeDatabaseConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}
?>