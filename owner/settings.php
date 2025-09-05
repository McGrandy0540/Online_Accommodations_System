<?php
session_start();
require_once  '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');

// Handle form submissions
$success_message = '';
$error_message = '';

// Get current user data
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$owner_id]);
$user = $user_stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
        $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        
        // Check if email is already taken by another user
        $email_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $email_check->execute([$email, $owner_id]);
        if ($email_check->fetch()) {
            throw new Exception("Email address already in use");
        }
        
        $update_stmt = $pdo->prepare("UPDATE users SET 
                                    email = ?, 
                                    phone_number = ?, 
                                    location = ?, 
                                    payment_method = ? 
                                    WHERE id = ?");
        $update_stmt->execute([$email, $phone_number, $location, $payment_method, $owner_id]);
        
        // Update session data
        $_SESSION['email'] = $email;
        
        $success_message = "Profile updated successfully!";
    } catch (Exception $e) {
        $error_message = "Error updating profile: " . $e->getMessage();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($current_password, $user['pwd'])) {
            throw new Exception("Current password is incorrect");
        }
        
        // Validate new password
        if (strlen($new_password) < 8) {
            throw new Exception("Password must be at least 8 characters long");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords don't match");
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET pwd = ? WHERE id = ?")->execute([$hashed_password, $owner_id]);
        
        $success_message = "Password changed successfully!";
    } catch (Exception $e) {
        $error_message = "Error changing password: " . $e->getMessage();
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    try {
        $upload_dir = '../../uploads/profile_pictures/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['profile_picture'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        // Validate file
        if (!in_array($file_ext, $allowed_ext)) {
            throw new Exception("Only JPG, JPEG, PNG & GIF files are allowed");
        }
        
        if ($file['size'] > 5000000) { // 5MB max
            throw new Exception("File size must be less than 5MB");
        }
        
        $new_filename = 'owner_' . $owner_id . '_' . time() . '.' . $file_ext;
        $destination = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Update database with relative path
            $relative_path = 'uploads/profile_pictures/' . $new_filename;
            $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$relative_path, $owner_id]);
            
            // Update session
            $_SESSION['profile_picture'] = $relative_path;
            $profile_pic_path = getProfilePicturePath($relative_path);
            
            $success_message = "Profile picture updated successfully!";
        } else {
            throw new Exception("Failed to upload file");
        }
    } catch (Exception $e) {
        $error_message = "Error uploading profile picture: " . $e->getMessage();
    }
}

// Handle notification preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    try {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        
        $pdo->prepare("UPDATE users SET 
                      email_notifications = ?, 
                      sms_notifications = ? 
                      WHERE id = ?")->execute([$email_notifications, $sms_notifications, $owner_id]);
        
        // Update session
        $_SESSION['email_notifications'] = $email_notifications;
        $_SESSION['sms_notifications'] = $sms_notifications;
        
        $success_message = "Notification preferences updated successfully!";
    } catch (Exception $e) {
        $error_message = "Error updating notification preferences: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Landlords&Tenant</title>
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
            height: 56px;
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

        /* Dashboard Layout */
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

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 1.5rem;
            background-color: #f5f7fa;
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

        /* Dashboard Content */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Card Styles */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background-color: white;
            margin-bottom: 1.5rem;
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
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            border: 1px solid #dee2e6;
            padding: 0.75rem 1rem;
            width: 100%;
            transition: all var(--transition-speed);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color), 0.25);
        }

        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.15rem;
        }

        .form-check-label {
            margin-left: 0.5rem;
        }

        /* Button Styles */
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

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: var(--success-color);
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: var(--accent-color);
        }

        /* Profile Picture Styles */
        .profile-picture-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2rem;
        }

        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
        }

        .profile-picture-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            border: 5px solid white;
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
        }

        /* Settings Tabs */
        .settings-tabs .nav-link {
            color: var(--secondary-color);
            font-weight: 500;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius);
            margin-right: 0.5rem;
        }

        .settings-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }

        .settings-tabs .nav-link:not(.active):hover {
            background-color: rgba(var(--primary-color), 0.1);
        }

        /* Utility Classes */
        .d-flex {
            display: flex;
        }

        .justify-content-between {
            justify-content: space-between;
        }

        .justify-content-end {
            justify-content: flex-end;
        }

        .align-items-center {
            align-items: center;
        }

        .mt-4 {
            margin-top: 1.5rem;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .me-2 {
            margin-right: 0.5rem;
        }

        .text-center {
            text-align: center;
        }

        .py-4 {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }

        .mb-3 {
            margin-bottom: 1rem;
        }

        .text-muted {
            color: #6c757d;
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

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
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
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../assets/images/landlords-logo.png" alt="UniHomes Logo">
                <span>Landlords&Tenant</span>
            </a>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($_SESSION['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                       <li>
                         <form action="logout.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                          <button type="submit" class="dropdown-item">
                           <i class="fas fa-sign-out-alt "></i> Logout
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
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="virtual-tours/"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="announcement.php"><i class="fas fa-bullhorn"></i> <span class="menu-text">Announcements</span></a></li>
                    <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Account Settings</h1>
            </div>

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

            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs settings-tabs" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">Password</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab">Notifications</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="settingsTabsContent">
                        <!-- Profile Tab -->
                        <div class="tab-pane fade show active" id="profile" role="tabpanel">
                            <div class="profile-picture-container">
                                <?php if (!empty($profile_pic_path)): ?>
                                    <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile Picture" class="profile-picture">
                                <?php else: ?>
                                    <div class="profile-picture-placeholder">
                                        <?= substr($user['username'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" enctype="multipart/form-data" class="text-center">
                                    <div class="mb-3">
                                        <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*" style="display: none;">
                                        <button type="button" class="btn btn-outline" onclick="document.getElementById('profile_picture').click()">
                                            <i class="fas fa-camera me-2"></i>Change Photo
                                        </button>
                                        <button type="submit" class="btn">
                                            <i class="fas fa-save me-2"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="update_profile" value="1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="username" class="form-label">Username</label>
                                            <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="phone_number" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= htmlspecialchars($user['phone_number']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="location" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($user['location']) ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="payment_method" class="form-label">Preferred Payment Method</label>
                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                        <option value="mobile_money" <?= $user['payment_method'] === 'mobile_money' ? 'selected' : '' ?>>Mobile Money</option>
                                        <option value="credit_card" <?= $user['payment_method'] === 'credit_card' ? 'selected' : '' ?>>Credit Card</option>
                                        <option value="bank_transfer" <?= $user['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                        <option value="cash" <?= $user['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    </select>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Password Tab -->
                        <div class="tab-pane fade" id="password" role="tabpanel">
                            <form method="POST">
                                <input type="hidden" name="change_password" value="1">
                                <div class="form-group">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <small class="text-muted">Password must be at least 8 characters long</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Notifications Tab -->
                        <div class="tab-pane fade" id="notifications" role="tabpanel">
                            <form method="POST">
                                <input type="hidden" name="update_notifications" value="1">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" <?= $user['email_notifications'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="email_notifications">Email Notifications</label>
                                </div>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="sms_notifications" name="sms_notifications" <?= $user['sms_notifications'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sms_notifications">SMS Notifications</label>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn">
                                        <i class="fas fa-bell me-2"></i>Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

        // Responsive adjustments
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });

        // Show file name when selecting profile picture
        document.getElementById('profile_picture').addEventListener('change', function() {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const nextButton = this.nextElementSibling;
                nextButton.textContent = 'Upload ' + fileName;
            }
        });
    </script>
</body>
</html>