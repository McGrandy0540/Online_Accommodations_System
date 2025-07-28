<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;
$conversation_id = $_GET['conversation_id'] ?? null;

// Check if user is logged in
if (!$user_id || !$conversation_id) {
    echo "data: " . json_encode(['error' => 'Not authenticated or missing conversation']) . "\n\n";
    exit();
}// Verify user has access to this conversation
$stmt = $pdo->prepare("SELECT * FROM chat_conversations 
                      WHERE id = ? AND (student_id = ? OR owner_id = ? OR admin_id = ?)");
$stmt->execute([$conversation_id, $user_id, $user_id, $user_id]);
$conversation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conversation) {
    echo "data: " . json_encode(['error' => 'Invalid conversation']) . "\n\n";
    exit();
}

// Get last event ID if provided (for reconnection)
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;

// Set a reasonable time limit (in case connection stays open)
set_time_limit(30);

// Close session to allow other requests
session_write_close();

// Check for new messages every second
while (true) {
    // Check if client disconnected
    if (connection_aborted()) {
        exit();
    }

    // Check for new messages since last event ID
    $stmt = $pdo->prepare("SELECT m.*, u.username 
                          FROM chat_messages m
                          JOIN users u ON m.sender_id = u.id
                          WHERE m.conversation_id = ? 
                          AND m.id > ? 
                          AND m.sender_id != ?
                          ORDER BY m.created_at ASC");
    $stmt->execute([$conversation_id, $lastEventId, $user_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check for typing indicators
    $stmt = $pdo->prepare("SELECT * FROM chat_typing_indicators 
                          WHERE conversation_id = ? 
                          AND user_id != ?
                          AND last_activity > DATE_SUB(NOW(), INTERVAL 3 SECOND)");
    $stmt->execute([$conversation_id, $user_id]);
    $typing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($typing) {
        echo "event: typing\n";
        echo "data: " . json_encode(['user_id' => $typing['user_id']]) . "\n\n";
    } else {
        echo "event: stop_typing\n";
        echo "data: {}\n\n";
    }

    // Send new messages if any
    foreach ($messages as $message) {
        echo "event: message\n";
        echo "data: " . json_encode([
            'type' => 'message',
            'message' => [
                'id' => $message['id'],
                'message' => $message['message'],
                'created_at' => $message['created_at'],
                'sender_id' => $message['sender_id'],
                'username' => $message['username']
            ]
        ]) . "\n\n";
        
        // Update last event ID
        $lastEventId = $message['id'];
    }

    // Flush output buffer
    ob_flush();
    flush();

    // Wait 1 second before checking again
    sleep(1);
}
?>