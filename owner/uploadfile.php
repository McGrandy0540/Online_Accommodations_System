<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get owner data
$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle file upload
$upload_success = false;
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if files were uploaded
    if (isset($_FILES['ghana_card']) && isset($_FILES['passport']) && isset($_FILES['land_document']) && isset($_FILES['property_document'])) {
        $ghana_card = $_FILES['ghana_card'];
        $passport = $_FILES['passport'];
        $land_document = $_FILES['land_document'];
        $property_document = $_FILES['property_document'];
        
        // Validate file types and sizes
        $allowed_image_types = ['image/jpeg', 'image/jpg', 'image/png'];
        $allowed_document_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $valid_files = true;
        $validation_errors = [];
        
        // Validate Ghana Card
        if (!in_array($ghana_card['type'], $allowed_image_types)) {
            $valid_files = false;
            $validation_errors[] = "Ghana Card must be an image (JPEG, JPG, PNG)";
        } elseif ($ghana_card['size'] > $max_size) {
            $valid_files = false;
            $validation_errors[] = "Ghana Card must be less than 5MB";
        }
        
        // Validate Passport
        if (!in_array($passport['type'], $allowed_image_types)) {
            $valid_files = false;
            $validation_errors[] = "Passport must be an image (JPEG, JPG, PNG)";
        } elseif ($passport['size'] > $max_size) {
            $valid_files = false;
            $validation_errors[] = "Passport must be less than 5MB";
        }
        
        // Validate Land Document
        if (!in_array($land_document['type'], $allowed_document_types)) {
            $valid_files = false;
            $validation_errors[] = "Land Document must be an image (JPEG, JPG, PNG) or PDF";
        } elseif ($land_document['size'] > $max_size) {
            $valid_files = false;
            $validation_errors[] = "Land Document must be less than 5MB";
        }
        
        // Validate Property Document
        if (!in_array($property_document['type'], $allowed_document_types)) {
            $valid_files = false;
            $validation_errors[] = "Property Document must be an image (JPEG, JPG, PNG) or PDF";
        } elseif ($property_document['size'] > $max_size) {
            $valid_files = false;
            $validation_errors[] = "Property Document must be less than 5MB";
        }
        
        if ($valid_files) {
            // Create uploads directory if it doesn't exist
            $upload_dir = '../uploads/owner_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filenames
            $ghana_card_filename = 'ghana_card_' . $owner_id . '_' . time() . '.' . pathinfo($ghana_card['name'], PATHINFO_EXTENSION);
            $passport_filename = 'passport_' . $owner_id . '_' . time() . '.' . pathinfo($passport['name'], PATHINFO_EXTENSION);
            $land_document_filename = 'land_doc_' . $owner_id . '_' . time() . '.' . pathinfo($land_document['name'], PATHINFO_EXTENSION);
            $property_document_filename = 'property_doc_' . $owner_id . '_' . time() . '.' . pathinfo($property_document['name'], PATHINFO_EXTENSION);
            
            $ghana_card_path = $upload_dir . $ghana_card_filename;
            $passport_path = $upload_dir . $passport_filename;
            $land_document_path = $upload_dir . $land_document_filename;
            $property_document_path = $upload_dir . $property_document_filename;
            
            // Move uploaded files
            if (move_uploaded_file($ghana_card['tmp_name'], $ghana_card_path) && 
                move_uploaded_file($passport['tmp_name'], $passport_path) &&
                move_uploaded_file($land_document['tmp_name'], $land_document_path) &&
                move_uploaded_file($property_document['tmp_name'], $property_document_path)) {
                
                // Save to database
                $stmt = $pdo->prepare("
                    INSERT INTO owner_documents 
                    (owner_id, ghana_card_path, passport_path, land_document_path, property_document_path, uploaded_at, status) 
                    VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
                    ON DUPLICATE KEY UPDATE 
                    ghana_card_path = VALUES(ghana_card_path), 
                    passport_path = VALUES(passport_path),
                    land_document_path = VALUES(land_document_path),
                    property_document_path = VALUES(property_document_path),
                    uploaded_at = NOW(),
                    status = 'pending'
                ");
                
                if ($stmt->execute([$owner_id, $ghana_card_path, $passport_path, $land_document_path, $property_document_path])) {
                    $upload_success = true;
                    $success_message = "Documents uploaded successfully! Our administrators will verify your documents within 24-48 hours.";
                } else {
                    $error_message = "Error saving document information to database.";
                }
            } else {
                $error_message = "Error uploading files. Please try again.";
            }
        } else {
            $error_message = implode("<br>", $validation_errors);
        }
    } else {
        $error_message = "Please select all required documents.";
    }
}

