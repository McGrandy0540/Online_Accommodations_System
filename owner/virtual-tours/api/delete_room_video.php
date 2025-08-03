<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is authenticated and is a property owner
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Check if the request is a DELETE request
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get video ID from query string
$video_id = $_GET['id'] ?? null;
if (!$video_id) {
    echo json_encode(['success' => false, 'message' => 'Missing video ID']);
    exit();
}

// Check if the video belongs to the owner
$stmt = $pdo->prepare("SELECT rv.video_path 
                       FROM room_videos rv
                       JOIN property_owners po ON rv.property_id = po.property_id
                       WHERE rv.id = ? AND po.owner_id = ?");
$stmt->execute([$video_id, $owner_id]);
$video = $stmt->fetch();

if (!$video) {
    echo json_encode(['success' => false, 'message' => 'Video not found or access denied']);
    exit();
}

// Delete file from server
$filePath = __DIR__ . '/../../../' . $video['video_path'];
if (file_exists($filePath)) {
    unlink($filePath);
}

// Delete from database
$stmt = $pdo->prepare("DELETE FROM room_videos WHERE id = ?");
$stmt->execute([$video_id]);

echo json_encode(['success' => true, 'message' => 'Video deleted successfully']);
?>