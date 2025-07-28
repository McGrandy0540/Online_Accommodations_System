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

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../' . ltrim($path, '/');
}
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');

// Get properties for dropdown
$properties = $pdo->prepare("SELECT p.id, p.property_name 
                            FROM property p
                            JOIN property_owners po ON p.id = po.property_id
                            WHERE po.owner_id = ? AND p.deleted = 0");
$properties->execute([$owner_id]);
$property_options = $properties->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_virtual_tour'])) {
    $request_id = $_POST['request_id'];
    $property_id = $_POST['property_id'];
    
    // Get request details
    $stmt = $pdo->prepare("SELECT * FROM maintenance_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if ($request) {
        // Create virtual tour record
        $stmt = $pdo->prepare("INSERT INTO property_images 
                              (property_id, image_url, is_virtual_tour, media_type, created_at)
                              VALUES (?, ?, 1, 'virtual_tour', NOW())");
        $tour_url = "virtual_tour_" . time() . "_" . $property_id;
        $stmt->execute([$property_id, $tour_url]);
        $tour_id = $pdo->lastInsertId();
        
        // Update maintenance request with virtual tour link
        $notes = ($request['admin_notes'] ? $request['admin_notes'] . "\n\n" : "") . 
                date('Y-m-d H:i') . ": Virtual tour created for this maintenance request. Tour ID: " . $tour_id;
        
        $pdo->prepare("UPDATE maintenance_requests 
                      SET admin_notes = ?, updated_at = NOW()
                      WHERE id = ?")->execute([$notes, $request_id]);
        
        // Add notification
        $pdo->prepare("INSERT INTO notifications (user_id, property_id, message, type)
                      VALUES (?, ?, ?, 'system_alert')")
            ->execute([$request['user_id'], $property_id, 
                      "Virtual tour created for maintenance request #" . $request_id]);
            
        header("Location: index.php?success=3&tour_id=" . $tour_id);
        exit();
    }
}

// Get unread messages count for header
$messages = $pdo->prepare("SELECT COUNT(*) FROM chat_messages cm
                          JOIN chat_conversations cc ON cm.conversation_id = cc.id
                          WHERE (cc.owner_id = ? AND cm.sender_id != ?) AND cm.is_read = 0");
$messages->execute([$owner_id, $owner_id]);
$unread_messages = $messages->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Virtual Tour | UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .main-content-wrapper {
            max-width: 800px;
            margin: 0 auto;
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

        /* Form Styles */
        .form-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
        }

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
        }

        .btn:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
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

        .btn i {
            margin-right: 0.5rem;
        }

        /* Virtual Tour Upload */
        .upload-area {
            border: 2px dashed #ced4da;
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            margin-bottom: 1.5rem;
            cursor: pointer;
            transition: all var(--transition-speed);
        }

        .upload-area:hover {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }

        .upload-area i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .upload-area p {
            margin-bottom: 0;
            color: #6c757d;
        }

        .upload-area.active {
            border-color: var(--success-color);
            background-color: rgba(40, 167, 69, 0.05);
        }

        .file-info {
            display: none;
            margin-top: 1rem;
            padding: 0.75rem;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
        }

        /* Preview Section */
        .preview-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        .preview-title {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .preview-container {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .preview-card {
            width: calc(33.333% - 1rem);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            position: relative;
        }

        .preview-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .preview-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity var(--transition-speed);
        }

        .preview-card:hover .preview-overlay {
            opacity: 1;
        }

        .preview-actions {
            display: flex;
            gap: 0.5rem;
        }

        .preview-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.9);
            color: var(--dark-color);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-speed);
        }

        .preview-btn:hover {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        /* Responsive Styles */
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
            .dashboard-header h1 {
                font-size: 1.5rem;
            }

            .form-container {
                padding: 1.5rem;
            }

            .preview-card {
                width: calc(50% - 0.75rem);
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }

            .dashboard-header h1 {
                font-size: 1.4rem;
            }

            .form-container {
                padding: 1.25rem;
            }

            .preview-card {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../" class="logo">
                <img src="../../assets/images/ktu logo.png" alt="UniHomes Logo">
                <span>UniHomes</span>
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
                        <li><a class="dropdown-item" href="../owner/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../owner/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="../auth/logout.php" method="POST">
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
                    <li><a href="../owner/dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../owner/properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="../owner/bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="../owner/payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../owner/reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../owner/chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="../owner/maintenance/" class="active"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="../owner/virtual-tours/"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="../owner/settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="main-content-wrapper">
                <div class="dashboard-header">
                    <h1>Create Virtual Tour for Maintenance</h1>
                    <button class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <span class="badge"><?= $unread_messages ?></span>
                    </button>
                </div>

                <?php if (isset($_GET['success']) && $_GET['success'] == 3): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Virtual tour created successfully! You can now add images and 360° views.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="form-container">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="request_id" value="<?= htmlspecialchars($_GET['request_id'] ?? '') ?>">
                        <input type="hidden" name="property_id" value="<?= htmlspecialchars($_GET['property_id'] ?? '') ?>">
                        
                        <div class="form-group">
                            <label for="property" class="form-label">Property</label>
                            <select class="form-control" id="property" name="property" required>
                                <?php foreach ($property_options as $property): ?>
                                    <option value="<?= $property['id'] ?>" <?= ($property['id'] == ($_GET['property_id'] ?? '')) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($property['property_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Upload 360° Images</label>
                            <div class="upload-area" id="uploadArea">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Drag & drop your 360° images here or click to browse</p>
                                <input type="file" id="fileInput" name="tour_images[]" multiple accept="image/*" style="display: none;">
                                <div class="file-info" id="fileInfo"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tour_title" class="form-label">Tour Title</label>
                            <input type="text" class="form-control" id="tour_title" name="tour_title" 
                                   value="Maintenance Tour - <?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="tour_description" class="form-label">Description</label>
                            <textarea class="form-control" id="tour_description" name="tour_description" 
                                      rows="3">Documenting maintenance progress</textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="index.php" class="btn btn-outline">
                                <i class="fas fa-arrow-left"></i> Back to Requests
                            </a>
                            <button type="submit" class="btn btn-primary" name="create_virtual_tour">
                                <i class="fas fa-save"></i> Create Virtual Tour
                            </button>
                        </div>
                    </form>
                    
                    <div class="preview-section">
                        <h3 class="preview-title">Preview</h3>
                        <div class="preview-container">
                            <!-- Sample preview cards - these would be dynamically generated from uploaded images -->
                            <div class="preview-card">
                                <img src="https://via.placeholder.com/300x200?text=360+View+1" alt="360 View">
                                <div class="preview-overlay">
                                    <div class="preview-actions">
                                        <button class="preview-btn">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="preview-btn">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="preview-card">
                                <img src="https://via.placeholder.com/300x200?text=360+View+2" alt="360 View">
                                <div class="preview-overlay">
                                    <div class="preview-actions">
                                        <button class="preview-btn">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="preview-btn">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');

        uploadArea.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (fileInput.files.length > 0) {
                uploadArea.classList.add('active');
                fileInfo.style.display = 'block';
                
                let fileList = '';
                for (let i = 0; i < fileInput.files.length; i++) {
                    fileList += `<div>${fileInput.files[i].name} (${formatFileSize(fileInput.files[i].size)})</div>`;
                }
                
                fileInfo.innerHTML = fileList;
            }
        });

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('active');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('active');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('active');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                
                let fileList = '';
                for (let i = 0; i < e.dataTransfer.files.length; i++) {
                    fileList += `<div>${e.dataTransfer.files[i].name} (${formatFileSize(e.dataTransfer.files[i].size)})</div>`;
                }
                
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = fileList;
            }
        });

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Resize handler
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>