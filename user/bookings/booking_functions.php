<?php
require_once __DIR__ . '../../../config/database.php';

function cancelBooking($booking_id, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, pr.id as room_id, pr.current_occupancy, pr.capacity 
            FROM bookings b 
            LEFT JOIN property_rooms pr ON b.room_id = pr.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        // Update booking status to cancelled
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        
        // If booking was confirmed/paid, decrease room occupancy
        if ($booking['room_id'] && 
            in_array($booking['status'], ['confirmed', 'paid', 'cash_approved'])) {
            
            $new_occupancy = max(0, $booking['current_occupancy'] - 1);
            $available_spots = $booking['capacity'] - $new_occupancy;
            
            $stmt = $pdo->prepare("
                UPDATE property_rooms 
                SET current_occupancy = ?, 
                    available_spots = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_occupancy, $available_spots, $booking['room_id']]);
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Booking cancelled successfully'];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error cancelling booking: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to cancel booking: ' . $e->getMessage()];
    }
}

function confirmBooking($booking_id, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, pr.id as room_id, pr.current_occupancy, pr.capacity 
            FROM bookings b 
            LEFT JOIN property_rooms pr ON b.room_id = pr.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        // Check if room has available capacity
        if ($booking['room_id'] && $booking['current_occupancy'] >= $booking['capacity']) {
            throw new Exception("Room is already at full capacity");
        }
        
        // Update booking status to confirmed
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'confirmed', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        
        // Increase room occupancy
        if ($booking['room_id']) {
            $new_occupancy = $booking['current_occupancy'] + 1;
            $available_spots = $booking['capacity'] - $new_occupancy;
            
            $stmt = $pdo->prepare("
                UPDATE property_rooms 
                SET current_occupancy = ?, 
                    available_spots = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_occupancy, $available_spots, $booking['room_id']]);
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Booking confirmed successfully'];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error confirming booking: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to confirm booking: ' . $e->getMessage()];
    }
}
?>