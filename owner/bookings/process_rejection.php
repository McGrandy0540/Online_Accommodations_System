<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/NotificationService.php';

// Check if user is logged in and is owner
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    $_SESSION['error'] = "Unauthorized access";
    header("Location: ../../auth/login.php");
    exit();
}

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method";
    header("Location: index.php");
    exit();
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$custom_reason = trim($_POST['custom_reason'] ?? '');

// Combine reason with custom reason if "Other" was selected
if (stripos($reason, 'Other') !== false && !empty($custom_reason)) {
    $reason = 'Other: ' . $custom_reason;
}

if (empty($reason)) {
    $_SESSION['error'] = "Please provide a reason for rejection";
    header("Location: reject.php?id=" . $booking_id);
    exit();
}

// Verify booking belongs to owner and is pending
$stmt = $pdo->prepare("
    SELECT b.*, p.property_name, p.id as property_id, u.username as student_name, u.email as student_email,
           pr.room_number
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.id = ? AND p.owner_id = ? AND b.status = 'pending'
");
$stmt->execute([$booking_id, $owner_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['error'] = "Booking not found or already processed";
    header("Location: index.php");
    exit();
}

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Update booking status to rejected
    $updateStmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'rejected', 
            rejection_reason = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$reason, $booking_id]);
    
    // If room was reserved, free it up
    if ($booking['room_id']) {
        $roomStmt = $pdo->prepare("
            UPDATE property_rooms 
            SET current_occupancy = GREATEST(current_occupancy - 1, 0),
                available_spots = LEAST(available_spots + 1, capacity),
                status = 'available'
            WHERE id = ?
        ");
        $roomStmt->execute([$booking['room_id']]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Send notification using NotificationService (automatic email & SMS)
    $notificationService = new NotificationService();
    
    // Prepare rejection message
    $message = "We regret to inform you that your booking request for {$booking['property_name']} has been declined by the property owner.";
    if ($booking['room_number']) {
        $message .= " (Room {$booking['room_number']})";
    }
    $message .= " Reason: {$reason}. Please consider booking another property or contact the owner for more information.";
    
    // Send booking notification (automatically sends email and SMS)
    $notificationService->sendBookingNotification(
        $booking['user_id'],
        $booking_id,
        'rejected',
        $booking['property_name'],
        $booking['room_number'] ?? null,
        true // Send email
    );
    
    $_SESSION['success'] = "Booking rejected successfully! The student has been notified via email and SMS.";
    header("Location: index.php");
    exit();
    
} catch (Exception $e) {
    // Rollback on error
    $pdo->rollBack();
    error_log("Booking rejection error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to reject booking. Please try again.";
    header("Location: reject.php?id=" . $booking_id);
    exit();
}
