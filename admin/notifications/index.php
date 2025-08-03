<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$user_filter = isset($_GET['user']) ? intval($_GET['user']) : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$query = "
    SELECT 
        n.*,
        u.username AS recipient_name,
        u.email AS recipient_email,
        p.property_name,
        a.username AS admin_name,
        py.amount AS payment_amount
    FROM notifications n
    LEFT JOIN users u ON n.user_id = u.id
    LEFT JOIN property p ON n.property_id = p.id
    LEFT JOIN users a ON n.admin_id = a.id
    LEFT JOIN payments py ON n.payment_id = py.id
    WHERE 1=1
";

$params = [];
$types = [];

// Apply filters
if ($status_filter !== 'all') {
    $query .= " AND n.is_read = :status";
    $params[':status'] = ($status_filter === 'read') ? 1 : 0;
    $types[':status'] = PDO::PARAM_INT;
}

if ($type_filter !== 'all') {
    $query .= " AND n.type = :type";
    $params[':type'] = $type_filter;
    $types[':type'] = PDO::PARAM_STR;
}

if ($user_filter > 0) {
    $query .= " AND n.user_id = :user_id";
    $params[':user_id'] = $user_filter;
    $types[':user_id'] = PDO::PARAM_INT;
}

if (!empty($search)) {
    $query .= " AND (n.message LIKE :search OR u.username LIKE :search OR p.property_name LIKE :search)";
    $params[':search'] = "%$search%";
    $types[':search'] = PDO::PARAM_STR;
}

$query .= " ORDER BY n.created_at DESC";

// Prepare and execute query
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, $types[$key]);
}

