<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$userStatus = $_SESSION['status'] ?? 'student';

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../assets/images/default-avatar.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

// Database connection
$pdo = Database::getInstance();

// Fetch user's profile picture
$profileStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
$profileStmt->execute([$userId]);
$profileData = $profileStmt->fetch(PDO::FETCH_ASSOC);
$profile_pic_path = getProfilePicturePath($profileData['profile_picture'] ?? '');

// Get filter parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Fetch announcements from database
$sql = "SELECT a.*, u.username AS sender_name 
        FROM announcements a
        JOIN users u ON a.sender_id = u.id
        WHERE 1=1";

// Apply filters
switch($filter) {
    case 'admin':
        $sql .= " AND a.target_group = 'admin'";
        break;
    case 'owner':
        $sql .= " AND a.target_group = 'property_owners'";
        break;
    case 'urgent':
        $sql .= " AND a.is_urgent = 1";
        break;
}

// Add user-specific conditions only for non-admin and non-owner filters
if ($filter !== 'admin' && $filter !== 'owner') {
    $sql .= " AND (a.target_group = 'all' 
                OR a.target_group = :user_status
                OR (a.target_group = 'specific' 
                    AND EXISTS (SELECT 1 FROM announcement_recipients ar 
                                WHERE ar.announcement_id = a.id 
                                AND ar.user_id = :user_id)))";
}

$sql .= " ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($sql);

// Bind parameters only for non-admin and non-owner filters
if ($filter !== 'admin' && $filter !== 'owner') {
    $stmt->bindParam(':user_status', $userStatus);
    $stmt->bindParam(':user_id', $userId);
}

$stmt->execute();

$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Landlords&Tenants</title>
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

        /* Announcements Content Styles */
        .content-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .announcements-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-title {
            font-size: 1.8rem;
            color: var(--secondary-color);
            margin: 0;
        }

        .filter-controls {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 8px 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .filter-btn:hover {
            background: var(--light-color);
        }

        .announcement-list {
            display: grid;
            gap: 20px;
        }

        .announcement-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            transition: transform 0.3s ease;
            border-left: 4px solid var(--primary-color);
            position: relative;
        }

        .announcement-card.urgent {
            border-left-color: var(--accent-color);
            background: linear-gradient(to right, rgba(231, 76, 60, 0.05), white);
        }

        .announcement-card.admin {
            border-left-color: var(--info-color);
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .announcement-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin-right: 15px;
        }

        .announcement-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            min-width: 120px;
        }

        .announcement-sender {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .announcement-date {
            font-size: 0.85rem;
            color: #777;
        }

        .announcement-body {
            margin-bottom: 15px;
            color: #555;
            line-height: 1.7;
        }

        .announcement-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tag {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .tag-urgent {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--accent-color);
        }

        .tag-admin {
            background-color: rgba(23, 162, 184, 0.15);
            color: var(--info-color);
        }

        .tag-owner {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--primary-color);
        }

        .no-announcements {
            text-align: center;
            padding: 40px 20px;
            color: #777;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
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
            
            .announcements-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-controls {
                width: 100%;
                justify-content: space-between;
            }
            
            .filter-btn {
                flex: 1;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 15px;
            }
            
            .announcement-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .announcement-meta {
                align-items: flex-start;
            }
            
            .announcement-title {
                font-size: 1.1rem;
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
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="search/"><i class="fas fa-search"></i> <span class="menu-text">Find Accommodation</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">My Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="profile/"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                    <li><a href="notification/"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
                    <li><a href="#" class="active"><i class="fas fa-bullhorn"></i> <span class="menu-text">Announcements</span></a></li>
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
                    
                    <a href="../../" class="logo">
                        <img src="../assets/images/landlords-logo.png" alt="Logo">
                        <span>Landlords&Tenants</span>
                    </a>
                    
                    <div class="user-controls">
                        <div class="dropdown">
                            <div class="user-profile dropdown-toggle">
                                <?php if ($profile_pic_path): ?>
                                    <img src="<?= $profile_pic_path ?>" alt="User Profile">
                                <?php else: ?>
                                    <div class="notification-icon" style="background-color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white;">
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
            
            <!-- Announcements Content -->
            <div class="content-container">
                <div class="announcements-header">
                    <h1 class="section-title">Important Announcements</h1>
                    <div class="filter-controls">
                        <a href="?filter=all" 
                           class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>" 
                           data-filter="all">All</a>
                        <a href="?filter=admin" 
                           class="filter-btn <?= $filter === 'admin' ? 'active' : '' ?>" 
                           data-filter="admin">Admin</a>
                        <a href="?filter=owner" 
                           class="filter-btn <?= $filter === 'owner' ? 'active' : '' ?>" 
                           data-filter="owner">Property Owners</a>
                        <a href="?filter=urgent" 
                           class="filter-btn <?= $filter === 'urgent' ? 'active' : '' ?>" 
                           data-filter="urgent">Urgent</a>
                    </div>
                </div>
                
                <div class="announcement-list">
                    <?php if (count($announcements) > 0): ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card 
                                <?= $announcement['is_urgent'] ? 'urgent' : '' ?>
                                <?= $announcement['target_group'] === 'admin' ? 'admin' : '' ?>">
                                <div class="announcement-header">
                                    <h2 class="announcement-title"><?= htmlspecialchars($announcement['title']) ?></h2>
                                    <div class="announcement-meta">
                                        <span class="announcement-sender"><?= htmlspecialchars($announcement['sender_name']) ?></span>
                                        <span class="announcement-date">
                                            <?= date('F j, Y', strtotime($announcement['created_at'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="announcement-body">
                                    <p><?= nl2br(htmlspecialchars($announcement['message'])) ?></p>
                                </div>
                                <div class="announcement-tags">
                                    <?php if ($announcement['is_urgent']): ?>
                                        <span class="tag tag-urgent">Urgent</span>
                                    <?php endif; ?>
                                    <?php if ($announcement['target_group'] === 'admin'): ?>
                                        <span class="tag tag-admin">Admin</span>
                                    <?php elseif ($announcement['target_group'] === 'property_owners'): ?>
                                        <span class="tag tag-owner">Property Owner</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-announcements">
                            <i class="far fa-bullhorn fa-3x mb-3" style="color: #ddd;"></i>
                            <h2>No announcements yet</h2>
                            <p>Important updates from property owners and administrators will appear here</p>
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