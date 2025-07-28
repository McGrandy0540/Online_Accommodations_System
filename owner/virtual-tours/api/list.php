<?php
session_start();
require_once __DIR__.'../../../../config/database.php';

header('Content-Type: application/json');

// Check if user is authenticated and is a property owner
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$pdo = Database::getInstance();

try {
    $stmt = $pdo->prepare("SELECT pi.id, pi.image_url, pi.media_type, pi.created_at, 
                          p.property_name, p.location,
                          (SELECT COUNT(*) FROM availability_calendar ac 
                           WHERE ac.property_id = p.id AND ac.status = 'booked' AND ac.date >= CURDATE()) as scheduled_viewings
                          FROM property_images pi
                          JOIN property p ON pi.property_id = p.id
                          JOIN property_owners po ON p.id = po.property_id
                          WHERE po.owner_id = ? AND pi.is_virtual_tour = 1
                          ORDER BY pi.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $tours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert file paths to full URLs
    foreach ($tours as &$tour) {
        $tour['image_url'] = 'http://' . $_SERVER['HTTP_HOST'] . '/' . $tour['image_url'];
    }
    
    echo json_encode(['success' => true, 'data' => $tours]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>