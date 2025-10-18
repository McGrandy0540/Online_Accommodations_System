<?php
// Start session
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("HTTP/1.0 403 Forbidden");
    exit("Access denied");
}

// Database connection
require_once '../config/database.php';

// Get file path from request
$file_path = $_GET['file'] ?? '';
$student_id = $_GET['student_id'] ?? '';
$doc_type = $_GET['type'] ?? ''; // 'ghana_card' or 'passport'
$is_download = isset($_GET['download']) && $_GET['download'] == '1';

if (empty($file_path) || empty($student_id) || empty($doc_type)) {
    header("HTTP/1.0 400 Bad Request");
    exit("Missing parameters");
}

// Map doc_type to actual database column name
$column_map = [
    'ghana_card' => 'ghana_card_path',
    'passport' => 'passport_path'
];

if (!isset($column_map[$doc_type])) {
    header("HTTP/1.0 400 Bad Request");
    exit("Invalid document type");
}

$column_name = $column_map[$doc_type];

try {
    $database = new Database();
    $pdo = $database->connect();
    
    // First, get student information for better filename
    $student_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $student_stmt->execute([$student_id]);
    $student = $student_stmt->fetch();
    $student_name = $student ? $student['username'] : 'student_' . $student_id;
    
    // Verify the document belongs to the student
    $stmt = $pdo->prepare("SELECT {$column_name} FROM student_documents WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $document = $stmt->fetch();
    
    if (!$document || empty($document[$column_name])) {
        header("HTTP/1.0 404 Not Found");
        exit("Document not found in database.");
    }
    
    // Check if the requested file matches the one in database
    if ($document[$column_name] !== $file_path) {
        header("HTTP/1.0 403 Forbidden");
        exit("Document access denied");
    }
    
    // Define possible file locations
    $possible_paths = [
        "../uploads/student_documents/" . $file_path,
        "../../uploads/student_documents/" . $file_path,
        "uploads/student_documents/" . $file_path,
        $file_path
    ];
    
    $actual_path = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_file($path)) {
            $actual_path = $path;
            break;
        }
    }
    
    if (!$actual_path) {
        // File not found - return HTML error page
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>File Not Found</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; background: #f8f9fa; }
                .error-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
                .error-icon { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class='error-container'>
                <div class='error-icon'>⚠️</div>
                <h2>File Not Found</h2>
                <p>The requested document could not be found.</p>
                <p><strong>Student:</strong> " . htmlspecialchars($student_name) . " (ID: " . htmlspecialchars($student_id) . ")</p>
                <p><strong>Document Type:</strong> " . htmlspecialchars($doc_type) . "</p>
                <p><strong>File:</strong> " . htmlspecialchars($file_path) . "</p>
                <p><strong>Checked paths:</strong></p>
                <ul style='text-align: left; max-width: 500px; margin: 20px auto;'>";
        foreach ($possible_paths as $path) {
            echo "<li>" . htmlspecialchars($path) . " - " . (file_exists($path) ? "EXISTS" : "NOT FOUND") . "</li>";
        }
        echo "</ul>
            </div>
        </body>
        </html>";
        exit();
    }
    
    // Get file info
    $file_size = filesize($actual_path);
    $original_filename = basename($actual_path);
    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
    $mime_type = mime_content_type($actual_path);
    
    // Create safe filename using student ID and document type
    $safe_filename = 'student_' . $student_id . '_' . $doc_type . '.' . $file_extension;
    
    // Security check - only allow certain file types
    $allowed_mime_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf'
    ];
    
    if (!in_array($mime_type, $allowed_mime_types)) {
        echo "<!DOCTYPE html>
        <html>
        <body>
            <h2>File Type Not Allowed</h2>
            <p>File type: " . htmlspecialchars($mime_type) . " is not supported.</p>
            <p><strong>Student:</strong> " . htmlspecialchars($student_name) . " (ID: " . htmlspecialchars($student_id) . ")</p>
        </body>
        </html>";
        exit();
    }
    
    // If download is requested, serve the file directly
    if ($is_download) {
        // Force download with student ID in filename
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($actual_path);
        exit();
    }
    
    // For viewing in iframe, we need to serve the file directly with proper headers
    // This allows the browser to handle the file natively
    
    if (strpos($mime_type, 'image/') === 0) {
        // Serve image directly - browser can display it in iframe
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $file_size);
        header('Cache-Control: public, max-age=3600');
        readfile($actual_path);
    } elseif ($mime_type === 'application/pdf') {
        // Serve PDF directly - modern browsers can display PDFs in iframe
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: public, max-age=3600');
        header('Content-Disposition: inline; filename="' . $safe_filename . '"');
        readfile($actual_path);
    } else {
        // For unsupported types, show HTML page with download option
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Document Viewer</title>
            <style>
                body { 
                    margin: 40px; 
                    background: #f8f9fa; 
                    font-family: Arial, sans-serif;
                }
                .container { 
                    background: white; 
                    padding: 30px; 
                    border-radius: 8px; 
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                    text-align: center;
                }
                .student-info {
                    color: #6c757d;
                    margin-bottom: 15px;
                    font-size: 14px;
                }
                .download-btn { 
                    background: #007bff; 
                    color: white; 
                    padding: 12px 24px; 
                    text-decoration: none; 
                    border-radius: 4px; 
                    display: inline-block;
                    margin: 15px 0;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Document Preview Not Available</h2>
                <div class='student-info'>
                    <strong>Student:</strong> " . htmlspecialchars($student_name) . " (ID: " . htmlspecialchars($student_id) . ")
                </div>
                <p>This file type cannot be previewed in the browser.</p>
                <p><strong>File:</strong> " . htmlspecialchars($safe_filename) . "</p>
                <p><strong>Type:</strong> " . htmlspecialchars($mime_type) . "</p>
                <a href='?file=" . urlencode($file_path) . "&student_id=" . $student_id . "&type=" . $doc_type . "&download=1' class='download-btn'>
                    Download File
                </a>
            </div>
        </body>
        </html>";
    }
    
} catch (Exception $e) {
    echo "<!DOCTYPE html>
    <html>
    <body>
        <h2>Error</h2>
        <p>Error retrieving document: " . htmlspecialchars($e->getMessage()) . "</p>
        <p><strong>Student ID:</strong> " . htmlspecialchars($student_id) . "</p>
        <p><strong>Document Type:</strong> " . htmlspecialchars($doc_type) . "</p>
    </body>
    </html>";
}
?>