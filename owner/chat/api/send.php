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
$message = trim($_POST['message'] ?? '');

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
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

// Insert message
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO chat_messages 
                          (conversation_id, sender_id, message) 
                          VALUES (?, ?, ?)");
    $stmt->execute([$conversation_id, $user_id, $message]);
    
    $message_id = $pdo->lastInsertId();
    
    // Update conversation's updated_at
    $pdo->prepare("UPDATE chat_conversations 
                  SET updated_at = CURRENT_TIMESTAMP 
                  WHERE id = ?")->execute([$conversation_id]);// Create notification for the other user
if ($conversation['conversation_type'] === 'owner_admin') {
    // For owner-admin conversations
    $other_user_id = ($user_id == $conversation['owner_id']) ? 
                    $conversation['admin_id'] : $conversation['owner_id'];
} else {
    // For student-owner conversations
    $other_user_id = ($user_id == $conversation['student_id']) ? 
                    $conversation['owner_id'] : $conversation['student_id'];
}
    
    $stmt = $pdo->prepare("INSERT INTO notifications 
                          (user_id, property_id, message, type, notification_type) 
                          VALUES (?, ?, ?, 'booking_update', 'in_app')");
    $notification_message = "New message from " . $_SESSION['username'];
    $stmt->execute([$other_user_id, $conversation['property_id'], $notification_message]);
    
    $pdo->commit();
    
    // Get the created message
    $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE id = ?");
    $stmt->execute([$message_id]);
    $created_message = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => [
            'id' => $created_message['id'],
            'message' => $created_message['message'],
            'created_at' => $created_message['created_at'],
            'is_read' => $created_message['is_read'],
            'sender_id' => $created_message['sender_id']
        ]
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>