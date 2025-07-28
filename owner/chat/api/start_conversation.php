<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['status'] ?? null;

// Check if user is logged in
if (!$user_id) {
    header("Location: /login.php");
    exit();
}

// Get parameters
$partner_id = $_GET['user_id'] ?? null;
$property_id = $_GET['property_id'] ?? null;

if (!$partner_id) {
    header("Location: /chat/index.php");
    exit();
}

// Verify the partner exists and is the correct type
$stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$partner) {
    header("Location: /chat/index.php");
    exit();
}

// Verify property exists if specified
if ($property_id) {
    $stmt = $pdo->prepare("SELECT id, owner_id FROM property WHERE id = ?");
    $stmt->execute([$property_id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$property) {
        header("Location: /chat/index.php");
        exit();
    }
    
    // Verify the partner is the owner of the property
    if ($property['owner_id'] != $partner_id && $user_id != $property['owner_id']) {
        header("Location: /chat/index.php");
        exit();
    }
}// Check if conversation already exists
if ($user_type === 'admin' || $partner['status'] === 'admin') {
    // Check for owner-admin conversation
    $stmt = $pdo->prepare("SELECT id FROM chat_conversations 
                          WHERE ((owner_id = ? AND admin_id = ?) OR (owner_id = ? AND admin_id = ?))
                          AND conversation_type = 'owner_admin'");
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id]);
} else {
    // Check for student-owner conversation
    $stmt = $pdo->prepare("SELECT id FROM chat_conversations 
                          WHERE ((student_id = ? AND owner_id = ?) OR (student_id = ? AND owner_id = ?))
                          AND (property_id = ? OR ? IS NULL)
                          AND conversation_type = 'student_owner'");
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $property_id, $property_id]);
}
$existing_conversation = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing_conversation) {
    header("Location: /chat/index.php?conversation_id=" . $existing_conversation['id']);
    exit();
}// Determine conversation type and user roles
$conversation_type = 'student_owner';
$admin_id = null;

if ($user_type === 'admin' || $partner['status'] === 'admin') {
    // This is an owner-admin conversation
    $conversation_type = 'owner_admin';
    
    if ($user_type === 'admin') {
        $admin_id = $user_id;
        $owner_id = $partner_id;
        $student_id = null;
    } else {
        $admin_id = $partner_id;
        $owner_id = $user_id;
        $student_id = null;
    }
} else {
    // This is a student-owner conversation
    if ($user_type === 'student') {
        $student_id = $user_id;
        $owner_id = $partner_id;
    } else {
        $student_id = $partner_id;
        $owner_id = $user_id;
    }
}

// Create new conversation
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO chat_conversations 
                          (student_id, owner_id, admin_id, property_id, conversation_type) 
                          VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$student_id, $owner_id, $admin_id, $property_id, $conversation_type]);
    $conversation_id = $pdo->lastInsertId();
    
    // Create welcome message
    $welcome_message = "Hello! This is the start of your conversation about ";
    if ($property_id) {
        $welcome_message .= "the property.";
    } else {
        $welcome_message .= "your accommodation.";
    }
    
    $stmt = $pdo->prepare("INSERT INTO chat_messages 
                          (conversation_id, sender_id, message) 
                          VALUES (?, ?, ?)");
    $stmt->execute([$conversation_id, $user_id, $welcome_message]);
    
    $pdo->commit();
    
    header("Location: /chat/index.php?conversation_id=" . $conversation_id);
    exit();
} catch (PDOException $e) {
    $pdo->rollBack();
    header("Location: /chat/index.php?error=Failed to start conversation");
    exit();
}
?>