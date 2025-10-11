<?php
session_start();
require_once '../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    header("Location: ../../auth/login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $property_id = $_POST['property_id'];
    $agreement_title = $_POST['agreement_title'];
    $selected_clauses = $_POST['selected_clauses'] ?? [];
    $custom_text = $_POST['custom_text'] ?? '';
    $action = $_POST['action'] ?? 'save';
    
    // Verify property ownership
    $property_stmt = $pdo->prepare("SELECT * FROM property WHERE id = ? AND owner_id = ?");
    $property_stmt->execute([$property_id, $owner_id]);
    $property = $property_stmt->fetch();
    
    if (!$property) {
        $_SESSION['error'] = "Property not found or access denied.";
        header("Location: generate.php");
        exit();
    }
    
    // Save to database
    $clauses_json = json_encode(array_map('intval', $selected_clauses));
    
    $insert_stmt = $pdo->prepare("
        INSERT INTO generated_tenancy_agreements 
        (owner_id, property_id, agreement_title, selected_clauses, custom_text, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'published', NOW())
    ");
    
    if ($insert_stmt->execute([$owner_id, $property_id, $agreement_title, $clauses_json, $custom_text])) {
        $agreement_id = $pdo->lastInsertId();
        
        $_SESSION['success'] = "Agreement saved successfully!";
        
        if ($action === 'save_and_send') {
            header("Location: send_agreement.php?agreement_id=" . $agreement_id);
        } else {
            header("Location: manage.php");
        }
        exit();
    } else {
        $_SESSION['error'] = "Failed to save agreement. Please try again.";
        header("Location: generate.php");
        exit();
    }
}
?>