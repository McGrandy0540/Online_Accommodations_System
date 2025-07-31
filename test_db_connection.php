<?php
require_once 'config/database.php';

try {
    $db = Database::getInstance();
    echo "Database connection successful!";
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage();
}
?>