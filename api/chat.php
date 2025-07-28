<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/database.php';
session_start();

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_conversations':
            $stmt = $db->prepare("
                SELECT c.id, c.property_id, p.property_name, 
                       u1.username as student_name, u2.username as owner_name,
                       (SELECT message FROM chat_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                       (SELECT created_at FROM chat_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM chat_conversations c
                LEFT JOIN property p ON c.property_id = p.id
                JOIN users u1 ON c.student_id = u1.id
                JOIN users u2 ON c.owner_id = u2.id
                WHERE c.student_id = ? OR c.owner_id = ?
                ORDER BY last_message_time DESC
            ");
            $stmt->execute([$userId, $userId]);
            echo json_encode(['success' => true, 'conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
            
        case 'get_messages':
            $conversationId = $_GET['conversation_id'];
            $stmt = $db->prepare("
                SELECT m.*, u.username as sender_name, u.profile_picture
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$conversationId]);
            
            // Mark messages as read
            $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ?")
               ->execute([$conversationId, $userId]);
            
            echo json_encode(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
            
        case 'send_message':
            $data = json_decode(file_get_contents('php://input'), true);
            $conversationId = $data['conversation_id'];
            $message = trim($data['message']);
            
            if (empty($message)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                break;
            }
            
            // Verify user is part of conversation
            $stmt = $db->prepare("SELECT id FROM chat_conversations WHERE id = ? AND (student_id = ? OR owner_id = ?)");
            $stmt->execute([$conversationId, $userId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not part of this conversation']);
                break;
            }
            
            $stmt = $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$conversationId, $userId, $message]);
            
            // Get the newly created message
            $messageId = $db->lastInsertId();
            $stmt = $db->prepare("SELECT m.*, u.username as sender_name FROM chat_messages m JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
            $stmt->execute([$messageId]);
            
            echo json_encode(['success' => true, 'message' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("Chat API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>