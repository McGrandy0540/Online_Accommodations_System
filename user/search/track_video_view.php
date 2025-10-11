<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['video_id']) || !is_numeric($input['video_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid video ID']);
    exit();
}

$video_id = (int)$input['video_id'];
$user_id = $_SESSION['user_id'] ?? null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $pdo = Database::getInstance();
    
    // Check if view already exists from this user/IP in the last hour to prevent spam
    $check_stmt = $pdo->prepare("SELECT id FROM property_video_views 
                                WHERE video_id = ? 
                                AND (user_id = ? OR ip_address = ?) 
                                AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                                LIMIT 1");
    $check_stmt->execute([$video_id, $user_id, $ip_address]);
    $existing_view = $check_stmt->fetch();
    
    if (!$existing_view) {
        // Insert view record
        $stmt = $pdo->prepare("INSERT INTO property_video_views (video_id, user_id, ip_address, viewed_at) 
                              VALUES (?, ?, ?, NOW())");
        $stmt->execute([$video_id, $user_id, $ip_address]);
        
        // Update view count
        $update_stmt = $pdo->prepare("UPDATE property_owner_videos 
                                     SET views_count = views_count + 1 
                                     WHERE id = ?");
        $update_stmt->execute([$video_id]);
    }
    
    echo json_encode(['success' => true, 'message' => 'View tracked']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to track view: ' . $e->getMessage()]);
}
?>