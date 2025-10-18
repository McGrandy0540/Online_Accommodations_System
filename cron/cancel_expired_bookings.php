<?php
/**
 * Automatic Booking Cancellation Cron Job
 * 
 * This script should be run every 5-10 minutes via cron job to automatically
 * cancel bookings that have exceeded their 24-hour payment deadline.
 * 
 * Cron job example (runs every 5 minutes):
 * [asterisk]/5 * * * * /usr/bin/php /path/to/project/cron/cancel_expired_bookings.php >> /path/to/logs/booking_cancellation.log 2>&1
 * Replace [asterisk] with the actual * character
 */

// Set execution time limit
set_time_limit(300); // 5 minutes

// Include database connection
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/BookingCancellationMailer.php';

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message" . PHP_EOL;
}

try {
    $pdo = Database::getInstance();
    
    logMessage("Starting automatic booking cancellation check...");
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Find all expired pending bookings
    $stmt = $pdo->prepare("
        SELECT b.id, b.user_id, b.property_id, b.room_id, b.payment_deadline,
               pr.available_spots, pr.capacity, pr.current_occupancy,
               p.property_name, pr.room_number, u.username, u.email
        FROM bookings b
        JOIN property p ON b.property_id = p.id
        LEFT JOIN property_rooms pr ON b.room_id = pr.id
        LEFT JOIN users u ON b.user_id = u.id
        WHERE b.status = 'pending'
        AND b.payment_deadline IS NOT NULL
        AND b.payment_deadline < NOW()
        AND b.cancelled_at IS NULL
    ");
    
    $stmt->execute();
    $expired_bookings = $stmt->fetchAll();
    
    $cancelled_count = 0;
    $failed_count = 0;
    
    foreach ($expired_bookings as $booking) {
        try {
            // Cancel the booking
            $cancel_stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = 'cancelled', 
                    cancelled_at = NOW(),
                    cancellation_reason = 'Automatic cancellation - Payment deadline expired'
                WHERE id = ?
            ");
            $cancel_stmt->execute([$booking['id']]);
            
            // Update room availability if room exists
            if ($booking['room_id']) {
                // Increase available spots (pending bookings don't affect current_occupancy)
                $new_available_spots = min(
                    $booking['capacity'], 
                    $booking['available_spots'] + 1
                );
                
                $room_stmt = $pdo->prepare("
                    UPDATE property_rooms 
                    SET available_spots = ?,
                        status = CASE 
                            WHEN current_occupancy < capacity THEN 'available'
                            ELSE 'occupied'
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $room_stmt->execute([$new_available_spots, $booking['room_id']]);
                
                logMessage("Cancelled booking #{$booking['id']} - User: {$booking['username']}, Property: {$booking['property_name']}, Room: {$booking['room_number']}");
            } else {
                logMessage("Cancelled booking #{$booking['id']} - User: {$booking['username']}, Property: {$booking['property_name']} (No room assigned)");
            }
            
            $cancelled_count++;
            
            // Send email notification to user
            if (!empty($booking['email']) && filter_var($booking['email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    $mailer = new BookingCancellationMailer();
                    $bookingDetails = [
                        'booking_id' => $booking['id'],
                        'property_name' => $booking['property_name'],
                        'room_number' => $booking['room_number']
                    ];
                    
                    $emailSent = $mailer->sendCancellationNotification(
                        $booking['email'],
                        $booking['username'],
                        $bookingDetails
                    );
                    
                    if ($emailSent) {
                        logMessage("✓ Sent cancellation notification email to {$booking['email']}");
                    } else {
                        logMessage("✗ Failed to send email to {$booking['email']}: " . $mailer->getLastError());
                    }
                } catch (Exception $emailError) {
                    logMessage("✗ Email error for {$booking['email']}: " . $emailError->getMessage());
                }
            }
            
        } catch (Exception $e) {
            logMessage("Failed to cancel booking #{$booking['id']}: " . $e->getMessage());
            $failed_count++;
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    logMessage("Cancellation complete: {$cancelled_count} bookings cancelled, {$failed_count} failed");
    logMessage("----------------------------------------");
    
    // Return success status
    exit(0);
    
} catch (Exception $e) {
    // Rollback on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logMessage("ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    logMessage("----------------------------------------");
    
    // Return error status
    exit(1);
}
