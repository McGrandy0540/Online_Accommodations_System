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
// Get tour ID and type from URL
$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if ($tour_id <= 0 || !in_array($type, ['tour', 'room'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid tour ID or type']);
    exit();
}

$pdo = Database::getInstance();

try {
    if ($type === 'tour') {
        // Verify tour belongs to owner
        $stmt = $pdo->prepare("SELECT pi.id, pi.image_url
                              FROM property_images pi
                              JOIN property p ON pi.property_id = p.id
                              JOIN property_owners po ON p.id = po.property_id
                              WHERE po.owner_id = ? AND pi.id = ? AND pi.is_virtual_tour = 1");
        $stmt->execute([$_SESSION['user_id'], $tour_id]);
        $tour = $stmt->fetch();

        if (!$tour) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Tour not found or access denied']);
            exit();
        }

        // Delete tour
        $stmt = $pdo->prepare("DELETE FROM property_images WHERE id = ?");
        $stmt->execute([$tour_id]);
        
        // Delete the file
        if ($tour['image_url'] && file_exists('../../' . $tour['image_url'])) {
            unlink('../../' . $tour['image_url']);
        }
        
        // Log activity
        $action = 'delete_virtual_tour';
        $entity_type = 'property_image';
    } elseif ($type === 'room') {
        // Verify room video belongs to owner
        $stmt = $pdo->prepare("SELECT rv.id, rv.video_path
                              FROM room_videos rv
                              JOIN property p ON rv.property_id = p.id
                              JOIN property_owners po ON p.id = po.property_id
                              WHERE po.owner_id = ? AND rv.id = ?");
        $stmt->execute([$_SESSION['user_id'], $tour_id]);
        $video = $stmt->fetch();

        if (!$video) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Room video not found or access denied']);
            exit();
        }

        // Delete room video
        $stmt = $pdo->prepare("DELETE FROM room_videos WHERE id = ?");
        $stmt->execute([$tour_id]);
        
        // Delete the file
        if ($video['video_path'] && file_exists('../../' . $video['video_path'])) {
            unlink('../../' . $video['video_path']);
        }
        
        // Log activity
        $action = 'delete_room_video';
        $entity_type = 'room_video';
    }

    // Log activity
    $activity_stmt = $pdo->prepare("INSERT INTO activity_logs
                                  (user_id, action, entity_type, entity_id, created_at)
                                  VALUES (?, ?, ?, ?, NOW())");
    $activity_stmt->execute([
        $_SESSION['user_id'],
        $action,
        $entity_type,
        $tour_id
    ]);
    
    echo json_encode(['success' => true, 'message' => ucfirst($type) . ' deleted successfully']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>