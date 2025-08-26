<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();



// Fetch student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submissions
$success = '';
$error = '';

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $phone = $_POST['phone'];
    $location = $_POST['location'];
    $gender = $_POST['gender'];
    $payment_method = $_POST['payment_method']; // New payment method field
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, phone_number = ?, location = ?, sex = ?, payment_method = ? WHERE id = ?");
        $stmt->execute([$full_name, $phone, $location, $gender, $payment_method, $student_id]);
        
        // Refresh student data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $success = "Profile updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating profile: " . $e->getMessage();
    }
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error = "New passwords do not match";
    } elseif (!password_verify($current_password, $student['pwd'])) {
        $error = "Current password is incorrect";
    } else {
        try {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET pwd = ? WHERE id = ?");
            $stmt->execute([$new_hash, $student_id]);
            
            $success = "Password changed successfully!";
        } catch (PDOException $e) {
            $error = "Error changing password: " . $e->getMessage();
        }
    }
}

// Update notification preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET email_notifications = ?, sms_notifications = ? WHERE id = ?");
        $stmt->execute([$email_notifications, $sms_notifications, $student_id]);
        
        $success = "Notification preferences updated!";
    } catch (PDOException $e) {
        $error = "Error updating preferences: " . $e->getMessage();
    }
}

// Handle profile picture upload and cropping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    $imageData = $_POST['avatar_data'];
    
    if (strpos($imageData, 'data:image') === 0) {
        list($type, $imageData) = explode(';', $imageData);
        list(, $imageData) = explode(',', $imageData);
        $imageData = base64_decode($imageData);
        
        $upload_dir = '../../../uploads/profile_pictures/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = 'avatar_' . $student_id . '_' . time() . '.png';
        $file_path = $upload_dir . $file_name;
        
        if (file_put_contents($file_path, $imageData)) {
            $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $stmt->execute([$file_path, $student_id]);
            
            // Refresh student data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $success = "Profile picture updated successfully!";
        } else {
            $error = "Error saving profile picture";
        }
    } else {
        $error = "Invalid image data";
    }
}

// Fetch unread notifications count
$unread_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$student_id]);
    $unread_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Silently ignore error
}



