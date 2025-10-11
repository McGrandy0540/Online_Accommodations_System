<?php
// Start session
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("HTTP/1.0 403 Forbidden");
    exit();
}

// Database connection
require_once '../config/database.php';

// Get file path from request
$file_path = $_GET['file'] ?? '';
$file_name = $_GET['name'] ?? basename($file_path);

if (empty($file_path)) {
    header("HTTP/1.0 400 Bad Request");
    exit();
}

try {
    $database = new Database();
    $pdo = $database->connect();
    
    // Get document data from database
    $stmt = $pdo->prepare("SELECT document_data, mime_type FROM document_storage WHERE file_path = ?");
    $stmt->execute([$file_path]);
    $document = $stmt->fetch();
    
    if ($document) {
        // Set appropriate headers for download
        header('Content-Type: ' . $document['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . strlen($document['document_data']));
        
        // Output the document data
        echo $document['document_data'];
    } else {
        header("HTTP/1.0 404 Not Found");
        echo "Document not found in database.";
    }
} catch (Exception $e) {
    header("HTTP/1.0 500 Internal Server Error");
    echo "Error retrieving document: " . $e->getMessage();
}
?>