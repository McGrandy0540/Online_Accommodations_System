<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

$user_id = $_SESSION['user_id'];

// Fetch additional user details from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "Failed to load user data. Please try again later.";
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $error = $e->getMessage();
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

$profile_pic_path = getProfilePicturePath($user['profile_picture'] ?? '');

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        $update_stmt->execute();
        $success_message = "All notifications marked as read!";
    } 
    elseif (isset($_POST['delete_all_read'])) {
        $delete_stmt = $pdo->prepare("DELETE FROM notifications WHERE is_read = 1");
        $delete_stmt->execute();
        $success_message = "All read notifications deleted!";
    }
    elseif (isset($_POST['delete_selected'])) {
        if (!empty($_POST['notification_ids'])) {
            $placeholders = str_repeat('?,', count($_POST['notification_ids']) - 1) . '?';
            $delete_stmt = $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
            $delete_stmt->execute($_POST['notification_ids']);
            $success_message = "Selected notifications deleted!";
        }
    }
}

// Handle single notification actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $notification_id = intval($_GET['id']);
    
    switch ($_GET['action']) {
        case 'mark_read':
            $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $update_stmt->execute([$notification_id]);
            $success_message = "Notification marked as read!";
            break;
            
        case 'mark_unread':
            $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 0 WHERE id = ?");
            $update_stmt->execute([$notification_id]);
            $success_message = "Notification marked as unread!";
            break;
            
        case 'delete':
            $delete_stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
            $delete_stmt->execute([$notification_id]);
            $success_message = "Notification deleted!";
            break;
    }
    
    // Redirect to avoid form resubmission
    header("Location: index.php");
    exit();
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_date = $_GET['date'] ?? 'all';

// Build query with filters
$query = "
    SELECT 
        n.*,
        u.username AS user_name,
        u.profile_picture AS user_avatar,
        p.property_name,
        b.id AS booking_id,
        mr.title AS maintenance_title,
        a.title AS announcement_title,
        py.amount AS payment_amount
    FROM notifications n
    LEFT JOIN users u ON n.user_id = u.id
    LEFT JOIN property p ON n.property_id = p.id
    LEFT JOIN bookings b ON n.booking_id = b.id
    LEFT JOIN maintenance_requests mr ON n.maintenance_id = mr.id
    LEFT JOIN announcements a ON n.announcement_id = a.id
    LEFT JOIN payments py ON n.payment_id = py.id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($filter_type !== 'all') {
    $query .= " AND n.type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    if ($filter_status === 'read') {
        $query .= " AND n.is_read = 1";
    } elseif ($filter_status === 'unread') {
        $query .= " AND n.is_read = 0";
    }
}

