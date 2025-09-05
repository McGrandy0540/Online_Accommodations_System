<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a tenant
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get student data
$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle file upload
$upload_success = false;
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if files were uploaded
    if (isset($_FILES['ghana_card']) && isset($_FILES['passport'])) {
        $ghana_card = $_FILES['ghana_card'];
        $passport = $_FILES['passport'];
        
        // Validate file types and sizes
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (in_array($ghana_card['type'], $allowed_types) && 
            in_array($passport['type'], $allowed_types) &&
            $ghana_card['size'] <= $max_size && 
            $passport['size'] <= $max_size) {
            
            // Create uploads directory if it doesn't exist
            $upload_dir = '../uploads/student_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filenames
            $ghana_card_filename = 'ghana_card_' . $student_id . '_' . time() . '.' . pathinfo($ghana_card['name'], PATHINFO_EXTENSION);
            $passport_filename = 'passport_' . $student_id . '_' . time() . '.' . pathinfo($passport['name'], PATHINFO_EXTENSION);
            
            $ghana_card_path = $upload_dir . $ghana_card_filename;
            $passport_path = $upload_dir . $passport_filename;
            
            // Move uploaded files
            if (move_uploaded_file($ghana_card['tmp_name'], $ghana_card_path) && 
                move_uploaded_file($passport['tmp_name'], $passport_path)) {
                
                // Save to database
                $stmt = $pdo->prepare("
                    INSERT INTO student_documents 
                    (student_id, ghana_card_path, passport_path, uploaded_at) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    ghana_card_path = VALUES(ghana_card_path), 
                    passport_path = VALUES(passport_path),
                    uploaded_at = NOW()
                ");
                
                if ($stmt->execute([$student_id, $ghana_card_path, $passport_path])) {
                    $upload_success = true;
                    $success_message = "Documents uploaded successfully! Property owners can now view your verification documents.";
                } else {
                    $error_message = "Error saving document information to database.";
                }
            } else {
                $error_message = "Error uploading files. Please try again.";
            }
        } else {
            $error_message = "Invalid file type or size. Please upload JPEG or PNG images under 5MB.";
        }
    } else {
        $error_message = "Please select both Ghana Card and Passport images.";
    }
}

// Check if documents already exist
$stmt = $pdo->prepare("SELECT * FROM student_documents WHERE student_id = ?");
$stmt->execute([$student_id]);
$existing_docs = $stmt->fetch();

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Documents - Student Accommodation System</title>
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
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
            transition: background-color var(--transition-speed);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .page-title {
            font-size: 28px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
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

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--accent-color);
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .upload-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        @media (min-width: 768px) {
            .upload-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        .upload-section {
            background-color: var(--light-color);
            padding: 20px;
            border-radius: var(--border-radius);
            border: 2px dashed #ccc;
            transition: border-color var(--transition-speed);
            text-align: center;
        }

        .upload-section:hover {
            border-color: var(--primary-color);
        }

        .upload-icon {
            font-size: 48px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .upload-title {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .upload-description {
            color: #6c757d;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .file-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
        }

        .file-preview {
            margin-top: 15px;
            max-width: 100%;
            max-height: 200px;
            border-radius: var(--border-radius);
            display: none;
        }

        .requirements {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--warning-color);
        }

        .requirements-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .requirements-list {
            padding-left: 20px;
        }

        .requirements-list li {
            margin-bottom: 8px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .mt-4 {
            margin-top: 25px;
        }

        .text-center {
            text-align: center;
        }

        .existing-docs {
            margin-top: 30px;
            padding: 20px;
            background-color: #e8f4fc;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--info-color);
        }

        .existing-docs-title {
            font-weight: bold;
            margin-bottom: 15px;
            color: var(--secondary-color);
        }

        .doc-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 576px) {
            .doc-list {
                grid-template-columns: 1fr 1fr;
            }
        }

        .doc-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background-color: white;
            border-radius: var(--border-radius);
        }

        .doc-icon {
            font-size: 24px;
            color: var(--primary-color);
        }

        .doc-info {
            flex-grow: 1;
        }

        .doc-name {
            font-weight: 600;
        }

        .doc-date {
            font-size: 12px;
            color: #6c757d;
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
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
            
            .upload-form {
                grid-template-columns: 1fr;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Upload Verification Documents</h1>
                <div></div> <!-- Empty div for spacing -->
            </div>
        </div>

        <?php if ($upload_success && $success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-id-card"></i>
                Identity Verification
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                Property owners require these documents for verification before confirming your bookings.
            </div>

            <form action="" method="POST" enctype="multipart/form-data" class="upload-form">
                <div class="upload-section">
                    <div class="upload-icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <h3 class="upload-title">Ghana Card</h3>
                    <p class="upload-description">Upload a clear photo of your Ghana Card</p>
                    <input type="file" name="ghana_card" id="ghana_card" class="file-input" accept="image/jpeg, image/jpg, image/png" required>
                    <img id="ghana_card_preview" class="file-preview" alt="Ghana Card Preview">
                </div>

                <div class="upload-section">
                    <div class="upload-icon">
                        <i class="fas fa-passport"></i>
                    </div>
                    <h3 class="upload-title">Passport Photo</h3>
                    <p class="upload-description">Upload a recent passport-sized photograph</p>
                    <input type="file" name="passport" id="passport" class="file-input" accept="image/jpeg, image/jpg, image/png" required>
                    <img id="passport_preview" class="file-preview" alt="Passport Preview">
                </div>

                <div class="requirements">
                    <div class="requirements-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Important Requirements
                    </div>
                    <ul class="requirements-list">
                        <li>Documents must be clear and legible</li>
                        <li>File formats: JPG, JPEG, or PNG only</li>
                        <li>Maximum file size: 5MB per document</li>
                        <li>Ghana Card must show full details clearly</li>
                        <li>Passport photo must be recent and clear</li>
                        <li>By uploading, you consent to share these documents with property owners for verification purposes only</li>
                        <li>So not to to uplaod your document you will be remove from the system by the Administrator</li>
                    </ul>
                </div>

                <div class="text-center" style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-primary btn-block mt-4">
                        <i class="fas fa-upload"></i> Upload Documents
                    </button>
                </div>
            </form>

            <?php if ($existing_docs): ?>
                <div class="existing-docs">
                    <div class="existing-docs-title">
                        <i class="fas fa-history"></i> Previously Uploaded Documents
                    </div>
                    <div class="doc-list">
                        <div class="doc-item">
                            <div class="doc-icon">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <div class="doc-info">
                                <div class="doc-name">Ghana Card</div>
                                <div class="doc-date">Uploaded on: <?php echo date('M j, Y g:i A', strtotime($existing_docs['uploaded_at'])); ?></div>
                            </div>
                        </div>
                        <div class="doc-item">
                            <div class="doc-icon">
                                <i class="fas fa-passport"></i>
                            </div>
                            <div class="doc-info">
                                <div class="doc-name">Passport Photo</div>
                                <div class="doc-date">Uploaded on: <?php echo date('M j, Y g:i A', strtotime($existing_docs['uploaded_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // File preview functionality
        document.getElementById('ghana_card').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('ghana_card_preview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('passport').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('passport_preview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const ghanaCard = document.getElementById('ghana_card').files[0];
            const passport = document.getElementById('passport').files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (ghanaCard && ghanaCard.size > maxSize) {
                e.preventDefault();
                alert('Ghana Card image must be less than 5MB');
                return false;
            }
            
            if (passport && passport.size > maxSize) {
                e.preventDefault();
                alert('Passport image must be less than 5MB');
                return false;
            }
        });
    </script>
</body>
</html>