// Function to get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../../assets/images/default-avatar.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../uploads/profile_prictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
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

        /* Profile Card Styles */
        .profile-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
            position: relative;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            margin: 0 auto;
            background-color: var(--light-color);
            position: relative;
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar .avatar-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            background-color: var(--info-color);
            color: white;
            font-size: 3rem;
            font-weight: bold;
        }

        .profile-edit-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            box-shadow: var(--box-shadow);
            cursor: pointer;
        }

        .profile-name {
            margin-top: 1rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .profile-status {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .profile-body {
            padding: 2rem;
        }

        .section-title {
            font-size: 1.25rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--primary-color);
            border-radius: 3px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            transition: transform var(--transition-speed);
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
        }

        .info-card h3 i {
            color: var(--primary-color);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: var(--dark-color);
        }

        .info-value {
            color: #666;
        }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            font-size: 1rem;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-col {
            flex: 1;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .setting-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
        }

        .setting-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }

        .setting-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--success-color);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Mobile Navigation */
        .mobile-nav-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .mobile-nav {
            display: none;
            background: white;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-nav ul {
            display: flex;
            list-style: none;
        }

        .mobile-nav li {
            flex: 1;
            text-align: center;
        }

        .mobile-nav a {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.75rem;
            color: var(--dark-color);
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.3s;
        }

        .mobile-nav a.active, .mobile-nav a:hover {
            color: var(--primary-color);
        }

        .mobile-nav i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .profile-header {
                padding: 1.5rem 1rem;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .profile-body {
                padding: 1.5rem 1rem;
            }
            
            .form-container {
                padding: 1.5rem;
            }
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.15);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }
        
        .alert-danger {
            background-color: rgba(231, 76, 60, 0.15);
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
        }
        
        /* Dashboard Content */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            padding-top: var(--header-height);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 0;
            transition: var(--transition-speed);
            padding: 1.5rem;
            background-color: #f5f7fa;
        }
        
        .main-content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Dashboard Header */
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
        
        /* Tab Navigation */
        .profile-tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .profile-tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border: none;
            background: none;
            font-weight: 500;
            color: #6c757d;
            position: relative;
        }
        
        .profile-tab.active {
            color: var(--primary-color);
        }
        
        .profile-tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary-color);
            border-radius: 3px;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @media (max-width: 576px) {
            .profile-tabs {
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .profile-tab {
                padding: 0.75rem;
            }
        }
        
        /* Notifications Dropdown */
        .notifications-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: none;
            padding: 1rem;
        }
        
        .notification-item {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f9f9f9;
        }
        
        .notification-item.unread {
            background-color: #f0f8ff;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #777;
            margin-top: 0.25rem;
        }
        
        /* Cropper Modal */
        .cropper-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            display: none;
        }
        
        .cropper-container {
            width: 90%;
            max-width: 600px;
            height: 400px;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .cropper-buttons {
            margin-top: 1rem;
            display: flex;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../" class="logo">
                <img src="../../assets/images/logo-removebg-preview.png" alt="Landlords&Tenant Logo">
                <span>Landlords&Tenant</span>
            </a>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if ($student['profile_picture']): ?>
                            <img src="<?= getProfilePicturePath($student['profile_picture']) ?>" alt="Profile Picture">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($student['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($student['username']) ?></span>
                    </div>
                </div>
                <button class="mobile-nav-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Main Content - Profile -->
        <main class="main-content" id="profileView">
            <div class="main-content-wrapper">
                <div class="dashboard-header">
                    <h1>My Profile</h1>
                    <div style="position: relative;">
                        <button class="notification-bell" id="notificationBell">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="badge"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notifications-dropdown" id="notificationsDropdown">
                            <div id="notificationList">
                                <!-- Notifications will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Navigation -->
                <div class="profile-tabs">
                    <button class="profile-tab active" data-tab="profile">Profile</button>
                    <button class="profile-tab" data-tab="settings">Settings</button>
                    <button class="profile-tab" data-tab="security">Security</button>
                </div>
                
                <!-- Alerts -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= $success ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Tab -->
                <div class="tab-content active" id="profile-tab">
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php if ($student['profile_picture']): ?>
                                    <img src="<?= getProfilePicturePath($student['profile_picture']) ?>" alt="Profile Picture" id="profileAvatar">
                                <?php else: ?>
                                    <div class="avatar-placeholder" id="profileAvatar">
                                        <?= substr($student['username'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="profile-edit-btn" id="avatarEditBtn">
                                    <i class="fas fa-pencil-alt"></i>
                                </div>
                            </div>
                            <div class="profile-name"><?= htmlspecialchars($student['username']) ?></div>
                            <div class="profile-status">Tenant</div>
                        </div>
                        
                        <div class="profile-body">
                            <div class="section-title">Personal Information</div>
                            
                            <div class="info-grid">
                                <div class="info-card">
                                    <h3><i class="fas fa-user"></i> Basic Info</h3>
                                    <div class="info-item">
                                        <span class="info-label">Full Name:</span>
                                        <span class="info-value"><?= htmlspecialchars($student['username']) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Student ID:</span>
                                        <span class="info-value"><?= $student['student_id'] ? htmlspecialchars($student['user_id']) : 'Not set' ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Gender:</span>
                                        <span class="info-value"><?= ucfirst($student['sex']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <h3><i class="fas fa-address-book"></i> Contact Info</h3>
                                    <div class="info-item">
                                        <span class="info-label">Email:</span>
                                        <span class="info-value"><?= htmlspecialchars($student['email']) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Phone:</span>
                                        <span class="info-value"><?= htmlspecialchars($student['phone_number']) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Address:</span>
                                        <span class="info-value"><?= htmlspecialchars($student['location']) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Settings Tab -->
                <div class="tab-content" id="settings-tab">
                    <div class="form-container">
                        <form method="POST">
                            <input type="hidden" name="update_profile">
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($student['username']) ?>" required>
                                    </div>
                                </div>
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($student['phone_number']) ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Address</label>
                                <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($student['location']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-control" required>
                                    <option value="male" <?= $student['sex'] === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= $student['sex'] === 'female' ? 'selected' : '' ?>>Female</option>
                                    <option value="other" <?= $student['sex'] === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" class="form-control" required>
                                    <option value="cash" <?= $student['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="mobile_money" <?= $student['payment_method'] === 'mobile_money' ? 'selected' : '' ?>>Mobile Money</option>
                                    <option value="bank_transfer" <?= $student['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                </select>
                            </div>
                            
                            <div class="text-center" style="margin-top: 2rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Tab -->
                <div class="tab-content" id="security-tab">
                    <div class="form-container">
                        <form method="POST">
                            <input type="hidden" name="change_password">
                            
                            <div class="form-group">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" required minlength="8">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            
                            <div class="text-center" style="margin-top: 2rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Navigation -->
    <nav class="mobile-nav">
        <ul>
            <li>
                <a href="view.php" class="active">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li>
                <a href="edit.php">
                    <i class="fas fa-edit"></i>
                    <span>Edit</span>
                </a>
            </li>
            <li>
                <a href="settings.php">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Avatar Cropping Modal -->
    <div class="cropper-modal" id="cropperModal">
        <div class="cropper-container">
            <img id="cropperImage" src="">
        </div>
        <div class="cropper-buttons">
            <button class="btn btn-secondary" id="cancelCropBtn">Cancel</button>
            <button class="btn btn-primary" id="saveCropBtn">Save</button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        // Toggle tabs
        const tabs = document.querySelectorAll('.profile-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Notification dropdown
        const notificationBell = document.getElementById('notificationBell');
        const notificationsDropdown = document.getElementById('notificationsDropdown');
        
        if (notificationBell && notificationsDropdown) {
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                if (notificationsDropdown.style.display === 'block') {
                    notificationsDropdown.style.display = 'none';
                } else {
                    // Fetch notifications via AJAX
                    fetch('get_notifications.php')
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('notificationList').innerHTML = data;
                            notificationsDropdown.style.display = 'block';
                            
                            // Mark notifications as read
                            fetch('mark_notifications_read.php');
                        });
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationsDropdown.contains(e.target)) {
                    notificationsDropdown.style.display = 'none';
                }
            });
        }
        
        // Profile picture cropping
        let cropper;
        const avatarEditBtn = document.getElementById('avatarEditBtn');
        const cropperModal = document.getElementById('cropperModal');
        const cropperImage = document.getElementById('cropperImage');
        const cancelCropBtn = document.getElementById('cancelCropBtn');
        const saveCropBtn = document.getElementById('saveCropBtn');
        
        if (avatarEditBtn) {
            avatarEditBtn.addEventListener('click', function() {
                // Create file input
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                
                fileInput.addEventListener('change', function(e) {
                    if (e.target.files && e.target.files.length) {
                        const reader = new FileReader();
                        
                        reader.onload = function(event) {
                            cropperImage.src = event.target.result;
                            cropperModal.style.display = 'flex';
                            
                            // Initialize cropper
                            if (cropper) {
                                cropper.destroy();
                            }
                            
                            cropper = new Cropper(cropperImage, {
                                aspectRatio: 1,
                                viewMode: 1,
                                autoCropArea: 0.8,
                            });
                        };
                        
                        reader.readAsDataURL(e.target.files[0]);
                    }
                });
                
                fileInput.click();
            });
        }
        
        if (cancelCropBtn) {
            cancelCropBtn.addEventListener('click', function() {
                cropperModal.style.display = 'none';
                if (cropper) {
                    cropper.destroy();
                }
            });
        }
        
        if (saveCropBtn) {
            saveCropBtn.addEventListener('click', function() {
                if (cropper) {
                    const canvas = cropper.getCroppedCanvas({
                        width: 300,
                        height: 300,
                    });
                    
                    const croppedImage = canvas.toDataURL('image/png');
                    
                    // Submit via AJAX
                    const formData = new FormData();
                    formData.append('update_avatar', '1');
                    formData.append('avatar_data', croppedImage);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(() => {
                        location.reload();
                    });
                    
                    cropperModal.style.display = 'none';
                }
            });
        }
        
        // Mobile navigation toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });
    </script>
</body>
</html>