// Check if documents already exist
$stmt = $pdo->prepare("SELECT * FROM owner_documents WHERE owner_id = ?");
$stmt->execute([$owner_id]);
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
    <title>Property Owner Verification - Student Accommodation System</title>
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

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
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

        .pdf-preview {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f1f1;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 15px;
            gap: 10px;
            color: #e74c3c;
            font-weight: bold;
        }

        .requirements {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--warning-color);
            grid-column: 1 / -1;
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

        @media (min-width: 992px) {
            .doc-list {
                grid-template-columns: 1fr 1fr 1fr 1fr;
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

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
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
                <h1 class="page-title">Property Owner Verification</h1>
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
                Property Owner Verification
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                To verify your identity as a legitimate property owner, please upload the following documents. This verification is required before you can list properties on our platform.
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

                <div class="upload-section">
                    <div class="upload-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3 class="upload-title">Land Ownership Document</h3>
                    <p class="upload-description">Upload proof of land ownership (Deed, Title, etc.)</p>
                    <input type="file" name="land_document" id="land_document" class="file-input" accept="image/jpeg, image/jpg, image/png, application/pdf" required>
                    <div id="land_document_preview" class="pdf-preview" style="display: none;">
                        <i class="fas fa-file-pdf"></i>
                        <span>PDF Document</span>
                    </div>
                    <img id="land_document_image_preview" class="file-preview" alt="Land Document Preview">
                </div>

                <div class="upload-section">
                    <div class="upload-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <h3 class="upload-title">Property Document</h3>
                    <p class="upload-description">Upload property registration or building permit</p>
                    <input type="file" name="property_document" id="property_document" class="file-input" accept="image/jpeg, image/jpg, image/png, application/pdf" required>
                    <div id="property_document_preview" class="pdf-preview" style="display: none;">
                        <i class="fas fa-file-pdf"></i>
                        <span>PDF Document</span>
                    </div>
                    <img id="property_document_image_preview" class="file-preview" alt="Property Document Preview">
                </div>

                <div class="requirements">
                    <div class="requirements-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Important Requirements
                    </div>
                    <ul class="requirements-list">
                        <li>All documents must be clear and legible</li>
                        <li>Image formats: JPG, JPEG, or PNG only (max 5MB)</li>
                        <li>Document formats: PDF or images (max 5MB)</li>
                        <li>Ghana Card must show full details clearly</li>
                        <li>Land documents must show your name as the owner</li>
                        <li>Property documents must be officially recognized</li>
                        <li>By uploading, you consent to verification of these documents by our administrators</li>
                    </ul>
                </div>

                <div class="text-center" style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-primary btn-block mt-4">
                        <i class="fas fa-upload"></i> Submit Verification Documents
                    </button>
                </div>
            </form>

            <?php if ($existing_docs): ?>
                <div class="existing-docs">
                    <div class="existing-docs-title">
                        <i class="fas fa-history"></i> Previously Uploaded Documents
                        <?php if ($existing_docs['status'] !== 'pending'): ?>
                            <span class="status-badge status-<?php echo $existing_docs['status']; ?>">
                                <?php echo ucfirst($existing_docs['status']); ?>
                            </span>
                        <?php endif; ?>
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
                        <div class="doc-item">
                            <div class="doc-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="doc-info">
                                <div class="doc-name">Land Document</div>
                                <div class="doc-date">Uploaded on: <?php echo date('M j, Y g:i A', strtotime($existing_docs['uploaded_at'])); ?></div>
                            </div>
                        </div>
                        <div class="doc-item">
                            <div class="doc-icon">
                                <i class="fas fa-home"></i>
                            </div>
                            <div class="doc-info">
                                <div class="doc-name">Property Document</div>
                                <div class="doc-date">Uploaded on: <?php echo date('M j, Y g:i A', strtotime($existing_docs['uploaded_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($existing_docs['status'] == 'pending'): ?>
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-clock"></i>
                            Your documents are under review. Please allow 24-48 hours for verification.
                        </div>
                    <?php elseif ($existing_docs['status'] == 'approved'): ?>
                        <div class="alert alert-success mt-3">
                            <i class="fas fa-check-circle"></i>
                            Your documents have been verified and approved. You can now list properties on our platform.
                        </div>
                    <?php elseif ($existing_docs['status'] == 'rejected'): ?>
                        <div class="alert alert-error mt-3">
                            <i class="fas fa-exclamation-circle"></i>
                            Your documents were rejected. Please upload new documents that meet our requirements.
                        </div>
                    <?php endif; ?>
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

        document.getElementById('land_document').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                if (file.type === 'application/pdf') {
                    document.getElementById('land_document_preview').style.display = 'flex';
                    document.getElementById('land_document_image_preview').style.display = 'none';
                } else {
                    document.getElementById('land_document_preview').style.display = 'none';
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('land_document_image_preview');
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                }
            }
        });

        document.getElementById('property_document').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                if (file.type === 'application/pdf') {
                    document.getElementById('property_document_preview').style.display = 'flex';
                    document.getElementById('property_document_image_preview').style.display = 'none';
                } else {
                    document.getElementById('property_document_preview').style.display = 'none';
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('property_document_image_preview');
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                }
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const ghanaCard = document.getElementById('ghana_card').files[0];
            const passport = document.getElementById('passport').files[0];
            const landDocument = document.getElementById('land_document').files[0];
            const propertyDocument = document.getElementById('property_document').files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (ghanaCard && ghanaCard.size > maxSize) {
                e.preventDefault();
                alert('Ghana Card must be less than 5MB');
                return false;
            }
            
            if (passport && passport.size > maxSize) {
                e.preventDefault();
                alert('Passport must be less than 5MB');
                return false;
            }
            
            if (landDocument && landDocument.size > maxSize) {
                e.preventDefault();
                alert('Land Document must be less than 5MB');
                return false;
            }
            
            if (propertyDocument && propertyDocument.size > maxSize) {
                e.preventDefault();
                alert('Property Document must be less than 5MB');
                return false;
            }
        });
    </script>
</body>
</html>