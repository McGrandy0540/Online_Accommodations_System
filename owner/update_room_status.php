<?php
// owner/update_room_status.php
session_start();
require_once '../config/database.php';

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die(json_encode(['success' => false, 'message' => 'Access denied']));
}

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['room_id']) && isset($data['property_id'])) {
        // Verify the room belongs to the owner
        $stmt = $pdo->prepare("SELECT pr.id 
                              FROM property_rooms pr
                              JOIN property p ON pr.property_id = p.id
                              WHERE pr.id = ? AND p.owner_id = ?");
        $stmt->execute([$data['room_id'], $owner_id]);
        $room = $stmt->fetch();
        
        if (!$room) {
            die(json_encode(['success' => false, 'message' => 'Room not found']));
        }
        
        // Update the room - mark as not newly added since this is an update
        $stmt = $pdo->prepare("UPDATE property_rooms 
                              SET is_newly_added = FALSE,
                                  room_number = ?,
                                  capacity = ?,
                                  gender = ?,
                                  status = ?
                              WHERE id = ?");
        $stmt->execute([
            $data['room_number'],
            $data['capacity'],
            $data['gender'],
            $data['status'],
            $data['room_id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Room updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    }
}
?>