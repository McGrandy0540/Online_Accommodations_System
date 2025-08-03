<?php
session_start();
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is property owner
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only property owners can record cash payments.']);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Please use POST.']);
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

try {
    // Get and validate form data
    $required_fields = ['property_id', 'student_name', 'student_email', 'student_phone', 
                       'room_id', 'amount', 'start_date', 'duration_months'];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $property_id = (int)$_POST['property_id'];
    $student_name = trim($_POST['student_name']);
    $student_email = trim($_POST['student_email']);
    $student_phone = trim($_POST['student_phone']);
    $student_id = trim($_POST['student_id'] ?? '');
    $room_id = (int)$_POST['room_id'];
    $amount = (float)$_POST['amount'];
    $start_date = $_POST['start_date'];
    $duration_months = (int)$_POST['duration_months'];
    $tenant_location = trim($_POST['tenant_location'] ?? '');

    // Validate email format
    if (!filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Validate amount
    if ($amount <= 0) {
        throw new Exception('Payment amount must be greater than zero');
    }

    // Validate duration
    $valid_durations = [1, 3, 6, 9, 12];
    if (!in_array($duration_months, $valid_durations)) {
        throw new Exception('Invalid duration selected. Valid options: 1, 3, 6, 9, 12 months');
    }

    // Validate start date
    if (strtotime($start_date) < strtotime('today')) {
        throw new Exception('Start date cannot be in the past');
    }

    $pdo->beginTransaction();

    // Verify property and room ownership
    $stmt = $pdo->prepare("
        SELECT p.id AS property_id, p.property_name, pr.id AS room_id, 
               pr.room_number, pr.capacity, pr.gender, pr.status as room_status,
               pr.current_occupancy
        FROM property p
        JOIN property_rooms pr ON p.id = pr.property_id
        WHERE p.id = :property_id 
          AND p.owner_id = :owner_id
          AND pr.id = :room_id
          AND p.deleted = 0
        FOR UPDATE
    ");
    
    $stmt->execute([
        ':property_id' => $property_id,
        ':owner_id' => $owner_id,
        ':room_id' => $room_id
    ]);
    
    $room_details = $stmt->fetch();

    if (!$room_details) {
        throw new Exception('Room not found or you do not have permission to manage this room');
    }

    // Check room status
    if ($room_details['room_status'] !== 'available') {
        throw new Exception('Room is not available for booking. Current status: ' . $room_details['room_status']);
    }

    // Check room occupancy
    if ($room_details['current_occupancy'] >= $room_details['capacity']) {
        throw new Exception('Room is fully occupied. Capacity: ' . $room_details['capacity']);
    }

    // Find or create student
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => $student_email]);
    $student_user_id = $stmt->fetchColumn();

    if (!$student_user_id) {
        $password_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, pwd, phone_number, student_id, status, created_at, location, sex) 
            VALUES (:name, :email, :pwd, :phone, :student_id, 'student', NOW(), :location, 'other')
        ");
        
        $stmt->execute([
            ':name' => $student_name,
            ':email' => $student_email,
            ':pwd' => $password_hash,
            ':phone' => $student_phone,
            ':student_id' => $student_id,
            ':location' => $tenant_location
        ]);
        
        $student_user_id = $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET 
                username = :name, 
                phone_number = :phone, 
                student_id = :student_id,
                location = :location
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':name' => $student_name,
            ':phone' => $student_phone,
            ':student_id' => $student_id,
            ':location' => $tenant_location,
            ':id' => $student_user_id
        ]);
    }

    // Calculate end date
    $end_date = date('Y-m-d', strtotime("+$duration_months months", strtotime($start_date)));

    // Create booking
    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            user_id, property_id, room_id, 
            start_date, end_date, duration_months, 
            status, booking_date, payment_method
        ) VALUES (
            :user_id, :property_id, :room_id,
            :start_date, :end_date, :duration_months, 
            'paid', NOW(), 'cash'
        )
    ");
    
    $stmt->execute([
        ':user_id' => $student_user_id,
        ':property_id' => $property_id,
        ':room_id' => $room_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':duration_months' => $duration_months
    ]);
    
    $booking_id = $pdo->lastInsertId();

    // Update room occupancy
    $new_occupancy = $room_details['current_occupancy'] + 1;
    $new_status = ($new_occupancy >= $room_details['capacity']) ? 'occupied' : 'available';
    
    $stmt = $pdo->prepare("
        UPDATE property_rooms 
        SET 
            current_occupancy = :occupancy,
            status = :status
        WHERE id = :room_id
    ");
    
    $stmt->execute([
        ':occupancy' => $new_occupancy,
        ':status' => $new_status,
        ':room_id' => $room_id
    ]);

    // Record payment
    $transaction_id = 'CASH_' . time() . '_' . $booking_id;
    
    $stmt = $pdo->prepare("
        INSERT INTO payments (
            booking_id, amount, status, payment_method, 
            transaction_id, created_at
        ) VALUES (
            :booking_id, :amount, 'completed', 'cash',
            :transaction_id, NOW()
        )
    ");
    
    $stmt->execute([
        ':booking_id' => $booking_id,
        ':amount' => $amount,
        ':transaction_id' => $transaction_id
    ]);

    // Create notifications
    $notification_message = "Cash payment recorded for {$student_name} "
        . "({$room_details['property_name']} - Room {$room_details['room_number']}) "
        . "Amount: GHS " . number_format($amount, 2);
    
    // For student
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, type, created_at) 
        VALUES (:user_id, :message, 'payment_received', NOW())
    ");
    $stmt->execute([
        ':user_id' => $student_user_id,
        ':message' => "Your booking is confirmed. Payment received: GHS " . number_format($amount, 2)
    ]);

    // For owner
    $stmt->execute([
        ':user_id' => $owner_id,
        ':message' => $notification_message
    ]);

    $pdo->commit();

    // Return success response with room details
    echo json_encode([
        'success' => true,
        'message' => 'Cash payment recorded successfully',
        'room_details' => [
            'room_number' => $room_details['room_number'],
            'capacity' => $room_details['capacity'],
            'gender' => $room_details['gender'],
            'property_name' => $room_details['property_name']
        ],
        'booking' => [
            'id' => $booking_id,
            'start_date' => $start_date,
            'end_date' => $end_date
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Cash payment error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => $e->getFile() . ':' . $e->getLine()
    ]);
}
?>