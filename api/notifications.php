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
        case 'get_unread_count':
            $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'count' => $stmt->fetchColumn()]);
            break;
            
        case 'get_notifications':
            $limit = min(20, intval($_GET['limit'] ?? 10));
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT n.*, p.property_name, p.id as property_id
                FROM notifications n
                LEFT JOIN property p ON n.property_id = p.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark as read if requested
            if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'true') {
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                   ->execute([$userId]);
            }
            
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            break;
            
        case 'mark_read':
            $notificationId = $_GET['id'] ?? 0;
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notificationId, $userId]);
            
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
            break;
            
        case 'get_preferences':
            $stmt = $db->prepare("SELECT email_notifications, sms_notifications FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'preferences' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;
            
        case 'update_preferences':
            $data = json_decode(file_get_contents('php://input'), true);
            $email = isset($data['email']) ? (int)$data['email'] : 0;
            $sms = isset($data['sms']) ? (int)$data['sms'] : 0;
            
            $stmt = $db->prepare("UPDATE users SET email_notifications = ?, sms_notifications = ? WHERE id = ?");
            $stmt->execute([$email, $sms, $userId]);
            
            // Update session if needed
            $_SESSION['notifications'] = $email;
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>