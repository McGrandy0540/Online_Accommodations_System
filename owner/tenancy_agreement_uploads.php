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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_agreement'])) {
    // Check if file was uploaded
    if (isset($_FILES['agreement_file']) && !empty($_POST['property_id']) && !empty($_POST['agreement_title'])) {
        $agreement_file = $_FILES['agreement_file'];
        $property_id = $_POST['property_id'];
        $agreement_title = $_POST['agreement_title'];
        $agreement_description = $_POST['agreement_description'] ?? '';
        
        // Validate file type and size
        $allowed_types = ['application/pdf'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        if (in_array($agreement_file['type'], $allowed_types)) {
            if ($agreement_file['size'] <= $max_size) {
                // Verify that the property belongs to this owner
                $property_check = $pdo->prepare("SELECT id, property_name FROM property WHERE id = ? AND owner_id = ?");
                $property_check->execute([$property_id, $owner_id]);
                $property = $property_check->fetch();
                
                if ($property) {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../uploads/tenancy_agreements/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($agreement_file['name'], PATHINFO_EXTENSION);
                    $filename = 'agreement_' . $property_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($agreement_file['tmp_name'], $file_path)) {
                        // Save to database
                        $stmt = $pdo->prepare("
                            INSERT INTO tenancy_agreements 
                            (property_id, owner_id, title, description, file_path) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        
                        if ($stmt->execute([$property_id, $owner_id, $agreement_title, $agreement_description, $file_path])) {
                            $agreement_id = $pdo->lastInsertId();
                            
                            // Grant access to ALL students who have booked and paid for this property
                            $grant_access = $pdo->prepare("
                                INSERT INTO student_agreement_access (agreement_id, student_id, booking_id)
                                SELECT ?, b.user_id, b.id
                                FROM bookings b
                                WHERE b.property_id = ? 
                                AND b.status IN ('paid', 'confirmed')
                                ON DUPLICATE KEY UPDATE agreement_id = VALUES(agreement_id)
                            ");
                            
                            if ($grant_access->execute([$agreement_id, $property_id])) {
                                $affected_students = $grant_access->rowCount();
                                $upload_success = true;
                                $success_message = "Tenancy agreement uploaded successfully! Made available to $affected_students students who have booked and paid for " . htmlspecialchars($property['property_name']) . ".";
                            } else {
                                $error_message = "Agreement uploaded but error granting access to students. Error: " . implode(" ", $grant_access->errorInfo());
                            }
                        } else {
                            $error_message = "Error saving agreement information to database.";
                        }
                    } else {
                        $error_message = "Error uploading file. Please try again.";
                    }
                } else {
                    $error_message = "Invalid property selected or you don't have permission to upload for this property.";
                }
            } else {
                $error_message = "File size must be less than 10MB.";
            }
        } else {
            $error_message = "Only PDF files are allowed.";
        }
    } else {
        $error_message = "Please fill all required fields and select a file.";
    }
}

// Get properties owned by this owner - grouped by name to avoid duplicates
$properties_stmt = $pdo->prepare("
    SELECT p.id, p.property_name
    FROM property p 
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY p.property_name
");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Group properties by name to avoid duplicates in dropdown
$grouped_properties = [];
foreach ($properties as $property) {
    $property_name = $property['property_name'];
    if (!isset($grouped_properties[$property_name])) {
        $grouped_properties[$property_name] = [];
    }
    $grouped_properties[$property_name][] = $property;
}

// Get uploaded agreements with student access count
$agreements_stmt = $pdo->prepare("
    SELECT ta.*, p.property_name, 
           COUNT(DISTINCT saa.student_id) as student_access_count
    FROM tenancy_agreements ta
    JOIN property p ON ta.property_id = p.id
    LEFT JOIN student_agreement_access saa ON ta.id = saa.agreement_id
    WHERE ta.owner_id = ?
    GROUP BY ta.id
    ORDER BY ta.uploaded_at DESC
");
$agreements_stmt->execute([$owner_id]);
$agreements = $agreements_stmt->fetchAll();

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
    <title>Tenancy Agreements - Property Owner Dashboard</title>
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
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (min-width: 768px) {
            .upload-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group-full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
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

        .agreements-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .agreement-card {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 20px;
            background-color: white;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .agreement-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }

        .agreement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .agreement-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .agreement-property {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .agreement-description {
            margin-bottom: 15px;
            color: #495057;
        }

        .agreement-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
            color: #6c757d;
        }

        .agreement-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
        }

        .no-agreements {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-agreements-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        /* Property grouping styles */
        .property-group {
            font-weight: bold;
            background-color: #f0f5ff;
        }
        
        .property-option {
            padding-left: 20px;
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

            .agreement-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agreement-actions {
                width: 100%;
                justify-content: space-between;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Tenancy Agreements</h1>
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
                <i class="fas fa-file-contract"></i>
                Upload New Tenancy Agreement
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                Upload a tenancy agreement that will be automatically shared with ALL students who have booked and paid for the selected property.
            </div>

            <form action="" method="POST" enctype="multipart/form-data" class="upload-form">
                <div class="form-group form-group-full">
                    <label class="form-label" for="property_id">Property *</label>
                    <select name="property_id" id="property_id" class="form-select" required>
                        <option value="">Select a Property</option>
                        <?php foreach ($grouped_properties as $property_name => $units): 
                            if (count($units) > 1): ?>
                                <optgroup label="<?php echo htmlspecialchars($property_name); ?>">
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>">
                                            <?php echo htmlspecialchars($property_name); ?> - Unit #<?php echo $unit['id']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php else: 
                                $property = $units[0];
                            ?>
                                <option value="<?php echo $property['id']; ?>">
                                    <?php echo htmlspecialchars($property_name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group form-group-full">
                    <label class="form-label" for="agreement_title">Agreement Title *</label>
                    <input type="text" name="agreement_title" id="agreement_title" class="form-input" placeholder="E.g., Tenancy Agreement 2025" required>
                </div>
                
                <div class="form-group form-group-full">
                    <label class="form-label" for="agreement_description">Description</label>
                    <textarea name="agreement_description" id="agreement_description" class="form-textarea" placeholder="Describe the contents of this agreement..."></textarea>
                </div>
                
                <div class="form-group form-group-full">
                    <label class="form-label" for="agreement_file">Agreement File (PDF only, max 10MB) *</label>
                    <input type="file" name="agreement_file" id="agreement_file" class="form-input" accept=".pdf" required>
                </div>
                
                <div class="form-group form-group-full">
                    <button type="submit" name="upload_agreement" class="btn btn-primary btn-block">
                        <i class="fas fa-upload"></i> Upload Agreement
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-alt"></i>
                Your Tenancy Agreements
            </h2>
            
            <?php if (empty($agreements)): ?>
                <div class="no-agreements">
                    <div class="no-agreements-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3>No Tenancy Agreements Yet</h3>
                    <p>Upload your first tenancy agreement using the form above.</p>
                </div>
            <?php else: ?>
                <div class="agreements-list">
                    <?php foreach ($agreements as $agreement): ?>
                        <div class="agreement-card">
                            <div class="agreement-header">
                                <h3 class="agreement-title"><?php echo htmlspecialchars($agreement['title']); ?></h3>
                                <div class="agreement-actions">
                                    <a href="<?php echo htmlspecialchars($agreement['file_path']); ?>" target="_blank" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?php echo htmlspecialchars($agreement['file_path']); ?>" download class="btn btn-primary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                            
                            <div class="agreement-property">
                                <i class="fas fa-home"></i> <?php echo htmlspecialchars($agreement['property_name']); ?>
                            </div>
                            
                            <?php if (!empty($agreement['description'])): ?>
                                <div class="agreement-description">
                                    <?php echo htmlspecialchars($agreement['description']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="agreement-meta">
                                <div>
                                    <i class="fas fa-users"></i> <?php echo $agreement['student_access_count']; ?> students have access
                                </div>
                                <div>
                                    <i class="fas fa-calendar"></i> Uploaded on <?php echo date('M j, Y', strtotime($agreement['uploaded_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Simple form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.upload-form');
            
            form.addEventListener('submit', function(e) {
                const propertySelect = document.getElementById('property_id');
                const titleInput = document.getElementById('agreement_title');
                const fileInput = document.getElementById('agreement_file');
                
                if (propertySelect.value === '') {
                    e.preventDefault();
                    alert('Please select a property');
                    propertySelect.focus();
                    return;
                }
                
                if (titleInput.value.trim() === '') {
                    e.preventDefault();
                    alert('Please enter an agreement title');
                    titleInput.focus();
                    return;
                }
                
                if (fileInput.files.length === 0) {
                    e.preventDefault();
                    alert('Please select a file to upload');
                    return;
                }
                
                const file = fileInput.files[0];
                if (file.type !== 'application/pdf') {
                    e.preventDefault();
                    alert('Please select a PDF file');
                    return;
                }
                
                if (file.size > 10 * 1024 * 1024) { // 10MB
                    e.preventDefault();
                    alert('File size must be less than 10MB');
                    return;
                }
            });
        });
    </script>
</body>
</html>