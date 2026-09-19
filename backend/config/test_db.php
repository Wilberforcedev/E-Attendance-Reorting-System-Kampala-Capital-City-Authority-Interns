<?php
include_once '../config/db.php';

$database = new Database();
$db = $database->getConnection();

if($db) {
    echo "Database connection successful!";
    print_r($db->getAttribute(PDO::ATTR_CONNECTION_STATUS));
} else {
    echo "Database connection failed";
    print_r($db->errorInfo());
}
?>