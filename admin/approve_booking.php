<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once '../config/database.php';

// Get the PDO instance from the Database class
$database = new Database();
$pdo = $database->connect();

// Get user data
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Admin';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get booking ID from POST
$booking_id = $_POST['booking_id'] ?? 0;

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../' . ltrim($path, '/');
}

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "Failed to load user data. Please try again later.";
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $error = $e->getMessage();
}

$profile_pic_path = getProfilePicturePath($user['profile_picture'] ?? '');

// Handle booking approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($booking_id)) {
    try {
        // Verify booking exists and is pending
        $stmt = $pdo->prepare("
            SELECT b.*, p.property_name, u_student.username as student_name, 
                   u_student.email as student_email, p.owner_id,
                   pr.room_number, pr.id as room_id, pr.capacity, pr.current_occupancy
            FROM bookings b
            JOIN property p ON b.property_id = p.id
            JOIN users u_student ON b.user_id = u_student.id
            LEFT JOIN property_rooms pr ON b.room_id = pr.id
            WHERE b.id = ? AND b.status = 'pending'
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            $error = "Booking not found or already processed.";
        } else {
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Update booking status to confirmed
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $result = $stmt->execute([$booking_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Update room occupancy if room is specified
                    if (!empty($booking['room_id'])) {
                        $new_occupancy = $booking['current_occupancy'] + 1;
                        $stmt = $pdo->prepare("
                            UPDATE property_rooms 
                            SET current_occupancy = ?, 
                                available_spots = capacity - ?,
                                status = CASE WHEN ? >= capacity THEN 'occupied' ELSE 'available' END
                            WHERE id = ?
                        ");
                        $stmt->execute([$new_occupancy, $new_occupancy, $new_occupancy, $booking['room_id']]);
                    }
                    
                    // Update property status
                    $stmt = $pdo->prepare("UPDATE property SET status = 'booked' WHERE id = ?");
                    $stmt->execute([$booking['property_id']]);
                    
                    // Create notification for student
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, admin_id, booking_id, message, type, notification_type, created_at) 
                        VALUES (?, ?, ?, ?, 'booking_approved', 'in_app', CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $booking['user_id'],
                        $user_id,
                        $booking_id,
                        "Your booking for {$booking['property_name']} has been approved"
                    ]);
                    
                    // Create notification for property owner
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, admin_id, booking_id, message, type, notification_type, created_at) 
                        VALUES (?, ?, ?, ?, 'booking_confirmed', 'in_app', CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $booking['owner_id'],
                        $user_id,
                        $booking_id,
                        "New booking confirmed for {$booking['property_name']} by {$booking['student_name']}"
                    ]);
                    
                    // Send email notification to student
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO email_queue (user_id, email_data, created_at) 
                            VALUES (?, ?, CURRENT_TIMESTAMP)
                        ");
                        $email_data = json_encode([
                            'to' => $booking['student_email'],
                            'subject' => 'Booking Approved - ' . $booking['property_name'],
                            'message' => "Dear {$booking['student_name']}, your booking for {$booking['property_name']} has been approved by the admin. You can now proceed with payment.",
                            'type' => 'booking_approved'
                        ]);
                        $stmt->execute([$booking['user_id'], $email_data]);
                    } catch (Exception $e) {
                        error_log("Email queue error: " . $e->getMessage());
                        // Continue even if email fails
                    }
                    
                    // Log admin action
                    $stmt = $pdo->prepare("
                        INSERT INTO admin_actions (admin_id, action_type, target_id, target_type, details, created_at) 
                        VALUES (?, ?, ?, 'booking', ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $user_id,
                        'approve',
                        $booking_id,
                        "Admin {$username} approved booking ID {$booking_id} for {$booking['property_name']}"
                    ]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    $success = "Booking #{$booking_id} approved successfully! Student and property owner have been notified.";
                    
                } else {
                    $error = "Failed to update booking status.";
                    $pdo->rollBack();
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        
    } catch (PDOException $e) {
        error_log("Approval Error: " . $e->getMessage());
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        error_log("Approval Error: " . $e->getMessage());
        $error = "Failed to process approval: " . $e->getMessage();
    }
    
    // Redirect back to dashboard with success/error message
    $redirect_url = "dashboard.php";
    if (isset($success)) {
        $redirect_url .= "?success=" . urlencode($success);
    } elseif (isset($error)) {
        $redirect_url .= "?error=" . urlencode($error);
    }
    
    header("Location: " . $redirect_url);
    exit();
} else {
    // If not POST request or no booking ID, redirect to dashboard
    header("Location: dashboard.php");
    exit();
}
?>