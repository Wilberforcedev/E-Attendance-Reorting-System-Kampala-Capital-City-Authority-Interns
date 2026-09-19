<?php
require_once 'C:/xampp/htdocs/ears-system/backend/config/db.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Database connection successful!<br>";
    echo "Server version: " . $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>