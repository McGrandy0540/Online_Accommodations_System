<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$input = json_decode(file_get_contents('php://input'), true);
$queueId = $input['queue_id'] ?? null;

if ($queueId) {
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE email_queue SET sent = 1, sent_at = NOW() WHERE id = ?");
        $success = $stmt->execute([$queueId]);
    } catch (Exception $e) {
        $success = false;
        error_log("Mark email sent error: " . $e->getMessage());
    }
} else {
    $success = false;
}

header('Content-Type: application/json');
echo json_encode(['success' => $success]);
?>