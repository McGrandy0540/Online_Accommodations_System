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

// Get maintenance requests
$query = "SELECT mr.*, p.property_name, p.id as property_id 
          FROM maintenance_requests mr
          JOIN property p ON mr.property_id = p.id
          JOIN property_owners po ON p.id = po.property_id
          WHERE po.owner_id = ?
          ORDER BY 
            CASE 
              WHEN mr.priority = 'emergency' THEN 1
              WHEN mr.priority = 'high' THEN 2
              WHEN mr.priority = 'medium' THEN 3
              WHEN mr.priority = 'low' THEN 4
            END,
            mr.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute([$owner_id]);
$requests = $stmt->fetchAll();

// Get properties for dropdown
$properties = $pdo->prepare("SELECT p.id, p.property_name 
                            FROM property p
                            JOIN property_owners po ON p.id = po.property_id
                            WHERE po.owner_id = ? AND p.deleted = 0");
$properties->execute([$owner_id]);
$property_options = $properties->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $request_id = $_POST['request_id'];
        $new_status = $_POST['status'];
        $notes = $_POST['admin_notes'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE maintenance_requests 
                              SET status = ?, updated_at = NOW(), admin_notes = ?
                              WHERE id = ?");
        $stmt->execute([$new_status, $notes, $request_id]);
        
        // If completed, set completed_at
        if ($new_status === 'completed') {
            $pdo->prepare("UPDATE maintenance_requests 
                          SET completed_at = NOW() 
                          WHERE id = ?")->execute([$request_id]);
        }
        
        // Add notification
        $pdo->prepare("INSERT INTO notifications (user_id, property_id, message, type)
                      SELECT mr.user_id, mr.property_id, 
                             CONCAT('Maintenance request #', mr.id, ' has been updated to ', ?),
                             'system_alert'
                      FROM maintenance_requests mr
                      WHERE mr.id = ?")
            ->execute([$new_status, $request_id]);
            
        header("Location: index.php?success=1");
        exit();
    }
    
    if (isset($_POST['add_response'])) {
        $request_id = $_POST['request_id'];
        $response = $_POST['response'];
        
        // Get current notes
        $stmt = $pdo->prepare("SELECT admin_notes FROM maintenance_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $current_notes = $stmt->fetchColumn();
        
        // Append new response with timestamp
        $new_notes = ($current_notes ? $current_notes . "\n\n" : "") . 
                    date('Y-m-d H:i') . " (Owner): " . $response;
        
        $stmt = $pdo->prepare("UPDATE maintenance_requests 
                              SET admin_notes = ?, updated_at = NOW()
                              WHERE id = ?");
        $stmt->execute([$new_notes, $request_id]);
        
        // Add notification
        $pdo->prepare("INSERT INTO notifications (user_id, property_id, message, type)
                      SELECT mr.user_id, mr.property_id, 
                             CONCAT('New response for maintenance request #', mr.id),
                             'system_alert'
                      FROM maintenance_requests mr
                      WHERE mr.id = ?")
            ->execute([$request_id]);
            
        header("Location: index.php?success=2");
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
    <title>Maintenance Requests | UniHomes</title>
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

        /* Maintenance Request Styles */
        .request-card {
            margin-bottom: 1.5rem;
            border-left: 4px solid transparent;
        }

        .request-card.emergency {
            border-left-color: var(--accent-color);
        }

        .request-card.high {
            border-left-color: var(--warning-color);
        }

        .request-card.medium {
            border-left-color: var(--info-color);
        }

        .request-card.low {
            border-left-color: var(--success-color);
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .request-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--secondary-color);
            margin-bottom: 0.25rem;
        }

        .request-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .request-property {
            color: var(--primary-color);
            font-weight: 500;
        }

        .request-status {
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

        .request-priority {
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

        .request-description {
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .request-notes {
            margin-top: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            white-space: pre-wrap;
        }

        .notes-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }

        .request-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
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
            font-size: 0.85rem;
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

        .btn-danger {
            background-color: var(--accent-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
            color: white;
        }

        .btn-success {
            background-color: var(--success-color);
        }

        .btn-success:hover {
            background-color: #218838;
            color: white;
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .btn-warning:hover {
            background-color: #e0a800;
            color: var(--dark-color);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
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
            min-height: 100px;
            resize: vertical;
        }

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .modal-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.25rem;
        }

        .modal-title {
            font-weight: 600;
            color: var(--secondary-color);
            margin: 0;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.25rem;
        }

        /* Virtual Tour Preview */
        .virtual-tour-preview {
            position: relative;
            width: 100%;
            height: 300px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .virtual-tour-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .virtual-tour-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .virtual-tour-overlay i {
            font-size: 3rem;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
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
            .request-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .request-meta {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .dashboard-header h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }

            .request-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .request-actions .btn {
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
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="../virtual-tours/"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="../settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                    <li><a href="maintenance_history.php"><i class="fa-solid fa-clock-rotate-left"></i> <span class="menu-text">Maintenance History</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="main-content-wrapper">
                <div class="dashboard-header">
                    <h1>Maintenance Requests</h1>
                    <button class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <span class="badge"><?= $unread_messages ?></span>
                    </button>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php if ($_GET['success'] == 1): ?>
                            Maintenance request status updated successfully!
                        <?php elseif ($_GET['success'] == 2): ?>
                            Response added successfully!
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-tools"></i>
                        <h3>No Maintenance Requests</h3>
                        <p>You don't have any maintenance requests at this time.</p>
                    </div>
                <?php else: ?>
                    <div class="mb-4">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">All Requests</h5>
                                    <div>
                                        <span class="badge bg-primary">Total: <?= count($requests) ?></span>
                                        <span class="badge bg-warning text-dark">Pending: <?= count(array_filter($requests, fn($r) => $r['status'] === 'pending')) ?></span>
                                        <span class="badge bg-info">In Progress: <?= count(array_filter($requests, fn($r) => $r['status'] === 'in_progress')) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php foreach ($requests as $request): ?>
                                    <?php 
                                    $priority_class = strtolower(str_replace(' ', '_', $request['priority']));
                                    $status_class = strtolower(str_replace(' ', '_', $request['status']));
                                    ?>
                                    <div class="card request-card <?= $priority_class ?> mb-3">
                                        <div class="card-body">
                                            <div class="request-header">
                                                <div>
                                                    <div class="request-title"><?= htmlspecialchars($request['title']) ?></div>
                                                    <div class="request-meta">
                                                        <span class="request-property">
                                                            <i class="fas fa-home"></i> <?= htmlspecialchars($request['property_name']) ?>
                                                        </span>
                                                        <span>
                                                            <i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($request['created_at'])) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <span class="request-priority priority-<?= $priority_class ?>">
                                                        <?= ucfirst($request['priority']) ?>
                                                    </span>
                                                    <span class="request-status status-<?= $status_class ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="request-description">
                                                <?= nl2br(htmlspecialchars($request['description'])) ?>
                                            </div>
                                            
                                            <?php if (!empty($request['admin_notes'])): ?>
                                                <div class="request-notes">
                                                    <span class="notes-label">Notes & Responses:</span>
                                                    <?= nl2br(htmlspecialchars($request['admin_notes'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="request-actions">
                                                <button class="btn btn-outline" data-bs-toggle="modal" data-bs-target="#responseModal<?= $request['id'] ?>">
                                                    <i class="fas fa-reply"></i> Add Response
                                                </button>
                                                
                                                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#statusModal<?= $request['id'] ?>">
                                                    <i class="fas fa-sync-alt"></i> Update Status
                                                </button>
                                                
                                                <?php if ($request['status'] !== 'completed'): ?>
                                                    <form method="POST" action="submit.php" style="display: inline;">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <input type="hidden" name="property_id" value="<?= $request['property_id'] ?>">
                                                        <button type="submit" class="btn btn-success" name="create_virtual_tour">
                                                            <i class="fas fa-video"></i> Create Virtual Tour
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Response Modal -->
                                    <div class="modal fade" id="responseModal<?= $request['id'] ?>" tabindex="-1" aria-labelledby="responseModalLabel<?= $request['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="responseModalLabel<?= $request['id'] ?>">Add Response</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <div class="form-group">
                                                            <label for="response" class="form-label">Your Response</label>
                                                            <textarea class="form-control" id="response" name="response" required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" name="add_response">Submit Response</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Status Modal -->
                                    <div class="modal fade" id="statusModal<?= $request['id'] ?>" tabindex="-1" aria-labelledby="statusModalLabel<?= $request['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="statusModalLabel<?= $request['id'] ?>">Update Status</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <div class="form-group">
                                                            <label for="status" class="form-label">New Status</label>
                                                            <select class="form-control" id="status" name="status" required>
                                                                <option value="pending" <?= $request['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                <option value="in_progress" <?= $request['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                                <option value="completed" <?= $request['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                                <option value="rejected" <?= $request['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label for="admin_notes" class="form-label">Additional Notes</label>
                                                            <textarea class="form-control" id="admin_notes" name="admin_notes"><?= htmlspecialchars($request['admin_notes'] ?? '') ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" name="update_status">Update Status</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
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

        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
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