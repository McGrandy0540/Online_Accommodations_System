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

// Get maintenance history with filters
$where = "WHERE po.owner_id = ?";
$params = [$owner_id];
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$property_filter = $_GET['property'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if ($status_filter) {
    $where .= " AND mr.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter) {
    $where .= " AND mr.priority = ?";
    $params[] = $priority_filter;
}

if ($property_filter) {
    $where .= " AND mr.property_id = ?";
    $params[] = $property_filter;
}

if ($date_from) {
    $where .= " AND DATE(mr.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where .= " AND DATE(mr.created_at) <= ?";
    $params[] = $date_to;
}

$query = "SELECT mr.*, p.property_name, p.id as property_id, 
          u.username as student_name, u.profile_picture as student_photo
          FROM maintenance_requests mr
          JOIN property p ON mr.property_id = p.id
          JOIN property_owners po ON p.id = po.property_id
          JOIN users u ON mr.user_id = u.id
          $where
          ORDER BY mr.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll();

// Get properties for filter dropdown
$properties = $pdo->prepare("SELECT p.id, p.property_name 
                            FROM property p
                            JOIN property_owners po ON p.id = po.property_id
                            WHERE po.owner_id = ? AND p.deleted = 0");
$properties->execute([$owner_id]);
$property_options = $properties->fetchAll();

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
    <title>Maintenance History | UniHomes</title>
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
            max-width: 1200px;
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

        /* Filter Section */
        .filter-section {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-title {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
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
            padding: 0.5rem 1rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: all var(--transition-speed);
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            height: 38px;
            align-self: flex-end;
        }

        .btn:hover {
            background-color: var(--primary-hover);
            color: white;
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

        /* History Table */
        .history-table {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--secondary-color);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-in_progress {
            background-color: #cce5ff;
            color: #004085;
        }

        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }

        .priority-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .priority-emergency {
            background-color: #f8d7da;
            color: #721c24;
        }

        .priority-high {
            background-color: #fff3cd;
            color: #856404;
        }

        .priority-medium {
            background-color: #cce5ff;
            color: #004085;
        }

        .priority-low {
            background-color: #d4edda;
            color: #155724;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.5rem;
        }

        .user-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 0.5rem;
        }

        .action-link {
            color: var(--primary-color);
            text-decoration: none;
            margin-right: 0.75rem;
            font-size: 0.9rem;
            transition: color var(--transition-speed);
        }

        .action-link:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .action-link i {
            margin-right: 0.25rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
        }

        .page-item {
            margin: 0 0.25rem;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: white;
            color: var(--dark-color);
            text-decoration: none;
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all var(--transition-speed);
        }

        .page-link:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .page-item.active .page-link {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
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

            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

            th, td {
                padding: 0.75rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .dashboard-header h1 {
                font-size: 1.4rem;
            }

            table {
                font-size: 0.85rem;
            }

            th, td {
                padding: 0.5rem;
            }

            .action-link {
                display: block;
                margin-bottom: 0.5rem;
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
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="../maintenance/" class="active"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="../virtual-tours/"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="../settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="main-content-wrapper">
                <div class="dashboard-header">
                    <h1>Maintenance History</h1>
                    <button class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <span class="badge"><?= $unread_messages ?></span>
                    </button>
                </div>

                <div class="filter-section">
                    <h3 class="filter-title">Filter Requests</h3>
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-control" id="priority" name="priority">
                                <option value="">All Priorities</option>
                                <option value="emergency" <?= $priority_filter === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                                <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                                <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                                <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="property" class="form-label">Property</label>
                            <select class="form-control" id="property" name="property">
                                <option value="">All Properties</option>
                                <?php foreach ($property_options as $property): ?>
                                    <option value="<?= $property['id'] ?>" <?= $property_filter == $property['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($property['property_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="maintenance_history.php" class="btn btn-outline">
                                <i class="fas fa-sync-alt"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="history-table">
                    <?php if (empty($history)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tools"></i>
                            <h3>No Maintenance History Found</h3>
                            <p>No maintenance requests match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Property</th>
                                        <th>Student</th>
                                        <th>Title</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $request): ?>
                                        <?php 
                                        $status_class = strtolower(str_replace(' ', '_', $request['status']));
                                        $priority_class = strtolower(str_replace(' ', '_', $request['priority']));
                                        ?>
                                        <tr>
                                            <td>#<?= $request['id'] ?></td>
                                            <td><?= htmlspecialchars($request['property_name']) ?></td>
                                            <td>
                                                <?php if (!empty($request['student_photo'])): ?>
                                                    <?php $student_photo = getProfilePicturePath($request['student_photo']); ?>
                                                    <img src="<?= htmlspecialchars($student_photo) ?>" class="user-avatar" alt="Student Photo">
                                                <?php else: ?>
                                                    <div class="user-avatar-placeholder">
                                                        <?= substr($request['student_name'], 0, 1) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($request['student_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($request['title']) ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?= $priority_class ?>">
                                                    <?= ucfirst($request['priority']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= $status_class ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                                                </span>
                                            </td>
                                            <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                            <td>
                                                <a href="index.php?view=<?= $request['id'] ?>" class="action-link" title="View Details">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <?php if ($request['status'] !== 'completed'): ?>
                                                    <a href="submit.php?request_id=<?= $request['id'] ?>&property_id=<?= $request['property_id'] ?>" class="action-link" title="Create Virtual Tour">
                                                        <i class="fas fa-video"></i> Tour
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="pagination">
                            <div class="page-item">
                                <a href="#" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </div>
                            <div class="page-item active">
                                <a href="#" class="page-link">1</a>
                            </div>
                            <div class="page-item">
                                <a href="#" class="page-link">2</a>
                            </div>
                            <div class="page-item">
                                <a href="#" class="page-link">3</a>
                            </div>
                            <div class="page-item">
                                <a href="#" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
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

        // Simple notification bell interaction
        document.querySelector('.notification-bell').addEventListener('click', function() {
            window.location.href = '../chat/index.php';
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