$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get users for filter dropdown
$user_stmt = $pdo->query("SELECT id, username FROM users ORDER BY username");
$users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_read'])) {
        $notification_id = $_POST['notification_id'];
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $update_stmt->execute([$notification_id]);
        header("Location: index.php");
        exit();
    }
    
    if (isset($_POST['delete'])) {
        $notification_id = $_POST['notification_id'];
        $delete_stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
        $delete_stmt->execute([$notification_id]);
        header("Location: index.php");
        exit();
    }
    
    if (isset($_POST['mark_all_read'])) {
        $update_all_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1");
        $update_all_stmt->execute();
        header("Location: index.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Management - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --sidebar-width: 250px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
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

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background-color: white;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
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

        .btn-info {
            background-color: var(--info-color);
            color: white;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notification-item {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 15px;
            border-left: 4px solid var(--primary-color);
            transition: transform 0.2s;
        }

        .notification-item:hover {
            transform: translateY(-3px);
        }

        .notification-item.unread {
            background-color: #f0f8ff;
            border-left-color: var(--info-color);
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .notification-title {
            font-weight: 600;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-type {
            font-size: 0.8rem;
            padding: 3px 8px;
            border-radius: 12px;
            background-color: #e0f7fa;
            color: #00796b;
        }

        .notification-time {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .notification-content {
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .notification-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .notification-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
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

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-unread {
            background-color: #cce5ff;
            color: #004085;
        }

        .badge-read {
            background-color: #d4edda;
            color: #155724;
        }

        .badge-payment {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .badge-booking {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-system {
            background-color: #e2e3e5;
            color: #383d41;
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
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .notification-header {
                flex-direction: column;
                gap: 10px;
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
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Hostel Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../users/"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> Property Management</a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-bell"></i> Notifications</a></li>
                    <li><a href="../reports/financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="../reports/occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li>
                        <form action="../logout.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                         <button type="submit" class="dropdown-item">
                           <i class="fas fa-sign-out-alt "></i> Logout
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
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile" class="user-avatar">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Notification Management</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Notifications</li>
                </ul>
            </div>

            <!-- Filters Section -->
            <div class="card">
                <div class="card-header">
                    <h2>Filter Notifications</h2>
                </div>
                <div class="card-body">
                    <form method="GET" class="filters">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="unread" <?= $status_filter === 'unread' ? 'selected' : '' ?>>Unread</option>
                                <option value="read" <?= $status_filter === 'read' ? 'selected' : '' ?>>Read</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Type</label>
                            <select name="type" class="filter-select">
                                <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="payment_received" <?= $type_filter === 'payment_received' ? 'selected' : '' ?>>Payment Received</option>
                                <option value="booking_update" <?= $type_filter === 'booking_update' ? 'selected' : '' ?>>Booking Update</option>
                                <option value="system_alert" <?= $type_filter === 'system_alert' ? 'selected' : '' ?>>System Alert</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">User</label>
                            <select name="user" class="filter-select">
                                <option value="0">All Users</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>" <?= $user_filter == $user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search messages..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="index.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notifications Section -->
            <div class="card">
                <div class="card-header">
                    <h2>All Notifications</h2>
                    <div class="action-buttons">
                        <form method="POST">
                            <button type="submit" name="mark_all_read" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Mark All as Read
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h3>No Notifications Found</h3>
                            <p>There are no notifications matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="notification-list">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                                    <div class="notification-header">
                                        <div class="notification-title">
                                            <span class="notification-type">
                                                <?php 
                                                    $type_class = '';
                                                    switch ($notification['type']) {
                                                        case 'payment_received': 
                                                            $type_class = 'badge-payment';
                                                            echo '<i class="fas fa-money-bill-wave"></i> Payment';
                                                            break;
                                                        case 'booking_update': 
                                                            $type_class = 'badge-booking';
                                                            echo '<i class="fas fa-calendar-check"></i> Booking';
                                                            break;
                                                        case 'system_alert': 
                                                            $type_class = 'badge-system';
                                                            echo '<i class="fas fa-exclamation-triangle"></i> System';
                                                            break;
                                                    }
                                                ?>
                                            </span>
                                            <?= htmlspecialchars($notification['message']) ?>
                                        </div>
                                        <div class="notification-time">
                                            <i class="fas fa-clock"></i> 
                                            <?= date('M j, Y H:i', strtotime($notification['created_at'])) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="notification-content">
                                        <?php if ($notification['type'] === 'payment_received' && $notification['payment_amount']): ?>
                                            <p>
                                                <strong>Payment Amount:</strong> GHS <?= number_format($notification['payment_amount'], 2) ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($notification['property_name']): ?>
                                            <p>
                                                <strong>Property:</strong> <?= htmlspecialchars($notification['property_name']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="notification-meta">
                                        <div class="notification-meta-item">
                                            <i class="fas fa-user"></i>
                                            <strong>Recipient:</strong> 
                                            <?php if ($notification['recipient_name']): ?>
                                                <?= htmlspecialchars($notification['recipient_name']) ?>
                                            <?php else: ?>
                                                System Notification
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="notification-meta-item">
                                            <i class="fas fa-envelope"></i>
                                            <strong>Channel:</strong> 
                                            <?= ucfirst(str_replace('_', ' ', $notification['notification_type'])) ?>
                                        </div>
                                        
                                        <div class="notification-meta-item">
                                            <span class="status-badge <?= $notification['is_read'] ? 'badge-read' : 'badge-unread' ?>">
                                                <?= $notification['is_read'] ? 'Read' : 'Unread' ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($notification['admin_name']): ?>
                                            <div class="notification-meta-item">
                                                <i class="fas fa-user-shield"></i>
                                                <strong>Admin:</strong> 
                                                <?= htmlspecialchars($notification['admin_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="notification-actions">
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                                <button type="submit" name="mark_read" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i> Mark as Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                            <button type="submit" name="delete" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this notification?');">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                        
                                        <?php if ($notification['property_id']): ?>
                                            <a href="../properties/view.php?id=<?= $notification['property_id'] ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-home"></i> View Property
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
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
    </script>
</body>
</html>