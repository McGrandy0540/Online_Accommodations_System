<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get owner stats
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

// Get property count
$properties = $pdo->prepare("SELECT COUNT(*) FROM property_owners WHERE owner_id = ?");
$properties->execute([$owner_id]);
$property_count = $properties->fetchColumn();

// Get pending bookings
$bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings b 
                          JOIN property p ON b.property_id = p.id 
                          JOIN property_owners po ON p.id = po.property_id
                          WHERE po.owner_id = ? AND b.status = 'pending'");
$bookings->execute([$owner_id]);
$booking_count = $bookings->fetchColumn();

// Get recent payments
$payments = $pdo->prepare("SELECT SUM(py.amount) FROM payments py
                          JOIN bookings b ON py.booking_id = b.id
                          JOIN property p ON b.property_id = p.id
                          JOIN property_owners po ON p.id = po.property_id
                          WHERE po.owner_id = ? AND py.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$payments->execute([$owner_id]);
$revenue = $payments->fetchColumn() ?? 0;

// Get unread messages
$messages = $pdo->prepare("SELECT COUNT(*) FROM chat_messages cm
                          JOIN chat_conversations cc ON cm.conversation_id = cc.id
                          WHERE (cc.owner_id = ? AND cm.sender_id != ?) AND cm.is_read = 0");
$messages->execute([$owner_id, $owner_id]);
$unread_messages = $messages->fetchColumn();

// Get maintenance requests
$maintenance = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr
                             JOIN property p ON mr.property_id = p.id
                             JOIN property_owners po ON p.id = po.property_id
                             WHERE po.owner_id = ? AND mr.status = 'pending'");
$maintenance->execute([$owner_id]);
$maintenance_count = $maintenance->fetchColumn();

// Get recent notifications
$notifications = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$notifications->execute([$owner_id]);
$unread_notifications_count = $notifications->fetchColumn();



// Get profile picture path
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard | Landlords&Tenant</title>
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
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .left-column {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .right-column {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Dashboard Content */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            grid-column: 1 / -1;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            grid-column: 1 / -1;
        }

        .stat-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            transition: transform var(--transition-speed);
            border: none;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            font-size: 0.9rem;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .stat-card p {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0;
        }

        .stat-card i {
            position: absolute;
            right: 1.25rem;
            top: 1.25rem;
            font-size: 2.5rem;
            opacity: 0.1;
            color: var(--primary-color);
        }

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            background-color: #fff3cd;
            color: #856404;
            border: none;
            grid-column: 1 / -1;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }

        .alert a {
            color: #856404;
            text-decoration: underline;
            margin-left: 0.5rem;
            font-weight: 500;
        }

        /* Recent Activity */
        .recent-activity {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.25rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .recent-activity h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .activity-list {
            display: grid;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(52, 152, 219, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: var(--primary-color);
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-content p {
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-content small {
            color: #6c757d;
            font-size: 0.8rem;
        }

        /* Quick Actions */
        .quick-actions {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.25rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .quick-actions h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: all var(--transition-speed);
            border: none;
            font-weight: 500;
        }

        .action-btn:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
        }

        .action-btn i {
            margin-right: 0.5rem;
        }

        /* Virtual Tour Promo */
        .virtual-tour-promo {
            background-color: var(--secondary-color);
            color: white;
            border-radius: var(--border-radius);
            padding: 1.75rem;
            text-align: center;
            box-shadow: var(--card-shadow);
            background: linear-gradient(135deg, var(--secondary-color) 0%, #1a2533 100%);
        }

        .virtual-tour-promo h2 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .virtual-tour-promo p {
            margin-bottom: 1.5rem;
            opacity: 0.9;
            font-size: 0.95rem;
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

        .btn i {
            margin-right: 0.5rem;
        }

        /* Profile Card */
        .profile-card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: none;
        }

        .profile-card-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1rem;
            font-weight: 600;
        }

        .profile-card-body {
            padding: 1.5rem;
            text-align: center;
        }

        .profile-avatar-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--card-shadow);
        }

        .profile-avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            border: 3px solid white;
            box-shadow: var(--card-shadow);
        }

        .profile-info {
            margin-bottom: 1.5rem;
        }

        .profile-info h4 {
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .profile-info p {
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .profile-info .location {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-color);
            font-size: 0.95rem;
        }

        .profile-info .location i {
            margin-right: 0.5rem;
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
            .main-content-wrapper {
                grid-template-columns: 1fr;
            }
            
            .left-column {
                order: 2;
            }
            
            .right-column {
                order: 1;
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
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .action-buttons {
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
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1.25rem;
            }

            .profile-avatar, .profile-avatar-placeholder {
                width: 90px;
                height: 90px;
                font-size: 2rem;
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
            <a href="../" class="logo">
                <img src="../assets/images/landlords-logo.png" alt="landlords&tenants Logo">
                <span>Landlords&Tenants</span>
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
            <div class="sidebar-header">
                <h2>Owner Dashboard</h2>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="property_dashboard.php"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="virtual-tours/"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                    <li><a href="announcement.php" class="active"><i class="fa-solid fa-bullhorn"></i> <span class="menu-text"> Announcements</span></a></li>
                    <li><a href="notification/"><i class="fa-solid fa-bell"></i> <span class="menu-text"> Notification</span> </a></li>
                    <li><a href="reports/"><i class="fa-solid fa-flag"></i> <span class="menu-text"> Reports</span> </a></li>
                    <li><a href="uploadfile.php"><i class="fa-solid fa-file"></i> <span class="menu-text"> Document Uploads</span> </a></li>
                    <li><a href="tenancy_agreement_uploads.php"><i class="fa-solid fa-file-upload"></i> <span class="menu-text"> Tenancy Agreement Upload</span> </a></li>
                    <li><a href="Tenants_verified_document.php"><i class="fa-solid fa-file"></i> <span class="menu-text"> Tenancy Verification Doc</span> </a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Welcome back, <?= htmlspecialchars(explode(' ', $owner['username'])[0]) ?>!</h1>
                <button class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="badge"><?= $unread_notifications_count ?></span>
                </button>
            </div>

            <div class="main-content-wrapper">
                <div class="left-column">
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Owner Profile</h5>
                        </div>
                        <div class="profile-card-body">
                            <div class="profile-avatar-container">
                                <?php if (!empty($profile_pic_path)): ?>
                                    <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar" alt="Profile Picture">
                                <?php else: ?>
                                    <div class="profile-avatar-placeholder">
                                        <?= substr($owner['username'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="profile-info">
                                <h4><?= htmlspecialchars($owner['username']) ?></h4>
                                <p class="text-muted"><?= htmlspecialchars($owner['email']) ?></p>
                                <?php if (!empty($owner['phone_number'])): ?>
                                    <p class="text-muted"><?= htmlspecialchars($owner['phone_number']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($owner['location'])): ?>
                                    <p class="location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($owner['location']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <a href="profile.php" class="btn btn-outline w-100">
                                <i class="fas fa-edit me-2"></i>Edit Profile
                            </a>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h2>Quick Actions</h2>
                        <div class="action-buttons">
                            <a href="add.php" class="action-btn">
                                <i class="fas fa-plus"></i> Add Property
                            </a>
                            <a href="bookings/" class="action-btn">
                                <i class="fas fa-calendar"></i> Manage Bookings
                            </a>
                            <a href="chat/" class="action-btn">
                                <i class="fas fa-comments"></i> View Messages
                            </a>
                        </div>
                    </div>
                </div>

                <div class="right-column">
                    <!-- Stats Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Properties</h3>
                            <p><?= $property_count ?></p>
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="stat-card">
                            <h3>Pending Bookings</h3>
                            <p><?= $booking_count ?></p>
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-card">
                            <h3>30-Day Revenue</h3>
                            <p>GHS<?= number_format($revenue, 2) ?></p>
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-card">
                            <h3>Maintenance</h3>
                            <p><?= $maintenance_count ?></p>
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>

                    <!-- Recent Activity Section -->
                    <div class="card recent-activity">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Recent Activity</h5>
                        </div>
                        <div class="card-body">
                            <div class="activity-list">
                                <?php if (!empty($recent_notifications)): ?>
                                    <?php foreach($recent_notifications as $notification): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-<?= $notification['type'] === 'payment' ? 'dollar-sign' : 'calendar-alt' ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <p><?= htmlspecialchars($notification['message']) ?></p>
                                            <small><?= date('M j, Y g:i a', strtotime($notification['created_at'])) ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No recent activity</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Virtual Tour Section -->
                    <div class="virtual-tour-promo">
                        <h2>Virtual Tours</h2>
                        <p>Enhance your listings with 360° virtual tours and attract more tenants</p>
                        <a href="virtual-tours/upload.php" class="btn">
                            <i class="fas fa-video"></i> Upload Virtual Tour
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-column">
                <h3>About Landlords&Tenants</h3>
                <p>Providing quality accommodation with modern amenities and secure living spaces.</p>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="../">Home</a></li>
                    <li><a href="../properties/">Properties</a></li>
                    <li><a href="../about/">About Us</a></li>
                    <li><a href="../contact/">Contact</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support</h3>
                <ul>
                    <li><a href="../faq/">FAQ</a></li>
                    <li><a href="../help/">Help Center</a></li>
                    <li><a href="../privacy/">Privacy Policy</a></li>
                    <li><a href="../terms/">Terms of Service</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Contact Us</h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt me-2"></i> Koforidua Technical University Avenue </li>
                    <li><i class="fas fa-phone me-2"></i> +233 240687599</li>
                    <li><i class="fas fa-envelope me-2"></i>godwinaboade5432109876@gmail.com</li>
                </ul>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            
            <div class="copyright">
                &copy; <?= date('Y') ?> Landlords&Tenants. All rights reserved.
            </div>
        </div>
    </footer>

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

        // Simple notification bell interaction
        document.querySelector('.notification-bell').addEventListener('click', function() {
            window.location.href = 'notification/';
        });

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