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

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Validate input
if (empty($_POST['property_id']) || empty($_POST['room_name']) || empty($_FILES['video'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Check if the property belongs to the owner
$stmt = $pdo->prepare("SELECT COUNT(*) 
                       FROM property_owners 
                       WHERE owner_id = ? AND property_id = ?");
$stmt->execute([$owner_id, $_POST['property_id']]);
$count = $stmt->fetchColumn();

if ($count == 0) {
    echo json_encode(['success' => false, 'message' => 'Property does not belong to you']);
    exit();
}

// Handle file upload
$uploadDir = __DIR__ . '/../../../uploads/room_videos/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$filename = uniqid() . '_' . basename($_FILES['video']['name']);
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($_FILES['video']['tmp_name'], $targetPath)) {
    // Save to database
    $stmt = $pdo->prepare("INSERT INTO room_videos (property_id, room_name, video_path) 
                           VALUES (?, ?, ?)");
    $relativePath = 'uploads/room_videos/' . $filename;
    $stmt->execute([$_POST['property_id'], $_POST['room_name'], $relativePath]);
    
    echo json_encode(['success' => true, 'message' => 'Video uploaded successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload video']);
}
?>