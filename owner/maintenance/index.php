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
    
    return '../../../' . ltrim($path, '/');
}
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');

// CORRECTED: Get maintenance requests with proper owner filtering
$query = "SELECT mr.*, p.property_name, p.id as property_id, u.username as student_name, u.email as student_email,
          (SELECT COUNT(*) FROM maintenance_messages mm 
           WHERE mm.maintenance_request_id = mr.id AND mm.sender_type = 'student' AND mm.is_read = FALSE) as unread_student_messages,
          (SELECT COUNT(*) FROM maintenance_messages mm 
           WHERE mm.maintenance_request_id = mr.id) as total_messages
          FROM maintenance_requests mr
          JOIN property p ON mr.property_id = p.id
          JOIN users u ON mr.user_id = u.id
          WHERE p.owner_id = ?
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
                            WHERE p.owner_id = ? AND p.deleted = 0");
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
    
    if (isset($_POST['send_message'])) {
        $request_id = $_POST['request_id'];
        $message = trim($_POST['message']);
        
        if (!empty($message)) {
            // Insert message into maintenance_messages table
            $stmt = $pdo->prepare("INSERT INTO maintenance_messages 
                                  (maintenance_request_id, sender_id, sender_type, message) 
                                  VALUES (?, ?, 'owner', ?)");
            $stmt->execute([$request_id, $owner_id, $message]);
            
            // Update maintenance request timestamp
            $pdo->prepare("UPDATE maintenance_requests SET updated_at = NOW() WHERE id = ?")
                ->execute([$request_id]);
            
            // Mark owner's messages as read
            $pdo->prepare("UPDATE maintenance_messages 
                          SET is_read = TRUE 
                          WHERE maintenance_request_id = ? AND sender_id = ?")
                ->execute([$request_id, $owner_id]);
            
            // Add notification for student
            $pdo->prepare("INSERT INTO notifications (user_id, property_id, message, type)
                          SELECT mr.user_id, mr.property_id, 
                                 CONCAT('New message for maintenance request #', mr.id),
                                 'maintenance_message'
                          FROM maintenance_requests mr
                          WHERE mr.id = ?")
                ->execute([$request_id]);
                
            header("Location: index.php?success=3&request_id=" . $request_id);
            exit();
        }
    }
    
    if (isset($_POST['mark_messages_read'])) {
        $request_id = $_POST['request_id'];
        
        // Mark all student messages as read for this request
        $stmt = $pdo->prepare("UPDATE maintenance_messages 
                              SET is_read = TRUE 
                              WHERE maintenance_request_id = ? AND sender_type = 'student'");
        $stmt->execute([$request_id]);
        
        header("Location: index.php?request_id=" . $request_id);
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
    <title>Maintenance Requests | Landlords&Tenant</title>
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

        Modal Fixes
        .modal-dialog {
            max-width: 600px;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            border-color: #3498db;
        }

        /* COMPLETE MODAL STABILITY FIX - NO ANIMATIONS */
        .modal {
            transition: none !important;
            animation: none !important;
            backdrop-filter: none !important;
        }
        
        .modal.fade {
            transition: none !important;
            animation: none !important;
        }
        
        .modal.fade .modal-dialog {
            transition: none !important;
            transform: none !important;
            animation: none !important;
        }
        
        .modal.show .modal-dialog {
            transform: none !important;
            animation: none !important;
        }
        
        .modal-backdrop {
            transition: none !important;
            animation: none !important;
        }
        
        .modal-backdrop.fade {
            opacity: 0.5 !important;
            transition: none !important;
            animation: none !important;
        }
        
        .modal-backdrop.show {
            opacity: 0.5 !important;
            transition: none !important;
            animation: none !important;
        }
        
        /* Force stable modal content */
        .modal-content {
            transform: none !important;
            transition: none !important;
            animation: none !important;
            will-change: auto !important;
            position: relative !important;
        }
        
        /* Stable positioning without flex issues */
        .modal-dialog-centered {
            display: flex !important;
            align-items: center !important;
            min-height: calc(100% - 1rem) !important;
            margin: 1.75rem auto !important;
        }
        
        /* Remove all transitions from form elements */
        .modal-body textarea,
        .modal-body input,
        .modal-body select {
            transition: none !important;
            animation: none !important;
        }
        
        /* Force immediate display */
        .modal.show {
            display: block !important;
            opacity: 1 !important;
        }
        
        .modal:not(.show) {
            display: none !important;
        }
        
        /* Prevent hover effects from interfering */
        .modal:hover,
        .modal-dialog:hover,
        .modal-content:hover,
        .modal-header:hover,
        .modal-body:hover,
        .modal-footer:hover {
            transform: none !important;
            transition: none !important;
            animation: none !important;
        }
        
        /* Force stable z-index */
        .modal.show {
            z-index: 1055 !important;
        }
        
        .modal-backdrop.show {
            z-index: 1050 !important;
        }
        
        /* Prevent any pointer events issues */
        .modal-backdrop {
            pointer-events: auto !important;
        }
        
        .modal.show .modal-dialog {
            pointer-events: auto !important;
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
                <img src="../../assets/images/ktu logo.png" alt="Landlords&Tenant Logo">
                <span>Landlords&Tenant</span>
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
                        <?php elseif ($_GET['success'] == 3): ?>
                            Message sent successfully!
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
                                            
                                            <!-- Messages Section -->
                                            <?php if ($request['total_messages'] > 0): ?>
                                                <div class="messages-section mt-3">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h6 class="mb-0">
                                                            <i class="fas fa-comments"></i> Messages (<?= $request['total_messages'] ?>)
                                                            <?php if ($request['unread_student_messages'] > 0): ?>
                                                                <span class="badge bg-danger"><?= $request['unread_student_messages'] ?> new</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <?php if ($request['unread_student_messages'] > 0): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                                <button type="submit" name="mark_messages_read" class="btn btn-sm btn-outline-primary">
                                                                    <i class="fas fa-check"></i> Mark as Read
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                    <button class="btn btn-sm btn-info" data-bs-toggle="collapse" data-bs-target="#messages<?= $request['id'] ?>" aria-expanded="false">
                                                        <i class="fas fa-eye"></i> View Messages
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="request-actions">
                                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#messageModal<?= $request['id'] ?>">
                                                    <i class="fas fa-reply"></i> Send Message
                                                </button>
                                                
                                                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#statusModal<?= $request['id'] ?>">
                                                    <i class="fas fa-sync-alt"></i> Update Status
                                                </button>
                                                
                                                <?php if ($request['status'] !== 'completed'): ?>
                                                    <!-- FIXED: Correct virtual tour creation path -->
                                                    <form method="POST" action="../virtual-tours/create.php" style="display: inline;">
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
                                    
                                    <!-- Messages Collapse -->
                                    <?php if ($request['total_messages'] > 0): ?>
                                        <div class="collapse mt-3" id="messages<?= $request['id'] ?>">
                                            <div class="card">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Conversation with <?= htmlspecialchars($request['student_name']) ?></h6>
                                                </div>
                                                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                                    <?php
                                                    // Get messages for this request
                                                    $msg_stmt = $pdo->prepare("
                                                        SELECT mm.*, u.username, u.profile_picture 
                                                        FROM maintenance_messages mm
                                                        JOIN users u ON mm.sender_id = u.id
                                                        WHERE mm.maintenance_request_id = ?
                                                        ORDER BY mm.created_at ASC
                                                    ");
                                                    $msg_stmt->execute([$request['id']]);
                                                    $messages = $msg_stmt->fetchAll();
                                                    ?>
                                                    
                                                    <?php foreach ($messages as $message): ?>
                                                        <div class="message-item mb-3">
                                                            <div class="d-flex <?= $message['sender_type'] === 'owner' ? 'justify-content-end' : 'justify-content-start' ?>">
                                                                <div class="message-bubble <?= $message['sender_type'] === 'owner' ? 'bg-primary text-white' : 'bg-light' ?>" style="max-width: 70%; padding: 10px 15px; border-radius: 15px;">
                                                                    <div class="message-header mb-1">
                                                                        <small class="<?= $message['sender_type'] === 'owner' ? 'text-white-50' : 'text-muted' ?>">
                                                                            <strong><?= htmlspecialchars($message['username']) ?></strong>
                                                                            <?= $message['sender_type'] === 'owner' ? '(You)' : '(Student)' ?>
                                                                            - <?= date('M j, Y g:i A', strtotime($message['created_at'])) ?>
                                                                        </small>
                                                                    </div>
                                                                    <div class="message-content">
                                                                        <?= nl2br(htmlspecialchars($message['message'])) ?>
                                                                    </div>
                                                                    <?php if ($message['sender_type'] === 'student' && !$message['is_read']): ?>
                                                                        <div class="mt-1">
                                                                            <small class="badge bg-warning">New</small>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Message Modal - STABLE SOLUTION -->
                                    <div class="modal fade" id="messageModal<?= $request['id'] ?>" tabindex="-1" 
                                         aria-labelledby="messageModalLabel<?= $request['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="messageModalLabel<?= $request['id'] ?>">
                                                        Send Message to <?= htmlspecialchars($request['student_name']) ?>
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <div class="alert alert-info">
                                                            <strong>Request:</strong> <?= htmlspecialchars($request['title']) ?><br>
                                                            <strong>Property:</strong> <?= htmlspecialchars($request['property_name']) ?>
                                                        </div>
                                                        <div class="form-group">
                                                            <label for="message<?= $request['id'] ?>" class="form-label">Your Message</label>
                                                            <textarea class="form-control" id="message<?= $request['id'] ?>" 
                                                                      name="message" rows="6" placeholder="Type your message to the student..." 
                                                                      required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary" name="send_message">
                                                            <i class="fas fa-paper-plane"></i> Send Message
                                                        </button>
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

        // COMPLETE MODAL STABILITY SOLUTION - REMOVE FADE CLASS AND USE CUSTOM IMPLEMENTATION
        document.addEventListener('DOMContentLoaded', function() {
            // Remove fade class from all modals to prevent animation issues
            const modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                modal.classList.remove('fade');
                modal.style.display = 'none';
                modal.classList.remove('show');
            });

            // Custom modal show/hide functions
            function showModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    // Hide any other open modals first
                    modals.forEach(function(otherModal) {
                        if (otherModal !== modal && otherModal.classList.contains('show')) {
                            hideModal(otherModal.id);
                        }
                    });

                    // Create backdrop
                    let backdrop = document.querySelector('.modal-backdrop');
                    if (!backdrop) {
                        backdrop = document.createElement('div');
                        backdrop.className = 'modal-backdrop show';
                        document.body.appendChild(backdrop);
                    }

                    // Show modal
                    modal.style.display = 'block';
                    modal.classList.add('show');
                    document.body.style.overflow = 'hidden';
                    document.body.classList.add('modal-open');

                    // Focus textarea after a short delay
                    setTimeout(() => {
                        const textarea = modal.querySelector('textarea[name="message"]');
                        if (textarea) {
                            textarea.focus();
                        }
                    }, 50);
                }
            }

            function hideModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    
                    // Remove backdrop
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }

                    // Restore body
                    document.body.style.overflow = '';
                    document.body.classList.remove('modal-open');

                    // Clear form
                    const form = modal.querySelector('form');
                    if (form) {
                        const textarea = form.querySelector('textarea[name="message"]');
                        if (textarea) {
                            textarea.value = '';
                        }
                    }
                }
            }

            // Replace all modal triggers with custom implementation
            document.addEventListener('click', function(event) {
                const trigger = event.target.closest('[data-bs-toggle="modal"]');
                if (trigger) {
                    event.preventDefault();
                    const targetModal = trigger.getAttribute('data-bs-target');
                    if (targetModal) {
                        const modalId = targetModal.replace('#', '');
                        showModal(modalId);
                    }
                }

                // Handle close buttons
                const closeBtn = event.target.closest('[data-bs-dismiss="modal"], .btn-close');
                if (closeBtn) {
                    event.preventDefault();
                    const modal = closeBtn.closest('.modal');
                    if (modal) {
                        hideModal(modal.id);
                    }
                }
            });

            // Handle backdrop clicks
            document.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    hideModal(event.target.id);
                }
            });

            // Handle escape key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    const openModal = document.querySelector('.modal.show');
                    if (openModal) {
                        hideModal(openModal.id);
                    }
                }
            });

            // Prevent form submission issues
            document.addEventListener('submit', function(event) {
                const form = event.target;
                if (form.closest('.modal')) {
                    // Let the form submit normally, modal will close on page reload
                    return true;
                }
            });
        });
    </script>
</body>
</html>
