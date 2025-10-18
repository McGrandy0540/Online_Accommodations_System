<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin (assuming role is stored in session)
if ($_SESSION['status'] !== 'admin') {
    // If not admin, show access denied
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once '../config/database.php';

// Get the PDO instance from the Database class
$database = new Database();
$pdo = $database->connect();

// Get all student documents with student information
$stmt = $pdo->prepare("
    SELECT 
        sd.*,
        u.username as student_name,
        u.email as student_email,
        u.phone_number as student_phone,
        u.student_id as student_id_number,
        u.created_at as student_joined,
        u.sex as student_gender,
        u.location as student_location,
        COUNT(b.id) as booking_count
    FROM student_documents sd
    JOIN users u ON sd.student_id = u.id
    LEFT JOIN bookings b ON u.id = b.user_id
    WHERE u.status = 'student' AND u.deleted = 0
    GROUP BY sd.id
    ORDER BY sd.uploaded_at DESC
");
$stmt->execute();
$student_documents = $stmt->fetchAll();

// Handle document verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? null;
    $action = $_POST['action'] ?? '';
    $verification_notes = $_POST['verification_notes'] ?? '';
    
    if ($student_id && in_array($action, ['verify', 'reject'])) {
        try {
            // Update student verification status in users table
            $update_stmt = $pdo->prepare("
                UPDATE users 
                SET phone_verified = :verified,
                    email_verified = :verified,
                    updated_at = NOW()
                WHERE id = :id AND status = 'student'
            ");
            
            $verified = $action === 'verify' ? 1 : 0;
            $update_stmt->execute([
                ':verified' => $verified,
                ':id' => $student_id
            ]);
            
            // Check if student_verifications table exists, if not create it
            $table_check = $pdo->query("SHOW TABLES LIKE 'student_verifications'")->fetch();
            
            if (!$table_check) {
                // Create the table if it doesn't exist
                $pdo->exec("
                    CREATE TABLE `student_verifications` (
                        `id` int NOT NULL AUTO_INCREMENT,
                        `student_id` int NOT NULL,
                        `verified_by` int NOT NULL,
                        `status` enum('verified','rejected') NOT NULL DEFAULT 'verified',
                        `notes` text,
                        `verified_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `student_id` (`student_id`),
                        KEY `verified_by` (`verified_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
                ");
            }
            
            // Create verification record or update existing
            $verification_stmt = $pdo->prepare("
                INSERT INTO student_verifications (student_id, verified_by, status, notes, verified_at)
                VALUES (:student_id, :verified_by, :status, :notes, NOW())
                ON DUPLICATE KEY UPDATE 
                    verified_by = :verified_by,
                    status = :status,
                    notes = :notes,
                    verified_at = NOW()
            ");
            
            $status = $action === 'verify' ? 'verified' : 'rejected';
            $verification_stmt->execute([
                ':student_id' => $student_id,
                ':verified_by' => $_SESSION['user_id'],
                ':status' => $status,
                ':notes' => $verification_notes
            ]);
            
            // Create notification for student
            $notify_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, created_at)
                VALUES (:user_id, :message, 'document_verification', NOW())
            ");
            
            $message = $action === 'verify' 
                ? 'Your student documents have been verified successfully.' 
                : 'Your student documents were rejected. Please review and re-upload.';
                
            $notify_stmt->execute([
                ':user_id' => $student_id,
                ':message' => $message . (!empty($verification_notes) ? " Notes: " . $verification_notes : "")
            ]);
            
            // Refresh the page to show updated status
            header("Location: ".$_SERVER['PHP_SELF']."?success=1");
            exit();
            
        } catch (Exception $e) {
            $error_message = "Error processing request: " . $e->getMessage();
        }
    }
}

// Get verification status for all students
// Check if student_verifications table exists
$table_check = $pdo->query("SHOW TABLES LIKE 'student_verifications'")->fetch();

if ($table_check) {
    $verification_stmt = $pdo->prepare("
        SELECT sv.*, u.username as verified_by_name
        FROM student_verifications sv
        JOIN users u ON sv.verified_by = u.id
        ORDER BY sv.verified_at DESC
        LIMIT 50
    ");
    $verification_stmt->execute();
    $verification_history = $verification_stmt->fetchAll();
} else {
    $verification_history = [];
}

// Create a map of student_id to verification status for easy lookup
$verification_map = [];
foreach ($verification_history as $verification) {
    $verification_map[$verification['student_id']] = $verification;
}

// Function to check if file exists and return appropriate path or data
function getDocumentSource($file_path) {
    if (empty($file_path)) {
        return array('type' => 'not_found', 'path' => '');
    }
    
    // Check if file exists in uploads folder
    $possible_paths = [
        "../../uploads/student_documents/" . $file_path,
        "../uploads/student_documents/" . $file_path,
        "uploads/student_documents/" . $file_path,
        $file_path
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_file($path)) {
            return array('type' => 'file', 'path' => $path);
        }
    }
    
    // File doesn't exist
    return array('type' => 'not_found', 'path' => $file_path);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Student Document Verification</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7f9;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: var(--primary-color);
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .back-button {
            color: white;
            text-decoration: none;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            transition: all var(--transition-speed);
            backdrop-filter: blur(10px);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .page-title {
            font-size: 32px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all var(--transition-speed);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-color);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 15px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stat-icon.unverified {
            background: var(--warning-color);
        }

        .stat-icon.verified {
            background: var(--success-color);
        }

        .stat-icon.rejected {
            background: var(--danger-color);
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 5px 0;
            color: var(--secondary-color);
        }

        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            transition: all var(--transition-speed);
            background: #f8f9fa;
        }

        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 22px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .student-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: all var(--transition-speed);
            border-left: 4px solid var(--warning-color);
        }

        .student-card.verified {
            border-left-color: var(--success-color);
        }

        .student-card.rejected {
            border-left-color: var(--danger-color);
        }

        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .student-name {
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 5px;
            color: var(--secondary-color);
        }

        .student-info {
            color: #6c757d;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .student-info div {
            margin-bottom: 5px;
        }

        .student-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 14px;
            color: #6c757d;
        }

        .student-files {
            margin-bottom: 20px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            background: #f8f9fa;
            border-radius: var(--border-radius);
            margin-bottom: 10px;
        }

        .file-name {
            flex: 1;
            font-size: 14px;
        }

        .file-actions {
            display: flex;
            gap: 5px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .verification-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-unverified {
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
        }

        .status-verified {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }

        .status-rejected {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .history-table th, .history-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .history-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--secondary-color);
            position: sticky;
            top: 0;
        }

        .history-table tr:hover {
            background-color: #f8f9fa;
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        /* Enhanced Document Viewer Modal Styles */
        .document-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(5px);
        }
        
        .document-modal-content {
            position: relative;
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            width: 95%;
            max-width: 1200px;
            height: 95vh;
            border-radius: 12px;
            box-shadow: 0 10px 50px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--secondary-color);
            margin: 0;
        }
        
        .close-document-modal {
            font-size: 28px;
            font-weight: bold;
            color: #6c757d;
            cursor: pointer;
            background: none;
            border: none;
            padding: 5px;
            transition: color 0.3s;
        }
        
        .close-document-modal:hover {
            color: var(--danger-color);
        }
        
        .document-viewer-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f8f9fa;
            position: relative;
            overflow: hidden;
        }
        
        .document-viewer {
            width: 100%;
            height: 100%;
            border: none;
            background: white;
        }
        
        .document-error {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .document-error i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        .document-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }

        /* Loading spinner */
        .document-loading {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--primary-color);
        }
        
        .document-loading.spinning {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .page-title {
                text-align: left;
                font-size: 24px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-title {
                font-size: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-radius: var(--border-radius);
                margin-bottom: 5px;
            }

            .student-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .history-table {
                display: block;
                overflow: auto;
            }
            
            .document-modal-content {
                width: 98%;
                height: 98vh;
                margin: 1% auto;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-title {
                font-size: 18px;
            }
            
            .document-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="../admin/dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Student Document Verification</h1>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i> Student verification status updated successfully.
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <?php
            $verified_count = 0;
            $unverified_count = 0;
            $rejected_count = 0;
            
            foreach ($student_documents as $student) {
                $student_id = $student['student_id'];
                if (isset($verification_map[$student_id])) {
                    if ($verification_map[$student_id]['status'] === 'verified') {
                        $verified_count++;
                    } else {
                        $rejected_count++;
                    }
                } else {
                    $unverified_count++;
                }
            }
            ?>
            
            <div class="stat-card">
                <div class="stat-icon unverified">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $unverified_count; ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon verified">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $verified_count; ?></div>
                <div class="stat-label">Verified Students</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon rejected">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $rejected_count; ?></div>
                <div class="stat-label">Rejected Students</div>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="students">Student Documents</div>
            <div class="tab" data-tab="history">Verification History</div>
        </div>

        <div class="tab-content active" id="students">
            <?php if (empty($student_documents)): ?>
                <div class="card">
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h3>No Student Documents</h3>
                        <p>There are no student documents to verify.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($student_documents as $student): 
                        $student_id = $student['student_id'];
                        $verification_status = isset($verification_map[$student_id]) ? $verification_map[$student_id]['status'] : 'unverified';
                        $verification_data = isset($verification_map[$student_id]) ? $verification_map[$student_id] : null;
                    ?>
                        <div class="student-card <?php echo $verification_status; ?>">
                            <div class="student-name"><?php echo htmlspecialchars($student['student_name']); ?></div>
                            <div class="student-info">
                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['student_email']); ?></div>
                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($student['student_phone']); ?></div>
                                <div><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['student_id_number']); ?></div>
                                <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($student['student_location']); ?></div>
                                <div><i class="fas fa-calendar"></i> Joined: <?php echo date('M j, Y', strtotime($student['student_joined'])); ?></div>
                                <div><i class="fas fa-bed"></i> <?php echo $student['booking_count']; ?> Bookings</div>
                            </div>
                            <div class="student-meta">
                                <span>Documents uploaded: <?php echo date('M j, Y', strtotime($student['uploaded_at'])); ?></span>
                                <span class="status-badge status-<?php echo $verification_status; ?>">
                                    <?php echo ucfirst($verification_status); ?>
                                </span>
                            </div>
                            
                            <?php if ($verification_data && !empty($verification_data['notes'])): ?>
                                <div class="student-meta">
                                    <strong>Verification Notes:</strong>
                                    <p><?php echo htmlspecialchars($verification_data['notes']); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="student-files">
                                <div class="file-item">
                                    <span class="file-name">Ghana Card</span>
                                    <div class="file-actions">
                                        <button class="btn btn-primary btn-sm view-document" 
                                                data-file-path="<?php echo htmlspecialchars($student['ghana_card_path']); ?>"
                                                data-file-name="Ghana Card - <?php echo htmlspecialchars($student['student_name']); ?>"
                                                data-student-id="<?php echo $student_id; ?>"
                                                data-doc-type="ghana_card">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="view_document.php?file=<?php echo urlencode($student['ghana_card_path']); ?>&student_id=<?php echo $student_id; ?>&type=ghana_card&download=1" 
                                           class="btn btn-info btn-sm">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Passport Photo</span>
                                    <div class="file-actions">
                                        <button class="btn btn-primary btn-sm view-document" 
                                                data-file-path="<?php echo htmlspecialchars($student['passport_path']); ?>"
                                                data-file-name="Passport - <?php echo htmlspecialchars($student['student_name']); ?>"
                                                data-student-id="<?php echo $student_id; ?>"
                                                data-doc-type="passport">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="view_document.php?file=<?php echo urlencode($student['passport_path']); ?>&student_id=<?php echo $student_id; ?>&type=passport&download=1" 
                                           class="btn btn-info btn-sm">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($verification_status !== 'verified'): ?>
                                <div class="verification-form">
                                    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                                        <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                        <div class="form-group">
                                            <label class="form-label" for="verification_notes_<?php echo $student_id; ?>">Verification Notes:</label>
                                            <textarea class="form-control" id="verification_notes_<?php echo $student_id; ?>" name="verification_notes" placeholder="Add notes about your verification decision"><?php echo $verification_data['notes'] ?? ''; ?></textarea>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" name="action" value="verify" class="btn btn-success">
                                                <i class="fas fa-check"></i> Verify
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="student-meta">
                                    <p><strong>Verified by:</strong> <?php echo htmlspecialchars($verification_data['verified_by_name'] ?? 'Admin'); ?></p>
                                    <p><strong>Verified on:</strong> <?php echo date('M j, Y g:i A', strtotime($verification_data['verified_at'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="history">
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-history"></i>
                    Verification History
                </h2>
                
                <?php if (empty($verification_history)): ?>
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3>No Verification History</h3>
                        <p>There is no verification history to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Status</th>
                                    <th>Verified By</th>
                                    <th>Date</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verification_history as $history): 
                                    // Get student name for this verification record
                                    $student_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                                    $student_stmt->execute([$history['student_id']]);
                                    $student = $student_stmt->fetch();
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['username'] ?? 'Unknown Student'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $history['status']; ?>">
                                                <?php echo ucfirst($history['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['verified_by_name'] ?? 'Admin'); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($history['verified_at'])); ?></td>
                                        <td><?php echo !empty($history['notes']) ? htmlspecialchars($history['notes']) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div id="documentModal" class="document-modal">
        <div class="document-modal-content">
            <div class="modal-header">
                <h3 id="modal-title" class="modal-title">Document Viewer</h3>
                <button class="close-document-modal">&times;</button>
            </div>
            <div class="document-viewer-container">
                <div class="document-loading" id="documentLoading">
                    <div class="spinner"></div>
                    <p>Loading document...</p>
                </div>
                <iframe id="document-viewer" class="document-viewer" src="about:blank"></iframe>
            </div>
            <div class="document-actions">
                <a id="download-link" href="#" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download Document
                </a>
                <button class="btn btn-danger close-document">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });

        // Confirmation for reject action
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const actionButton = e.submitter;
                if (actionButton && actionButton.value === 'reject') {
                    const notes = this.querySelector('textarea[name="verification_notes"]').value;
                    if (!notes || notes.trim() === '') {
                        e.preventDefault();
                        alert('Please provide verification notes when rejecting student documents.');
                    } else if (!confirm('Are you sure you want to reject this student\'s documents?')) {
                        e.preventDefault();
                    }
                }
            });
        });

        // Document viewer functionality
        const modal = document.getElementById('documentModal');
        const documentViewer = document.getElementById('document-viewer');
        const downloadLink = document.getElementById('download-link');
        const modalTitle = document.getElementById('modal-title');
        const closeModalBtn = document.querySelector('.close-document-modal');
        const closeDocumentBtn = document.querySelector('.close-document');
        const documentLoading = document.getElementById('documentLoading');
        
        // Open document in modal
        document.querySelectorAll('.view-document').forEach(button => {
            button.addEventListener('click', function() {
                const filePath = this.getAttribute('data-file-path');
                const fileName = this.getAttribute('data-file-name');
                const studentId = this.getAttribute('data-student-id');
                const docType = this.getAttribute('data-doc-type');
                
                // Set modal title
                modalTitle.textContent = fileName;
                
                // Show loading spinner
                documentLoading.classList.add('spinning');
                documentViewer.style.display = 'none';
                
                // Set iframe source - no download parameter for viewing
                const viewerUrl = `view_document.php?file=${encodeURIComponent(filePath)}&student_id=${studentId}&type=${docType}`;
                documentViewer.src = viewerUrl;
                
                // Set download link - add download parameter
                const downloadUrl = `view_document.php?file=${encodeURIComponent(filePath)}&student_id=${studentId}&type=${docType}&download=1`;
                downloadLink.href = downloadUrl;
                downloadLink.setAttribute('download', '');
                
                // Show modal
                modal.style.display = 'block';
                
                // Hide loading spinner when iframe loads
                documentViewer.onload = function() {
                    documentLoading.classList.remove('spinning');
                    documentViewer.style.display = 'block';
                };
                
                // Handle iframe errors
                documentViewer.onerror = function() {
                    documentLoading.classList.remove('spinning');
                    documentViewer.style.display = 'block';
                    documentViewer.srcdoc = `
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <style>
                                body { 
                                    font-family: Arial, sans-serif; 
                                    display: flex; 
                                    justify-content: center; 
                                    align-items: center; 
                                    height: 100vh; 
                                    margin: 0; 
                                    background: #f8f9fa; 
                                }
                                .error-container { 
                                    text-align: center; 
                                    padding: 40px; 
                                    background: white; 
                                    border-radius: 8px; 
                                    box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                                }
                                .error-icon { 
                                    font-size: 48px; 
                                    color: #dc3545; 
                                    margin-bottom: 20px; 
                                }
                            </style>
                        </head>
                        <body>
                            <div class="error-container">
                                <div class="error-icon">⚠️</div>
                                <h2>Unable to Load Document</h2>
                                <p>The document could not be loaded. Please try downloading the file instead.</p>
                                <a href="${downloadUrl}" style="display: inline-block; padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-top: 15px;">Download File</a>
                            </div>
                        </body>
                        </html>
                    `;
                };
            });
        });
        
        // Close modal
        function closeDocumentModal() {
            modal.style.display = 'none';
            documentViewer.src = 'about:blank';
            documentLoading.classList.remove('spinning');
            documentViewer.style.display = 'block';
        }
        
        closeModalBtn.addEventListener('click', closeDocumentModal);
        closeDocumentBtn.addEventListener('click', closeDocumentModal);
        
        // Close modal when clicking outside content
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeDocumentModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'block') {
                closeDocumentModal();
            }
        });
    </script>
</body>
</html>