<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../includes/SMSService.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

if ($_SESSION['status'] !== 'student') {
    header("Location: ../../index.php");
    exit();
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Student';

// Database connection
$pdo = Database::getInstance();

// Add SMS preferences columns if they don't exist
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_notifications TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_booking_updates TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_payment_alerts TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_maintenance_updates TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_announcements TINYINT(1) DEFAULT 1");
} catch (PDOException $e) {
    error_log("SMS preferences table update error: " . $e->getMessage());
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_preferences'])) {
        // Update SMS preferences
        $smsNotifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $smsBookingUpdates = isset($_POST['sms_booking_updates']) ? 1 : 0;
        $smsPaymentAlerts = isset($_POST['sms_payment_alerts']) ? 1 : 0;
        $smsMaintenanceUpdates = isset($_POST['sms_maintenance_updates']) ? 1 : 0;
        $smsAnnouncements = isset($_POST['sms_announcements']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET sms_notifications = ?, 
                    sms_booking_updates = ?, 
                    sms_payment_alerts = ?, 
                    sms_maintenance_updates = ?, 
                    sms_announcements = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $smsNotifications,
                $smsBookingUpdates,
                $smsPaymentAlerts,
                $smsMaintenanceUpdates,
                $smsAnnouncements,
                $userId
            ]);
            
            $message = 'SMS preferences updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating preferences: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_phone'])) {
        // Update phone number
        $phoneNumber = trim($_POST['phone_number']);
        
        // Validate phone number format
        if (empty($phoneNumber)) {
            $message = 'Phone number is required for SMS notifications.';
            $messageType = 'error';
        } else {
            // Basic validation for Ghana phone numbers
            if (preg_match('/^0[0-9]{9}$/', $phoneNumber) || 
                preg_match('/^233[0-9]{9}$/', $phoneNumber) || 
                preg_match('/^\+233[0-9]{9}$/', $phoneNumber)) {
                
                try {
                    $stmt = $pdo->prepare("UPDATE users SET phone_number = ? WHERE id = ?");
                    $stmt->execute([$phoneNumber, $userId]);
                    
                    $message = 'Phone number updated successfully!';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'Error updating phone number: ' . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = 'Please enter a valid Ghana phone number (e.g., 0244123456, 233244123456, or +233244123456).';
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['send_test_sms'])) {
        // Send test SMS
        $smsService = new SMSService();
        $userStmt = $pdo->prepare("SELECT phone_number FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userPhone = $userStmt->fetchColumn();
        
        if ($userPhone) {
            $testMessage = "Test SMS from Landlords&Tenants accommodation system. Your SMS notifications are working correctly!";
            $success = $smsService->sendSMS($userPhone, $testMessage);
            
            if ($success) {
                $message = 'Test SMS sent successfully! Check your phone.';
                $messageType = 'success';
            } else {
                $message = 'Failed to send test SMS. Please check your phone number and try again.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please add a phone number first before testing SMS.';
            $messageType = 'error';
        }
    }
}

// Get current user data
$stmt = $pdo->prepare("
    SELECT phone_number, sms_notifications, sms_booking_updates, 
           sms_payment_alerts, sms_maintenance_updates, sms_announcements,
           profile_picture
    FROM users 
    WHERE id = ?
");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

// Get SMS statistics
$smsService = new SMSService();
$smsStats = $smsService->getSMSStats($userId);

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../../assets/images/default-avatar.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($userData['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Settings - Landlords&Tenants</title>
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
            background-color: #f5f7fa;
            color: var(--secondary-color);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .dashboard-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: var(--secondary-color);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: all var(--transition-speed) ease;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--header-height);
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            white-space: nowrap;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 15px 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .sidebar-menu li a:hover, 
        .sidebar-menu li a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .sidebar-menu li a i {
            min-width: 30px;
            text-align: center;
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .menu-text {
            transition: opacity var(--transition-speed);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .menu-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar.collapsed .sidebar-header h2 {
            display: none;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin var(--transition-speed) ease;
            min-height: 100vh;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Header Styles */
        .main-header {
            background: white;
            box-shadow: var(--box-shadow);
            height: var(--header-height);
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
            height: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--secondary-color);
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo img {
            height: 40px;
            margin-right: 10px;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--secondary-color);
            cursor: pointer;
        }

        .user-controls {
            position: relative;
        }

        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-profile span {
            margin-left: 10px;
            font-weight: 500;
        }

        .dropdown-menu {
            position: absolute;
            right: 0;
            top: 50px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            min-width: 200px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }

        .dropdown:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-menu li {
            list-style: none;
        }

        .dropdown-menu a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: var(--secondary-color);
            transition: all 0.3s;
        }

        .dropdown-menu a:hover {
            background: var(--light-color);
        }

        .dropdown-divider {
            height: 1px;
            background: rgba(0,0,0,0.1);
            margin: 5px 0;
        }

        /* Content Styles */
        .content-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            color: var(--secondary-color);
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .form-check-input {
            margin-right: 10px;
            transform: scale(1.2);
        }

        .form-check-label {
            font-weight: 500;
            cursor: pointer;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
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

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .help-text {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar .menu-text {
                opacity: 0;
                width: 0;
                overflow: hidden;
            }
            
            .sidebar .sidebar-header h2 {
                display: none;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .sidebar.collapsed {
                width: var(--sidebar-width);
            }
            
            .sidebar.collapsed .menu-text {
                opacity: 1;
                width: auto;
            }
            
            .sidebar.collapsed .sidebar-header h2 {
                display: block;
            }
            
            .sidebar.collapsed ~ .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1100;
            }
            
            .sidebar.collapsed {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .header-container {
                padding: 0 15px;
            }
            
            .logo span {
                display: none;
            }
            
            .user-profile span {
                display: none;
            }
            
            .dropdown-menu {
                right: -20px;
                min-width: 180px;
            }
            
            .content-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Tenant Dashboard</h2>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../search/"><i class="fas fa-search"></i> <span class="menu-text">Find Accommodation</span></a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">My Bookings</span></a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../messages/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="../reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="../profile/"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                    <li><a href="../notification/"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
                    <li><a href="#" class="active"><i class="fas fa-sms"></i> <span class="menu-text">SMS Settings</span></a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="main-header">
                <div class="header-container">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <a href="../dashboard.php" class="logo">
                        <img src="../../assets/images/logo-removebg-preview.png" alt="Logo">
                        <span>Landlords&Tenant</span>
                    </a>
                    
                    <div class="user-controls">
                        <div class="dropdown">
                            <div class="user-profile dropdown-toggle">
                                <?php if ($profile_pic_path): ?>
                                    <img src="<?= $profile_pic_path ?>" alt="User Profile">
                                <?php else: ?>
                                    <div class="notification-icon">
                                        <?= substr($username, 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="d-none d-md-inline ms-2"><?= $username ?></span>
                            </div>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="../profile/"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><a class="dropdown-item" href="../settings/"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="../../auth/logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- SMS Settings Content -->
            <div class="content-container">
                <h1 class="section-title">SMS Notification Settings</h1>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Phone Number Settings -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-phone"></i>
                            Phone Number
                        </h3>
                    </div>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label" for="phone_number">Phone Number</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="phone_number" 
                                   name="phone_number" 
                                   value="<?= htmlspecialchars($userData['phone_number'] ?? '') ?>"
                                   placeholder="e.g., 0244123456, 233244123456, or +233244123456">
                            <div class="help-text">
                                Enter your Ghana phone number to receive SMS notifications. Supported formats: 0XXXXXXXXX, 233XXXXXXXXX, or +233XXXXXXXXX
                            </div>
                        </div>
                        
                        <button type="submit" name="update_phone" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Phone Number
                        </button>
                        
                        <?php if (!empty($userData['phone_number'])): ?>
                            <button type="submit" name="send_test_sms" class="btn btn-warning" style="margin-left: 10px;">
                                <i class="fas fa-paper-plane"></i> Send Test SMS
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- SMS Preferences -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-cog"></i>
                            SMS Notification Preferences
                        </h3>
                    </div>
                    
                    <form method="POST">
                        <div class="form-check">
                            <input type="checkbox" 
                                   class="form-check-input" 
                                   id="sms_notifications" 
                                   name="sms_notifications"
                                   <?= ($userData['sms_notifications'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_notifications">
                                Enable SMS Notifications
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" 
                                   class="form-check-input" 
                                   id="sms_booking_updates" 
                                   name="sms_booking_updates"
                                   <?= ($userData['sms_booking_updates'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_booking_updates">
                                Booking Updates (confirmations, rejections, cancellations)
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" 
                                   class="form-check-input" 
                                   id="sms_payment_alerts" 
                                   name="sms_payment_alerts"
                                   <?= ($userData['sms_payment_alerts'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_payment_alerts">
                                Payment Alerts (confirmations, reminders, failures)
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" 
                                   class="form-check-input" 
                                   id="sms_maintenance_updates" 
                                   name="sms_maintenance_updates"
                                   <?= ($userData['sms_maintenance_updates'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_maintenance_updates">
                                Maintenance Updates (request status, completion notifications)
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" 
                                   class="form-check-input" 
                                   id="sms_announcements" 
                                   name="sms_announcements"
                                   <?= ($userData['sms_announcements'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_announcements">
                                System Announcements (important updates, policy changes)
                            </label>
                        </div>
                        
                        <button type="submit" name="update_preferences" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </form>
                </div>
                
                <!-- SMS Statistics -->
                <?php if (!empty($smsStats)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar"></i>
                            SMS Statistics
                        </h3>
                    </div>
                    
                    <div class="stats-grid">
                        <?php
                        $totalSent = 0;
                        $totalDelivered = 0;
                        $totalFailed = 0;
                        
                        foreach ($smsStats as $stat) {
                            switch ($stat['status']) {
                                case 'sent':
                                    $totalSent += $stat['count'];
                                    break;
                                case 'delivered':
                                    $totalDelivered += $stat['count'];
                                    break;
                                case 'failed':
                                case 'error':
                                    $totalFailed += $stat['count'];
                                    break;
                            }
                        }
                        ?>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?= $totalSent ?></div>
                            <div class="stat-label">SMS Sent</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?= $totalDelivered ?></div>
                            <div class="stat-label">SMS Delivered</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?= $totalFailed ?></div>
                            <div class="stat-label">SMS Failed</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?= $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100) : 0 ?>%</div>
                            <div class="stat-label">Delivery Rate</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Information Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            SMS Information
                        </h3>
                    </div>
                    
                    <div style="line-height: 1.8;">
                        <p><strong>SMS Delivery:</strong> SMS notifications are sent automatically when important events occur in your account.</p>
                        <p><strong>Message Format:</strong> Messages are limited to 160 characters and will be automatically shortened if needed.</p>
                        <p><strong>Delivery Reports:</strong> We track delivery status to ensure you receive important notifications.</p>
                        <p><strong>Privacy:</strong> Your phone number is only used for SMS notifications and is never shared with third parties.</p>
                        <p><strong>Support:</strong> If you're not receiving SMS notifications, please check your phone number format and ensure your device can receive SMS messages.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
        
        // Toggle mobile menu
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    const menu = dropdown.querySelector('.dropdown-menu');
                    if (menu) {
                        menu.style.opacity = '0';
                        menu.style.visibility = 'hidden';
                        menu.style.transform = 'translateY(10px)';
                    }
                }
            });
        });
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const phoneInput = document.getElementById('phone_number');
            if (phoneInput && phoneInput.value) {
                const phonePattern = /^(0[0-9]{9}|233[0-9]{9}|\+233[0-9]{9})$/;
                if (!phonePattern.test(phoneInput.value.replace(/\s+/g, ''))) {
                    e.preventDefault();
                    alert('Please enter a valid Ghana phone number format.');
                    phoneInput.focus();
                }
            }
        });
    </script>
</body>
</html>
