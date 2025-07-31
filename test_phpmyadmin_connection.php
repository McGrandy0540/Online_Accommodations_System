<?php
$host = '162.214.26.15';
$dbname = 'if0_39582071_online_accommodation';
$username = 'if0_39582071';
$password = 'mcgrandy0408';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    echo "PHPMyAdmin connection successful!";
} catch (PDOException $e) {
    echo "PHPMyAdmin connection failed: " . $e->getMessage();
}
?>