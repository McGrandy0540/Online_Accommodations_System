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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
        $target_group = filter_input(INPUT_POST, 'target_group', FILTER_SANITIZE_STRING);
        $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
        
        // Insert announcement
        $stmt = $pdo->prepare("INSERT INTO announcements 
                              (sender_id, title, message, target_group, is_urgent) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$owner_id, $title, $message, $target_group, $is_urgent]);
        
        $announcement_id = $pdo->lastInsertId();
        
        // Get recipients based on target group
        $recipients = [];
        
        if ($target_group === 'all') {
            // Send to all students, admins, and students who have booked this owner's properties
            $recipients_stmt = $pdo->prepare("
                SELECT DISTINCT u.id 
                FROM users u 
                WHERE u.deleted = 0 AND (
                    u.status = 'student' OR 
                    u.status = 'admin' OR 
                    u.id IN (
                        SELECT DISTINCT b.user_id
                        FROM bookings b
                        JOIN property p ON b.property_id = p.id
                        WHERE p.owner_id = ? AND b.status IN ('confirmed', 'paid')
                    )
                )
            ");
            $recipients_stmt->execute([$owner_id]);
            $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target_group === 'all_students') {
            $recipients_stmt = $pdo->prepare("SELECT id FROM users WHERE status = 'student' AND deleted = 0");
            $recipients_stmt->execute();
            $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target_group === 'admin') {
            $recipients_stmt = $pdo->prepare("SELECT id FROM users WHERE status = 'admin' AND deleted = 0");
            $recipients_stmt->execute();
            $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target_group === 'booked_students') {
            $recipients_stmt = $pdo->prepare("SELECT DISTINCT b.user_id
                                             FROM bookings b
                                             JOIN property p ON b.property_id = p.id
                                             WHERE p.owner_id = ? AND b.status IN ('confirmed', 'paid')");
            $recipients_stmt->execute([$owner_id]);
            $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target_group === 'specific_room') {
            $room_id = $_POST['room_id'] ?? null;
            if ($room_id) {
                // Get all students in the specific room
                $recipients_stmt = $pdo->prepare("
                    SELECT DISTINCT b.user_id
                    FROM bookings b
                    JOIN property p ON b.property_id = p.id
                    WHERE p.owner_id = ? AND b.room_id = ? AND b.status IN ('confirmed', 'paid')
                ");
                $recipients_stmt->execute([$owner_id, $room_id]);
                $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } elseif ($target_group === 'specific_student') {
            $student_id = $_POST['student_id'] ?? null;
            if ($student_id) {
                // Verify the student has booked a room from this owner
                $verify_stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM bookings b
                    JOIN property p ON b.property_id = p.id
                    WHERE p.owner_id = ? AND b.user_id = ? AND b.status IN ('confirmed', 'paid')
                ");
                $verify_stmt->execute([$owner_id, $student_id]);
                if ($verify_stmt->fetchColumn() > 0) {
                    $recipients = [$student_id];
                }
            }
        }
        
        // Insert recipients if any
        if (!empty($recipients)) {
            $values = [];
            $params = [];
            foreach ($recipients as $recipient_id) {
                $values[] = "(?, ?)";
                $params[] = $announcement_id;
                $params[] = $recipient_id;
            }
            
            $insert_recipients = $pdo->prepare("INSERT INTO announcement_recipients 
                                              (announcement_id, user_id) 
                                              VALUES " . implode(',', $values));
            $insert_recipients->execute($params);
        }
        
        $success_message = "Announcement sent successfully to " . count($recipients) . " recipients!";
    } catch (PDOException $e) {
        $error_message = "Error sending announcement: " . $e->getMessage();
    }
}

// Get announcements sent by this owner with read statistics
$announcements_stmt = $pdo->prepare("SELECT a.*, 
                                    (SELECT COUNT(*) FROM announcement_recipients ar 
                                     WHERE ar.announcement_id = a.id) as recipient_count,
                                    (SELECT COUNT(*) FROM announcement_recipients ar 
                                     WHERE ar.announcement_id = a.id AND ar.read_at IS NOT NULL) as read_count
                                    FROM announcements a
                                    WHERE a.sender_id = ?
                                    ORDER BY a.created_at DESC");
$announcements_stmt->execute([$owner_id]);
$announcements = $announcements_stmt->fetchAll();

// Get properties owned by this owner for specific targeting
$properties_stmt = $pdo->prepare("SELECT p.id, p.property_name
                                 FROM property p
                                 WHERE p.owner_id = ? AND p.deleted = 0
                                 ORDER BY p.property_name");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Get booked rooms and students
$rooms_stmt = $pdo->prepare("
    SELECT DISTINCT 
        pr.id as room_id, 
        pr.room_number, 
        p.id as property_id,
        p.property_name, 
        u.id as user_id, 
        u.username,
        u.email
    FROM property_rooms pr
    JOIN property p ON pr.property_id = p.id
    JOIN bookings b ON pr.id = b.room_id
    JOIN users u ON b.user_id = u.id
    WHERE p.owner_id = ? AND b.status IN ('confirmed', 'paid')
    ORDER BY p.property_name, pr.room_number, u.username
");
$rooms_stmt->execute([$owner_id]);
$booked_rooms = $rooms_stmt->fetchAll();

// Get all rooms for this owner (including unoccupied ones)
$all_rooms_stmt = $pdo->prepare("
    SELECT DISTINCT 
        pr.id as room_id, 
        pr.room_number, 
        p.id as property_id,
        p.property_name,
        pr.status as room_status
    FROM property_rooms pr
    JOIN property p ON pr.property_id = p.id
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY p.property_name, pr.room_number
");
$all_rooms_stmt->execute([$owner_id]);
$all_rooms = $all_rooms_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            padding-top: var(--header-height);
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
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 1.5rem;
            background-color: #f5f7fa;
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

        /* Announcement Card Styles */
        .announcement-card {
            border-left: 4px solid var(--primary-color);
            transition: all var(--transition-speed);
        }

        .announcement-card.urgent {
            border-left-color: var(--accent-color);
        }

        .announcement-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .announcement-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        .announcement-badge {
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-urgent {
            background-color: var(--accent-color);
            color: white;
        }

        .badge-normal {
            background-color: var(--light-color);
            color: var(--dark-color);
        }

        .recipient-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        /* Select2 Customization */
        .select2-container--default .select2-selection--multiple {
            border-radius: var(--border-radius) !important;
            border: 1px solid #dee2e6 !important;
            padding: 0.375rem 0.75rem !important;
            min-height: 45px !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary-color) !important;
            border: none !important;
            color: white !important;
            border-radius: 4px !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white !important;
            margin-right: 5px !important;
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
            .announcement-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .recipient-stats {
                flex-direction: column;
                gap: 0.5rem;
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
                <img src="../assets/images/ktu logo.png" alt="UniHomes Logo">
                <span>UniHomes</span>
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
                        <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
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
                    <li><a href="roommate-matching/"><i class="fas fa-users"></i> <span class="menu-text">Roommate Matching</span></a></li>
                    <li><a href="announcements.php" class="active"><i class="fas fa-bullhorn"></i> <span class="menu-text">Announcements</span></a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Announcements</h1>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal">
                    <i class="fas fa-plus me-2"></i>New Announcement
                </button>
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
                    <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Your Announcements</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($announcements)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-bullhorn fa-3x text-muted mb-3"></i>
                            <h4>No Announcements Yet</h4>
                            <p class="text-muted">Create your first announcement to communicate with your tenants</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($announcements as $announcement): ?>
                            <div class="card announcement-card mb-3 <?= $announcement['is_urgent'] ? 'urgent' : '' ?>">
                                <div class="card-body">
                                    <div class="announcement-header">
                                        <div>
                                            <h3 class="announcement-title"><?= htmlspecialchars($announcement['title']) ?></h3>
                                            <div class="announcement-meta">
                                                <span><i class="fas fa-calendar-alt me-1"></i> <?= date('M j, Y g:i A', strtotime($announcement['created_at'])) ?></span>
                                                <span><i class="fas fa-users me-1"></i> <?= htmlspecialchars(ucfirst($announcement['target_group'])) ?></span>
                                            </div>
                                        </div>
                                        <span class="announcement-badge <?= $announcement['is_urgent'] ? 'badge-urgent' : 'badge-normal' ?>">
                                            <?= $announcement['is_urgent'] ? 'URGENT' : 'Standard' ?>
                                        </span>
                                    </div>
                                    
                                    <p><?= nl2br(htmlspecialchars($announcement['message'])) ?></p>
                                    
                                    <div class="recipient-stats">
                                        <div class="stat-item">
                                            <i class="fas fa-paper-plane text-primary"></i>
                                            <span>Sent to <?= $announcement['recipient_count'] ?> recipients</span>
                                        </div>
                                        <div class="stat-item">
                                            <i class="fas fa-eye text-success"></i>
                                            <span><?= $announcement['read_count'] ?> read</span>
                                        </div>
                                        <div class="stat-item">
                                            <i class="fas fa-percentage text-info"></i>
                                            <span><?= $announcement['recipient_count'] > 0 ? round(($announcement['read_count'] / $announcement['recipient_count']) * 100) : 0 ?>% read rate</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- New Announcement Modal -->
    <div class="modal fade" id="newAnnouncementModal" tabindex="-1" aria-labelledby="newAnnouncementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newAnnouncementModalLabel">Create New Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="message" class="form-label">Message</label>
                            <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="target_group" class="form-label">Recipients</label>
                            <select class="form-select" id="target_group" name="target_group" required>
                                <option value="">Select recipients</option>
                                <option value="all">All (Students, Admins & My Tenants)</option>
                                <option value="all_students">All Students</option>
                                <option value="admin">All Admins</option>
                                <option value="booked_students">Students who have booked my properties</option>
                                <option value="specific_room">All Students in a Specific Room</option>
                                <option value="specific_student">Specific Student</option>
                            </select>
                        </div>

                        <div class="form-group" id="roomGroup" style="display: none;">
                            <label for="room" class="form-label">Select Room</label>
                            <select class="form-select" id="room" name="room_id">
                                <option value="">Select a room</option>
                                <?php foreach ($all_rooms as $room): ?>
                                    <option value="<?= $room['room_id'] ?>" data-property="<?= $room['property_id'] ?>">
                                        <?= htmlspecialchars($room['property_name']) ?> - Room <?= htmlspecialchars($room['room_number']) ?>
                                        <?php if ($room['room_status'] === 'available'): ?>
                                            (Available)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Select a room to send message to all students in that room</small>
                        </div>

                        <div class="form-group" id="studentGroup" style="display: none;">
                            <label for="student" class="form-label">Select Student</label>
                            <select class="form-select" id="student" name="student_id">
                                <option value="">Select a student</option>
                            </select>
                            <small class="form-text text-muted">Only students who have booked your properties are shown</small>
                        </div>

                        <div class="form-group" id="previewGroup" style="display: none;">
                            <label class="form-label">Recipients Preview</label>
                            <div id="recipientsPreview" class="alert alert-info">
                                <!-- Recipients will be shown here -->
                            </div>
                        </div>
                        
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" id="is_urgent" name="is_urgent">
                            <label class="form-check-label" for="is_urgent">Mark as Urgent</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn">
                            <i class="fas fa-paper-plane me-2"></i>Send Announcement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Initialize functionality
        $(document).ready(function() {
            var booked_rooms = <?php echo json_encode($booked_rooms); ?>;
            var all_rooms = <?php echo json_encode($all_rooms); ?>;
            
            // Group students by room
            var rooms = {};
            booked_rooms.forEach(function(room) {
                if (!rooms[room.room_id]) {
                    rooms[room.room_id] = {
                        room_number: room.room_number,
                        property_name: room.property_name,
                        students: []
                    };
                }
                rooms[room.room_id].students.push({
                    id: room.user_id,
                    username: room.username,
                    email: room.email
                });
            });

            // Get all unique students who have booked properties
            var all_students = [];
            var student_ids = new Set();
            booked_rooms.forEach(function(room) {
                if (!student_ids.has(room.user_id)) {
                    student_ids.add(room.user_id);
                    all_students.push({
                        id: room.user_id,
                        username: room.username,
                        email: room.email
                    });
                }
            });

            // Show/hide form groups based on target selection
            $('#target_group').change(function() {
                var targetGroup = $(this).val();
                
                // Hide all conditional groups first
                $('#roomGroup, #studentGroup, #previewGroup').hide();
                
                if (targetGroup === 'specific_room') {
                    $('#roomGroup').show();
                } else if (targetGroup === 'specific_student') {
                    $('#studentGroup').show();
                    populateStudentSelect();
                }
                
                updatePreview();
            });

            // Populate student select with all students who have bookings
            function populateStudentSelect() {
                var studentSelect = $('#student');
                studentSelect.empty();
                studentSelect.append(new Option('Select a student', ''));
                
                all_students.forEach(function(student) {
                    studentSelect.append(new Option(student.username + ' (' + student.email + ')', student.id));
                });
            }

            // Handle room selection change
            $('#room').change(function() {
                updatePreview();
            });

            // Handle student selection change
            $('#student').change(function() {
                updatePreview();
            });

            // Update recipients preview
            function updatePreview() {
                var targetGroup = $('#target_group').val();
                var previewDiv = $('#recipientsPreview');
                var previewGroup = $('#previewGroup');
                
                if (!targetGroup) {
                    previewGroup.hide();
                    return;
                }

                var previewText = '';
                var count = 0;

                switch (targetGroup) {
                    case 'all':
                        previewText = 'All students, admins, and students who have booked your properties';
                        count = 'Multiple';
                        break;
                    case 'all_students':
                        previewText = 'All students in the system';
                        count = 'Multiple';
                        break;
                    case 'admin':
                        previewText = 'All administrators';
                        count = 'Multiple';
                        break;
                    case 'booked_students':
                        previewText = 'Students who have booked your properties (' + all_students.length + ' students)';
                        count = all_students.length;
                        break;
                    case 'specific_room':
                        var roomId = $('#room').val();
                        if (roomId && rooms[roomId]) {
                            var room = rooms[roomId];
                            previewText = 'Students in ' + room.property_name + ' - Room ' + room.room_number + ':';
                            room.students.forEach(function(student) {
                                previewText += '<br>• ' + student.username + ' (' + student.email + ')';
                            });
                            count = room.students.length;
                        } else {
                            previewText = 'Please select a room';
                            count = 0;
                        }
                        break;
                    case 'specific_student':
                        var studentId = $('#student').val();
                        if (studentId) {
                            var student = all_students.find(s => s.id == studentId);
                            if (student) {
                                previewText = 'Selected student: ' + student.username + ' (' + student.email + ')';
                                count = 1;
                            }
                        } else {
                            previewText = 'Please select a student';
                            count = 0;
                        }
                        break;
                }

                if (previewText) {
                    previewDiv.html('<strong>Recipients (' + count + '):</strong><br>' + previewText);
                    previewGroup.show();
                } else {
                    previewGroup.hide();
                }
            }
        });

        // Toggle sidebar collapse
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
    </script>
</body>
</html>
