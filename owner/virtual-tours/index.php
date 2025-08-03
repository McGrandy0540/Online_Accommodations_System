<?php
session_start();
require_once __DIR__. '../../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get all properties owned by this user
$properties_stmt = $pdo->prepare("SELECT p.id, p.property_name 
                                FROM property p
                                JOIN property_owners po ON p.id = po.property_id
                                WHERE po.owner_id = ? AND p.deleted = 0
                                ORDER BY p.property_name");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $property_id = $_POST['property_id'];
        $tour_title = $_POST['tour_title'];
        $tour_description = $_POST['tour_description'];
        $is_360 = isset($_POST['is_360']) ? 1 : 0;
        $is_vr_compatible = isset($_POST['is_vr_compatible']) ? 1 : 0;
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        
        // Verify the property belongs to this owner
        $verify_stmt = $pdo->prepare("SELECT 1 FROM property_owners WHERE owner_id = ? AND property_id = ?");
        $verify_stmt->execute([$owner_id, $property_id]);
        
        if (!$verify_stmt->fetch()) {
            throw new Exception("Invalid property selected");
        }
        
        // Handle file upload
        $media_path = '';
        if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/virtual-tours/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_ext = pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION);
            $allowed_ext = ['jpg', 'jpeg', 'png', 'mp4', 'webm', 'mov'];
            
            if (!in_array(strtolower($file_ext), $allowed_ext)) {
                throw new Exception("Invalid file type. Only images and videos are allowed.");
            }
            
            $filename = 'tour_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['media_file']['tmp_name'], $destination)) {
                throw new Exception("Failed to upload file");
            }
            
            $media_path = 'uploads/virtual-tours/' . $filename;
        } else {
            throw new Exception("Please select a media file to upload");
        }
        
        // Insert into database
        $insert_stmt = $pdo->prepare("INSERT INTO property_images 
                                    (property_id, image_url, is_virtual_tour, media_type, created_at)
                                    VALUES (?, ?, 1, ?, NOW())");
        
        $media_type = strpos($media_path, '.mp4') !== false || strpos($media_path, '.webm') !== false || strpos($media_path, '.mov') !== false ? 'video' : 'image';
        
        $insert_stmt->execute([
            $property_id,
            $media_path,
            $media_type
        ]);
        
        // Log activity
        $activity_stmt = $pdo->prepare("INSERT INTO activity_logs 
                                      (user_id, action, entity_type, entity_id, created_at)
                                      VALUES (?, ?, ?, ?, NOW())");
        $activity_stmt->execute([
            $owner_id,
            'upload_virtual_tour',
            'property',
            $property_id
        ]);
        
        $success_message = "Virtual tour uploaded successfully!";
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get existing virtual tours
$tours_stmt = $pdo->prepare("SELECT pi.*, p.property_name 
                            FROM property_images pi
                            JOIN property p ON pi.property_id = p.id
                            JOIN property_owners po ON p.id = po.property_id
                            WHERE po.owner_id = ? AND pi.is_virtual_tour = 1
                            ORDER BY pi.created_at DESC");
$tours_stmt->execute([$owner_id]);
$virtual_tours = $tours_stmt->fetchAll();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');


// Get profile picture path
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Tours | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.1/viewer.min.css">
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
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark-color);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Header Styles */
        .main-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 0.75rem 0;
            box-shadow: var(--box-shadow);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1030;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: white;
            font-weight: 600;
            font-size: 1.4rem;
        }

        .logo img {
            height: 36px;
            margin-right: 10px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            cursor: pointer;
        }

        .user-profile img, .user-profile .avatar-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-profile .avatar-placeholder {
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Dropdown Menu */
        .dropdown-menu {
            border: none;
            box-shadow: var(--box-shadow);
            border-radius: var(--border-radius);
        }

        .dropdown-item {
            padding: 0.5rem 1rem;
            transition: all var(--transition-speed);
        }

        .dropdown-item:hover {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
        }

        .dropdown-divider {
            border-color: rgba(0, 0, 0, 0.05);
        }

        /* Sidebar Styles */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            padding-top: var(--header-height);
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: white;
            color: var(--dark-color);
            position: fixed;
            height: calc(100vh - var(--header-height));
            transition: all var(--transition-speed);
            z-index: 1020;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            border-right: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-header {
            padding: 1.25rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            background-color: var(--primary-color);
            color: white;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            white-space: nowrap;
            margin: 0;
            font-weight: 600;
        }

        .sidebar.collapsed .sidebar-header h2 {
            display: none;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1rem;
            transition: transform var(--transition-speed);
            padding: 0.25rem;
        }

        .sidebar.collapsed .toggle-btn i {
            transform: rotate(180deg);
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--dark-color);
            text-decoration: none;
            transition: all var(--transition-speed);
            white-space: nowrap;
            font-size: 0.95rem;
        }

        .sidebar.collapsed .sidebar-menu li a {
            justify-content: center;
            padding: 0.75rem 0.5rem;
        }

        .sidebar-menu li a:hover {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
        }

        .sidebar-menu li a i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            color: var(--dark-color);
        }

        .sidebar.collapsed .sidebar-menu li a i {
            margin-right: 0;
            font-size: 1.2rem;
        }

        .sidebar-menu li a:hover i {
            color: var(--primary-color);
        }

        .menu-text {
            transition: opacity var(--transition-speed);
        }

        .sidebar.collapsed .menu-text {
            display: none;
        }

        .sidebar-menu li a.active {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .sidebar-menu li a.active i {
            color: var(--primary-color);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 1.5rem;
            background-color: #f5f7fa;
        }

        .sidebar.collapsed + .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Dashboard Content */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
            color: var(--secondary-color);
            font-weight: 600;
            margin: 0;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            font-size: 1.2rem;
            color: var(--secondary-color);
            background: none;
            border: none;
            padding: 0.5rem;
        }

        .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background-color: white;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.25rem;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Form Styles */
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            transition: all var(--transition-speed);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        /* File Upload */
        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-upload-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            border: 2px dashed #ddd;
            border-radius: var(--border-radius);
            background-color: #f9f9f9;
            text-align: center;
            transition: all var(--transition-speed);
            cursor: pointer;
        }

        .file-upload-label:hover {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }

        .file-upload-label i {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .file-upload-label .file-name {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--secondary-color);
            word-break: break-all;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: all var(--transition-speed);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--accent-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
            color: white;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Virtual Tour Gallery */
        .tour-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .tour-card {
            position: relative;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: all var(--transition-speed);
        }

        .tour-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .tour-media {
            position: relative;
            width: 100%;
            height: 200px;
            overflow: hidden;
        }

        .tour-media img, .tour-media video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .tour-card:hover .tour-media img, 
        .tour-card:hover .tour-media video {
            transform: scale(1.05);
        }

        .tour-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .tour-info {
            padding: 1rem;
            background-color: white;
        }

        .tour-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .tour-info p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .tour-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            font-size: 0.85rem;
        }

        .tour-date {
            color: #6c757d;
        }

        .tour-actions {
            display: flex;
            gap: 0.5rem;
        }

        .tour-actions .btn {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-top: 2rem;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 1.5rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem;
        }

        .modal-title {
            font-weight: 600;
            color: var(--secondary-color);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.5rem;
        }

        /* Footer Styles */
        .main-footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 2.5rem 0 1.5rem;
            margin-top: auto;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .footer-column h3 {
            margin-bottom: 1.25rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .footer-column ul {
            list-style: none;
            padding: 0;
        }

        .footer-column li {
            margin-bottom: 0.75rem;
        }

        .footer-column a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .footer-column a:hover {
            color: white;
            padding-left: 5px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .social-links a {
            color: white;
            font-size: 1.1rem;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            grid-column: 1 / -1;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            background: none;
            border: none;
            padding: 0.5rem;
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1.25rem;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
                box-shadow: none;
            }

            .sidebar.active {
                left: 0;
                box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            }

            .sidebar.collapsed {
                left: calc(-1 * var(--sidebar-collapsed-width));
            }

            .sidebar.collapsed.active {
                left: 0;
                width: var(--sidebar-collapsed-width);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .tour-gallery {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
            }

            .footer-container {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }
            
            .footer-container {
                grid-template-columns: 1fr;
            }
            
            .header-container {
                padding: 0 15px;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .logo img {
                height: 30px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../../assets/images/ktu logo.png" alt="landlords&tenants Logo">
                <span>landlords&tenants</span>
            </a>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($owner['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($owner['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="../../auth/logout.php" method="POST">
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Owner Dashboard</h2>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="../maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="upload.php" class="active"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="../settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Virtual Tours Management</h1>
                <button class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="badge"></span>
                </button>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload New Virtual Tour</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= htmlspecialchars($success_message) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="manage.php" method="POST" enctype="multipart/form-data">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="property_id" class="form-label">Property</label>
                                <select class="form-select" id="property_id" name="property_id" required>
                                    <option value="">Select Property</option>
                                    <?php foreach ($properties as $property): ?>
                                        <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['property_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="tour_title" class="form-label">Tour Title</label>
                                <input type="text" class="form-control" id="tour_title" name="tour_title" required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="tour_description" class="form-label">Description</label>
                            <textarea class="form-control" id="tour_description" name="tour_description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Media File</label>
                            <div class="file-upload">
                                <input type="file" class="file-upload-input" id="media_file" name="media_file" accept="image/*,video/*" required>
                                <label for="media_file" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Click to upload or drag and drop</span>
                                    <small>Supports: JPG, PNG, MP4, WEBM (Max 50MB)</small>
                                    <span class="file-name" id="file-name">No file selected</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_360" name="is_360">
                                    <label class="form-check-label" for="is_360">360° View</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_vr_compatible" name="is_vr_compatible">
                                    <label class="form-check-label" for="is_vr_compatible">VR Compatible</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured">
                                    <label class="form-check-label" for="is_featured">Featured Tour</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn">
                                <i class="fas fa-upload me-2"></i>Upload Tour
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-photo-video me-2"></i>Your Virtual Tours</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($virtual_tours)): ?>
                        <div class="empty-state">
                            <i class="fas fa-photo-video"></i>
                            <h3>No Virtual Tours Yet</h3>
                            <p>You haven't uploaded any virtual tours yet. Upload your first tour to showcase your properties in an immersive way.</p>
                        </div>
                    <?php else: ?>
                        <div class="tour-gallery">
                            <?php foreach ($virtual_tours as $tour): ?>
                                <div class="tour-card">
                                    <div class="tour-media">
                                        <?php if (strpos($tour['image_url'], '.mp4') !== false || strpos($tour['image_url'], '.webm') !== false || strpos($tour['image_url'], '.mov') !== false): ?>
                                            <video src="../../<?= htmlspecialchars($tour['image_url']) ?>" controls></video>
                                        <?php else: ?>
                                            <img src="../../<?= htmlspecialchars($tour['image_url']) ?>" alt="Virtual Tour">
                                        <?php endif; ?>
                                        <span class="tour-badge">
                                            <?= strpos($tour['image_url'], '.mp4') !== false || strpos($tour['image_url'], '.webm') !== false || strpos($tour['image_url'], '.mov') !== false ? 'Video' : 'Image' ?>
                                        </span>
                                    </div>
                                    <div class="tour-info">
                                        <h3><?= htmlspecialchars($tour['property_name']) ?></h3>
                                        <p>Uploaded: <?= date('M j, Y', strtotime($tour['created_at'])) ?></p>
                                        <div class="tour-meta">
                                            <span class="tour-date"><?= date('g:i a', strtotime($tour['created_at'])) ?></span>
                                            <div class="tour-actions">
                                                <button class="btn btn-outline btn-sm view-tour" data-url="../../<?= htmlspecialchars($tour['image_url']) ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <button class="btn btn-outline btn-sm" data-bs-toggle="modal" data-bs-target="#shareModal" data-id="<?= $tour['id'] ?>">
                                                    <i class="fas fa-share-alt"></i> Share
                                                </button>
                                                <button class="btn btn-outline btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal" data-id="<?= $tour['id'] ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View Tour Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Virtual Tour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="tour-viewer" style="width: 100%; height: 500px; background-color: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-spinner fa-spin fa-3x" style="color: var(--primary-color);"></i>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div class="modal fade" id="shareModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Share Virtual Tour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="share-link" class="form-label">Direct Link</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="share-link" readonly>
                            <button class="btn btn-outline" id="copy-link">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Share via</label>
                        <div class="d-flex gap-2">
                            <a href="#" class="btn btn-outline flex-grow-1">
                                <i class="fab fa-facebook-f"></i> Facebook
                            </a>
                            <a href="#" class="btn btn-outline flex-grow-1">
                                <i class="fab fa-twitter"></i> Twitter
                            </a>
                            <a href="#" class="btn btn-outline flex-grow-1">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </a>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="embed-code" class="form-label">Embed Code</label>
                        <textarea class="form-control" id="embed-code" rows="3" readonly></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Virtual Tour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this virtual tour? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirm-delete">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-column">
                <h3>About landlords&tenants</h3>
                <p>Providing quality accommodation with modern amenities and secure living spaces.</p>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="../../">Home</a></li>
                    <li><a href="../../properties/">Properties</a></li>
                    <li><a href="../../about/">About Us</a></li>
                    <li><a href="../../contact/">Contact</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support</h3>
                <ul>
                    <li><a href="../../faq/">FAQ</a></li>
                    <li><a href="../../help/">Help Center</a></li>
                    <li><a href="../../privacy/">Privacy Policy</a></li>
                    <li><a href="../../terms/">Terms of Service</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Contact Us</h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt me-2"></i> 123 Campus Drive, University Town</li>
                    <li><i class="fas fa-phone me-2"></i> +233 240687599</li>
                    <li><i class="fas fa-envelope me-2"></i> owners@landlords&tenants.com</li>
                </ul>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            
            <div class="copyright">
                &copy; <?= date('Y') ?> landlords&tenants. All rights reserved.
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.1/viewer.min.js"></script>
    <script>
        // Toggle sidebar collapse
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });

        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 992 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // File upload name display
        document.getElementById('media_file').addEventListener('change', function(e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file selected';
            document.getElementById('file-name').textContent = fileName;
        });

        // View tour modal
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const tourViewer = document.getElementById('tour-viewer');
        
        document.querySelectorAll('.view-tour').forEach(button => {
            button.addEventListener('click', function() {
                const mediaUrl = this.getAttribute('data-url');
                tourViewer.innerHTML = '';
                
                if (mediaUrl.includes('.mp4') || mediaUrl.includes('.webm') || mediaUrl.includes('.mov')) {
                    const video = document.createElement('video');
                    video.src = mediaUrl;
                    video.controls = true;
                    video.style.width = '100%';
                    video.style.height = '100%';
                    tourViewer.appendChild(video);
                } else {
                    const img = document.createElement('img');
                    img.src = mediaUrl;
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '100%';
                    tourViewer.appendChild(img);
                    
                    // Initialize image viewer
                    new Viewer(img, {
                        inline: true,
                        viewed() {
                            viewer.zoomTo(1);
                        }
                    });
                }
                
                viewModal.show();
            });
        });

        // Share modal
        const shareModal = new bootstrap.Modal(document.getElementById('shareModal'));
        const shareLink = document.getElementById('share-link');
        const embedCode = document.getElementById('embed-code');
        
        document.querySelectorAll('[data-bs-target="#shareModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const tourId = this.getAttribute('data-id');
                const url = `${window.location.origin}/virtual-tour/${tourId}`;
                shareLink.value = url;
                embedCode.value = `<iframe src="${url}" width="800" height="450" frameborder="0" allowfullscreen></iframe>`;
            });
        });

        // Copy link
        document.getElementById('copy-link').addEventListener('click', function() {
            shareLink.select();
            document.execCommand('copy');
            this.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => {
                this.innerHTML = '<i class="fas fa-copy"></i> Copy';
            }, 2000);
        });

        // Delete modal
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        let tourToDelete = null;
        
        document.querySelectorAll('[data-bs-target="#deleteModal"]').forEach(button => {
            button.addEventListener('click', function() {
                tourToDelete = this.getAttribute('data-id');
            });
        });

        document.getElementById('confirm-delete').addEventListener('click', function() {
            if (tourToDelete) {
                // Here you would typically make an AJAX call to delete the tour
                console.log(`Deleting tour with ID: ${tourToDelete}`);
                
                // Simulate deletion
                setTimeout(() => {
                    deleteModal.hide();
                    alert('Tour deleted successfully!');
                    location.reload(); // Refresh to see changes
                }, 1000);
            }
        });

        // Responsive adjustments
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>