if ($filter_date !== 'all') {
    $date_condition = "";
    switch ($filter_date) {
        case 'today':
            $date_condition = "DATE(n.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $date_condition = "DATE(n.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $date_condition = "n.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "n.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
    }
    if ($date_condition) {
        $query .= " AND $date_condition";
    }
}

$query .= " ORDER BY n.created_at DESC";

// Get notifications
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filters
$count_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
    FROM notifications
");
$count_stmt->execute();
$counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

// Get notification types
$types_stmt = $pdo->prepare("SELECT DISTINCT type FROM notifications WHERE type IS NOT NULL ORDER BY type");
$types_stmt->execute();
$notification_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts & Notifications - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 250px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            position: fixed;
            height: 100vh;
            transition: all var(--transition-speed) ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            background-color: rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
        }

        .sidebar-menu li a {
            display: block;
            padding: 12px 20px;
            color: #b8c7ce;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-menu li a:hover, 
        .sidebar-menu li a.active {
            color: white;
            background-color: rgba(0, 0, 0, 0.2);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

        .top-nav {
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title h1 {
            font-size: 24px;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }

        .breadcrumb {
            list-style: none;
            display: flex;
            font-size: 14px;
            color: #6c757d;
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin: 0 10px;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header h2 {
            font-size: 18px;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 20px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background-color: white;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-card.total i { color: var(--primary-color); }
        .stat-card.unread i { color: var(--warning-color); }
        .stat-card.read i { color: var(--success-color); }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: var(--border-radius);
            margin-bottom: 10px;
            transition: all 0.3s;
            background: white;
        }

        .notification-item.unread {
            background-color: #f8f9fa;
            border-left: 4px solid var(--primary-color);
        }

        .notification-item:hover {
            box-shadow: var(--box-shadow);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .notification-icon.booking { background-color: #e3f2fd; color: var(--primary-color); }
        .notification-icon.payment { background-color: #e8f5e8; color: var(--success-color); }
        .notification-icon.maintenance { background-color: #fff3e0; color: var(--warning-color); }
        .notification-icon.system { background-color: #fce4ec; color: var(--accent-color); }
        .notification-icon.announcement { background-color: #f3e5f5; color: #9c27b0; }

        .notification-content {
            flex: 1;
        }

        .notification-message {
            margin-bottom: 5px;
            line-height: 1.4;
        }

        .notification-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .notification-actions {
            display: flex;
            gap: 5px;
            flex-shrink: 0;
        }

        .btn {
            padding: 6px 12px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
            border: none;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 50px;
            margin-bottom: 20px;
            color: #ddd;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }

        .badge-unread {
            background-color: var(--warning-color);
            color: white;
        }

        .badge-type {
            background-color: #e9ecef;
            color: #495057;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .filters {
                grid-template-columns: 1fr;
            }
            
            .notification-item {
                flex-direction: column;
            }
            
            .notification-actions {
                margin-top: 10px;
                align-self: flex-end;
            }
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary-color);
        }

        @media (max-width: 992px) {
            .menu-toggle {
                display: block;
            }
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #eee;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--secondary-color);
        }

        .pagination a:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .pagination .current {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Landlords&Tenant Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../users/"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> Property Management</a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-bell"></i> Alerts & Notifications</a></li>
                    <li><a href="../reports/financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="../reports/occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li>
                        <form action="../logout.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" class="dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="user-profile">
                    <img src="<?= htmlspecialchars($profile_pic_path)?>" alt="User Profile" class="user-avatar">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Alerts & Notifications</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Alerts & Notifications</li>
                </ul>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card total">
                    <i class="fas fa-bell"></i>
                    <div class="stat-number"><?= $counts['total'] ?? 0 ?></div>
                    <div>Total Notifications</div>
                </div>
                <div class="stat-card unread">
                    <i class="fas fa-envelope"></i>
                    <div class="stat-number"><?= $counts['unread'] ?? 0 ?></div>
                    <div>Unread Notifications</div>
                </div>
                <div class="stat-card read">
                    <i class="fas fa-envelope-open"></i>
                    <div class="stat-number"><?= $counts['read_count'] ?? 0 ?></div>
                    <div>Read Notifications</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-filter"></i> Filter Notifications</h2>
                </div>
                <div class="card-body">
                    <form method="GET" class="filters">
                        <div class="filter-group">
                            <label for="type">Notification Type</label>
                            <select id="type" name="type">
                                <option value="all">All Types</option>
                                <?php foreach ($notification_types as $type): ?>
                                    <option value="<?= $type ?>" <?= $filter_type === $type ? 'selected' : '' ?>>
                                        <?= ucwords(str_replace('_', ' ', $type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="unread" <?= $filter_status === 'unread' ? 'selected' : '' ?>>Unread Only</option>
                                <option value="read" <?= $filter_status === 'read' ? 'selected' : '' ?>>Read Only</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="date">Time Period</label>
                            <select id="date" name="date">
                                <option value="all" <?= $filter_date === 'all' ? 'selected' : '' ?>>All Time</option>
                                <option value="today" <?= $filter_date === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="yesterday" <?= $filter_date === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                                <option value="week" <?= $filter_date === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="month" <?= $filter_date === 'month' ? 'selected' : '' ?>>Last 30 Days</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" class="bulk-actions">
                <button type="submit" name="mark_all_read" class="btn btn-success">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
                <button type="submit" name="delete_all_read" class="btn btn-danger" 
                        onclick="return confirm('Are you sure you want to delete all read notifications?')">
                    <i class="fas fa-trash"></i> Delete All Read
                </button>
            </form>

            <!-- Notifications List -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class="fas fa-list"></i> Notifications 
                        <?php if ($counts['unread'] > 0): ?>
                            <span class="badge badge-unread"><?= $counts['unread'] ?> unread</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="card-body">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h3>No Notifications Found</h3>
                            <p>There are no notifications matching your current filters.</p>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="notifications-form">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                                    <div class="notification-icon <?= $notification['type'] ?? 'system' ?>">
                                        <?php
                                        $icon = 'fas fa-bell';
                                        switch ($notification['type']) {
                                            case 'booking_update':
                                            case 'new_booking':
                                                $icon = 'fas fa-calendar-check';
                                                break;
                                            case 'payment_received':
                                            case 'payment_failed':
                                                $icon = 'fas fa-money-bill-wave';
                                                break;
                                            case 'maintenance_update':
                                                $icon = 'fas fa-tools';
                                                break;
                                            case 'announcement':
                                                $icon = 'fas fa-bullhorn';
                                                break;
                                            case 'system_alert':
                                                $icon = 'fas fa-exclamation-triangle';
                                                break;
                                        }
                                        ?>
                                        <i class="<?= $icon ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-message">
                                            <?= htmlspecialchars($notification['message']) ?>
                                            <?php if ($notification['type']): ?>
                                                <span class="badge badge-type"><?= $notification['type'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notification-meta">
                                            <i class="fas fa-clock"></i> 
                                            <?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?>
                                            
                                            <?php if ($notification['user_name']): ?>
                                                • <i class="fas fa-user"></i> <?= htmlspecialchars($notification['user_name']) ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($notification['property_name']): ?>
                                                • <i class="fas fa-home"></i> <?= htmlspecialchars($notification['property_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="notification-actions">
                                        <?php if (!$notification['is_read']): ?>
                                            <a href="?action=mark_read&id=<?= $notification['id'] ?>" 
                                               class="btn btn-success btn-sm" title="Mark as Read">
                                                <i class="fas fa-check"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?action=mark_unread&id=<?= $notification['id'] ?>" 
                                               class="btn btn-outline btn-sm" title="Mark as Unread">
                                                <i class="fas fa-envelope"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="?action=delete&id=<?= $notification['id'] ?>" 
                                           class="btn btn-danger btn-sm" 
                                           title="Delete"
                                           onclick="return confirm('Are you sure you want to delete this notification?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Auto-refresh notifications every 30 seconds
        setInterval(function() {
            // Only refresh if there are unread notifications and no filters applied
            if (window.location.search === '') {
                window.location.reload();
            }
        }, 30000);

        // Mark notification as read when clicked
        document.addEventListener('DOMContentLoaded', function() {
            const notificationItems = document.querySelectorAll('.notification-item.unread');
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (!e.target.closest('.notification-actions')) {
                        const markReadLink = this.querySelector('a[href*="mark_read"]');
                        if (markReadLink) {
                            window.location.href = markReadLink.href;
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>