<?php
session_start();
require_once __DIR__. '../../../../config/database.php';

header('Content-Type: application/json');

// Check if user is authenticated and is a property owner
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Validate input
$required = ['tour_id', 'viewing_date', 'duration'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$tour_id = (int)$_POST['tour_id'];
$viewing_date = $_POST['viewing_date'];
$duration = (int)$_POST['duration'];
$notes = $_POST['notes'] ?? '';

$pdo = Database::getInstance();

try {
    // Verify the tour belongs to the owner
    $stmt = $pdo->prepare("SELECT pi.property_id 
                          FROM property_images pi
                          JOIN property p ON pi.property_id = p.id
                          JOIN property_owners po ON p.id = po.property_id
                          WHERE po.owner_id = ? AND pi.id = ? AND pi.is_virtual_tour = 1");
    $stmt->execute([$_SESSION['user_id'], $tour_id]);
    
    $result = $stmt->fetch();
    if (!$result) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tour not found or access denied']);
        exit();
    }
    
    $property_id = $result['property_id'];
    
    // Check if the time slot is available
    $stmt = $pdo->prepare("SELECT 1 FROM availability_calendar 
                          WHERE property_id = ? AND date = ? AND status = 'booked'");
    $stmt->execute([$property_id, $viewing_date]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This time slot is already booked']);
        exit();
    }
    
    // Schedule the viewing
    $stmt = $pdo->prepare("INSERT INTO availability_calendar 
                          (property_id, date, status, notes, created_at)
                          VALUES (?, ?, 'booked', ?, NOW())");
    $stmt->execute([$property_id, $viewing_date, $notes]);
    
    // Create a notification for the owner
    $stmt = $pdo->prepare("INSERT INTO notifications 
                          (user_id, property_id, message, type, created_at)
                          VALUES (?, ?, ?, 'booking_update', NOW())");
    $message = "New virtual tour viewing scheduled for " . date('M j, Y g:i a', strtotime($viewing_date));
    $stmt->execute([$_SESSION['user_id'], $property_id, $message]);
    
    // Log activity
    $activity_stmt = $pdo->prepare("INSERT INTO activity_logs 
                                  (user_id, action, entity_type, entity_id, created_at)
                                  VALUES (?, ?, ?, ?, NOW())");
    $activity_stmt->execute([
        $_SESSION['user_id'],
        'schedule_virtual_tour',
        'property',
        $property_id
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Viewing scheduled successfully']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>