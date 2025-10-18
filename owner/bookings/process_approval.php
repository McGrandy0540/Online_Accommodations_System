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
$deposit_amount = floatval($_POST['deposit_amount'] ?? 0);
$approval_notes = trim($_POST['approval_notes'] ?? '');

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
    
    // Update booking status to confirmed
    $updateStmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'confirmed', 
            deposit_amount = ?,
            approval_notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$deposit_amount, $approval_notes, $booking_id]);
    
    // Update room occupancy if room is assigned
    if ($booking['room_id']) {
        $roomStmt = $pdo->prepare("
            UPDATE property_rooms 
            SET current_occupancy = current_occupancy + 1,
                available_spots = GREATEST(available_spots - 1, 0)
            WHERE id = ?
        ");
        $roomStmt->execute([$booking['room_id']]);
        
        // Update room status if fully occupied
        $statusStmt = $pdo->prepare("
            UPDATE property_rooms
            SET status = CASE 
                WHEN current_occupancy >= capacity THEN 'occupied'
                ELSE 'available'
            END
            WHERE id = ?
        ");
        $statusStmt->execute([$booking['room_id']]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Send notification using NotificationService (automatic email & SMS)
    $notificationService = new NotificationService();
    
    // Prepare detailed confirmation message
    $message = "Great news! Your booking for {$booking['property_name']} has been confirmed by the property owner.";
    if ($booking['room_number']) {
        $message .= " You've been assigned to Room {$booking['room_number']}.";
    }
    $message .= " Check-in date: " . date('M j, Y', strtotime($booking['start_date']));
    
    if ($deposit_amount > 0) {
        $message .= " Deposit amount: GHS " . number_format($deposit_amount, 2);
    }
    
    if (!empty($approval_notes)) {
        $message .= " Note from owner: " . $approval_notes;
    }
    
    // Send booking notification (automatically sends email and SMS)
    $notificationService->sendBookingNotification(
        $booking['user_id'],
        $booking_id,
        'confirmed',
        $booking['property_name'],
        $booking['room_number'] ?? null,
        true // Send email
    );
    
    $_SESSION['success'] = "Booking confirmed successfully! The student has been notified via email and SMS.";
    header("Location: view.php?id=" . $booking_id);
    exit();
    
} catch (Exception $e) {
    // Rollback on error
    $pdo->rollBack();
    error_log("Booking approval error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to approve booking. Please try again.";
    header("Location: approve.php?id=" . $booking_id);
    exit();
}
