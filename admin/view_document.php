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
$student_id = $_GET['student_id'] ?? '';
$doc_type = $_GET['type'] ?? ''; // 'ghana_card' or 'passport'

if (empty($file_path) || empty($student_id) || empty($doc_type)) {
    header("HTTP/1.0 400 Bad Request");
    exit("Missing parameters");
}

try {
    $database = new Database();
    $pdo = $database->connect();
    
    // Verify the document belongs to the student
    $stmt = $pdo->prepare("SELECT $doc_type FROM student_documents WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $document = $stmt->fetch();
    
    if ($document && !empty($document[$doc_type])) {
        // Check if the requested file matches the one in database
        if ($document[$doc_type] !== $file_path) {
            header("HTTP/1.0 403 Forbidden");
            exit("Document access denied");
        }
        
        $full_path = "../uploads/student_documents/" . $file_path;
        
        // Check if file exists
        if (file_exists($full_path)) {
            // Determine MIME type
            $mime_type = mime_content_type($full_path);
            
            // Set appropriate headers
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . filesize($full_path));
            
            // Output the file
            readfile($full_path);
        } else {
            // Try to find the file with a different path structure
            if (file_exists($file_path)) {
                $mime_type = mime_content_type($file_path);
                header('Content-Type: ' . $mime_type);
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
            } else {
                header("HTTP/1.0 404 Not Found");
                echo "File not found: " . htmlspecialchars($file_path);
            }
        }
    } else {
        header("HTTP/1.0 404 Not Found");
        echo "Document not found in database.";
    }
} catch (Exception $e) {
    header("HTTP/1.0 500 Internal Server Error");
    echo "Error retrieving document: " . $e->getMessage();
}
?>