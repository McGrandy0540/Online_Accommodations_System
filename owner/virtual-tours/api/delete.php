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

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get tour ID from URL
$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tour_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid tour ID']);
    exit();
}

$pdo = Database::getInstance();

try {
    // Verify the tour belongs to the owner
    $stmt = $pdo->prepare("SELECT pi.id 
                          FROM property_images pi
                          JOIN property p ON pi.property_id = p.id
                          JOIN property_owners po ON p.id = po.property_id
                          WHERE po.owner_id = ? AND pi.id = ? AND pi.is_virtual_tour = 1");
    $stmt->execute([$_SESSION['user_id'], $tour_id]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tour not found or access denied']);
        exit();
    }

    // Get the image path before deleting
    $stmt = $pdo->prepare("SELECT image_url FROM property_images WHERE id = ?");
    $stmt->execute([$tour_id]);
    $image = $stmt->fetch();
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM property_images WHERE id = ?");
    $stmt->execute([$tour_id]);
    
    // Delete the file if it exists
    if ($image && file_exists('../../' . $image['image_url'])) {
        unlink('../../' . $image['image_url']);
    }
    
    // Log activity
    $activity_stmt = $pdo->prepare("INSERT INTO activity_logs 
                                  (user_id, action, entity_type, entity_id, created_at)
                                  VALUES (?, ?, ?, ?, NOW())");
    $activity_stmt->execute([
        $_SESSION['user_id'],
        'delete_virtual_tour',
        'property_image',
        $tour_id
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Tour deleted successfully']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>