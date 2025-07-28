<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;

// Check if user is logged in
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Validate input
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$conversation_id = $_POST['conversation_id'] ?? null;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit();
}// Verify user has access to this conversation
$stmt = $pdo->prepare("SELECT * FROM chat_conversations 
                      WHERE id = ? AND (student_id = ? OR owner_id = ? OR admin_id = ?)");
$stmt->execute([$conversation_id, $user_id, $user_id, $user_id]);
$conversation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conversation) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation']);
    exit();
}

// Update or insert typing indicator
try {
    $stmt = $pdo->prepare("INSERT INTO chat_typing_indicators 
                          (conversation_id, user_id, last_activity) 
                          VALUES (?, ?, NOW())
                          ON DUPLICATE KEY UPDATE last_activity = NOW()");
    $stmt->execute([$conversation_id, $user_id]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>