<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = $_POST['booking_id'];
    
    try {
        // Update booking status to approved
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'approved', updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $booking_id]);
        
        $_SESSION['success'] = "Booking #$booking_id has been approved successfully";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error approving booking: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit();
} else {
    header("Location: index.php");
    exit();
}
?>