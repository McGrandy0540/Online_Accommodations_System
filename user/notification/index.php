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

// Database connection
$pdo = Database::getInstance();

// Add delivered column if not exists (one-time operation)
try {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS delivered TINYINT(1) DEFAULT 0");
} catch (PDOException $e) {
    error_log("Notification table update error: " . $e->getMessage());
}

// Initialize Notification Service
require_once __DIR__ . '../../../includes/NotificationService.php';
$notificationService = new NotificationService();

// Process undelivered notifications and send SMS when student views notifications
// This is the new flow: SMS is sent when student accesses their notification portal
$smsResults = $notificationService->processPendingSMSForUser($userId);

// Log SMS processing results for debugging
if ($smsResults['processed'] > 0) {
    error_log("SMS Processing for User $userId: " . 
              "Processed: {$smsResults['processed']}, " .
              "Success: {$smsResults['success']}, " .
              "Failed: {$smsResults['failed']}");
}

// Mark all notifications as read when page loads
$updateStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$updateStmt->execute([$userId]);

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT n.*, p.property_name 
    FROM notifications n
    LEFT JOIN property p ON n.property_id = p.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to format time difference
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $currentTime = time();
    $timeDiff = $currentTime - $time;

    if ($timeDiff < 60) {
        return 'Just now';
    } elseif ($timeDiff < 3600) {
        $mins = floor($timeDiff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        $days = floor($timeDiff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
}

// Fetch user's profile picture
$profileStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
$profileStmt->execute([$userId]);
$profileData = $profileStmt->fetch(PDO::FETCH_ASSOC);
$profile_pic_path = getProfilePicturePath($profileData['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Landlords&Tenants</title>
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
            height: 80px;
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

        /* Notification Content Styles */
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

        .notification-list {
            display: grid;
            gap: 15px;
        }

        .notification-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
            display: flex;
            gap: 15px;
            transition: transform 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }

        .notification-card.unread {
            background-color: rgba(52, 152, 219, 0.05);
            border-left-color: var(--accent-color);
        }

        .notification-icon {
            min-width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }

        .notification-type {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 20px;
            background-color: var(--info-color);
            color: white;
        }

        .notification-message {
            margin-bottom: 8px;
            color: #555;
        }

        .notification-property {
            font-style: italic;
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        .notification-time {
            font-size: 0.85rem;
            color: #777;
        }

        .no-notifications {
            text-align: center;
            padding: 40px 20px;
            color: #777;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }

        .mark-all-read {
            display: block;
            margin-left: auto;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            margin-bottom: 20px;
            transition: background 0.3s;
        }

        .mark-all-read:hover {
            background: var(--primary-hover);
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
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 15px;
            }
            
            .notification-card {
                flex-direction: column;
                gap: 10px;
            }
            
            .notification-icon {
                align-self: flex-start;
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
                    <li><a href="#" class="active"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
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
                    
                    <a href="../dashbord.php" class="logo">
                        <img src="../../assets/images/landlords-logo2.png" alt="Logo">
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
            
            <!-- Notification Content -->
            <div class="content-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 class="section-title">Notifications</h1>
                    <button class="mark-all-read" id="markAllReadBtn">
                        <i class="fas fa-check-circle me-2"></i>Mark All as Read
                    </button>
                </div>
                
                <div class="notification-list">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-card <?= $notification['is_read'] ? '' : 'unread' ?>" data-id="<?= $notification['id'] ?>">
                                <div class="notification-icon">
                                    <?php 
                                    $icon = 'fas fa-bell';
                                    switch ($notification['type']) {
                                        case 'payment_received':
                                            $icon = 'fas fa-wallet';
                                            break;
                                        case 'booking_update':
                                            $icon = 'fas fa-calendar-alt';
                                            break;
                                        case 'system_alert':
                                            $icon = 'fas fa-exclamation-circle';
                                            break;
                                        case 'maintenance':
                                            $icon = 'fas fa-tools';
                                            break;
                                        case 'announcement':
                                            $icon = 'fas fa-bullhorn';
                                            break;
                                        default:
                                            $icon = 'fas fa-bell';
                                    }
                                    ?>
                                    <i class="<?= $icon ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">
                                        <span><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $notification['type']))) ?></span>
                                        <span class="notification-type">
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $notification['type']))) ?>
                                        </span>
                                    </div>
                                    <p class="notification-message">
                                        <?= htmlspecialchars($notification['message']) ?>
                                    </p>
                                    <?php if (!empty($notification['property_name'])): ?>
                                        <p class="notification-property">
                                            Property: <?= htmlspecialchars($notification['property_name']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <p class="notification-time">
                                        <?= timeAgo($notification['created_at']) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-notifications">
                            <i class="far fa-bell fa-3x mb-3" style="color: #ddd;"></i>
                            <h2>No notifications yet</h2>
                            <p>You'll see important updates here</p>
                        </div>
                    <?php endif; ?>
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
        
        // Mark all notifications as read
        document.getElementById('markAllReadBtn').addEventListener('click', function() {
            // Remove unread class from all notifications
            document.querySelectorAll('.notification-card.unread').forEach(card => {
                card.classList.remove('unread');
            });
            
            // Disable button and change text
            this.textContent = 'All notifications marked as read';
            this.disabled = true;
            
            // Send AJAX request to mark all as read in database
            fetch('mark_all_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: <?= $userId ?> })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Error marking notifications as read');
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    const menu = dropdown.querySelector('.dropdown-menu');
                    menu.style.opacity = '0';
                    menu.style.visibility = 'hidden';
                    menu.style.transform = 'translateY(10px)';
                }
            });
        });
    </script>
</body>
</html>
