<?php
session_start();
require_once __DIR__ . '../../config/database.php';

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

// Include email service
require_once __DIR__ . '../../includes/EmailService.php';
require_once __DIR__ . '../../config/email.php';

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../../assets/images/default-profile.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $target_group = filter_input(INPUT_POST, 'target_group', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
    $specific_property = filter_input(INPUT_POST, 'specific_property', FILTER_SANITIZE_NUMBER_INT);
    $specific_room = filter_input(INPUT_POST, 'specific_room', FILTER_SANITIZE_NUMBER_INT);
    $specific_student = filter_input(INPUT_POST, 'specific_student', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Insert announcement
        $stmt = $pdo->prepare("INSERT INTO announcements 
                              (sender_id, title, message, target_group, is_urgent, specific_property, specific_room, specific_student) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $owner_id, 
            $title, 
            $message, 
            $target_group, 
            $is_urgent,
            $specific_property,
            $specific_room,
            $specific_student
        ]);
        
        $announcement_id = $pdo->lastInsertId();
        
        // Get recipients based on target group
        $recipients = [];
        $recipient_query = "";
        $params = [];
        
        switch ($target_group) {
            case 'all':
                $recipient_query = "SELECT id, email FROM users";
                break;
                
            case 'admin':
                $recipient_query = "SELECT id, email FROM users WHERE status = 'admin'";
                break;
                
            case 'my_properties':
                $recipient_query = "SELECT DISTINCT u.id, u.email
                                   FROM users u
                                   JOIN bookings b ON u.id = b.user_id
                                   JOIN property_rooms pr ON b.room_id = pr.id
                                   JOIN property p ON pr.property_id = p.id
                                   WHERE p.owner_id = ? AND b.status IN ('confirmed', 'paid')";
                $params = [$owner_id];
                break;
                
            case 'specific_property':
                $recipient_query = "SELECT DISTINCT u.id, u.email
                                   FROM users u
                                   JOIN bookings b ON u.id = b.user_id
                                   JOIN property_rooms pr ON b.room_id = pr.id
                                   WHERE pr.property_id = ? AND b.status IN ('confirmed', 'paid')";
                $params = [$specific_property];
                break;
                
            case 'specific_room':
                $recipient_query = "SELECT DISTINCT u.id, u.email
                                   FROM users u
                                   JOIN bookings b ON u.id = b.user_id
                                   WHERE b.room_id = ? AND b.status IN ('confirmed', 'paid')";
                $params = [$specific_room];
                break;
                
            case 'specific_student':
                $recipient_query = "SELECT id, email FROM users WHERE id = ?";
                $params = [$specific_student];
                break;
                
            default:
                $recipient_query = "SELECT id, email FROM users";
                break;
        }
        
        $stmt = $pdo->prepare($recipient_query);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll();
        
        // Send emails using EmailService
        $emailService = new EmailService();
        $email_subject = $is_urgent ? "[URGENT] $title" : $title;
        $sent_count = 0;
        
        foreach ($recipients as $recipient) {
            try {
                $emailService->sendAnnouncement(
                    $recipient['email'],
                    $email_subject,
                    $message
                );
                
                $sent_count++;
                
                // Record recipient
                $stmt = $pdo->prepare("INSERT INTO announcement_recipients (announcement_id, user_id) VALUES (?, ?)");
                $stmt->execute([$announcement_id, $recipient['id']]);
            } catch (Exception $e) {
                error_log("Email sending failed to {$recipient['email']}: " . $e->getMessage());
            }
        }
        
        $_SESSION['success_message'] = "Announcement sent successfully to $sent_count recipients!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error sending announcement: " . $e->getMessage();
    }
    header("Location: announcement.php");
    exit();
}

// Get announcements sent by this owner
$announcements_stmt = $pdo->prepare("SELECT a.*, COUNT(ar.user_id) as recipient_count
                                    FROM announcements a
                                    LEFT JOIN announcement_recipients ar ON a.id = ar.announcement_id
                                    WHERE a.sender_id = ? 
                                    GROUP BY a.id
                                    ORDER BY a.created_at DESC");
$announcements_stmt->execute([$owner_id]);
$announcements = $announcements_stmt->fetchAll();

// Get properties owned by this owner
$properties_stmt = $pdo->prepare("SELECT p.id, p.property_name 
                                 FROM property p
                                 WHERE p.owner_id = ? AND p.deleted = 0
                                 ORDER BY p.property_name");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Get booked rooms and students
$rooms_stmt = $pdo->prepare("SELECT DISTINCT pr.id as room_id, pr.room_number, p.id as property_id, 
                            p.property_name, u.id as user_id, u.username, u.email
                            FROM property_rooms pr
                            JOIN property p ON pr.property_id = p.id
                            JOIN bookings b ON pr.id = b.room_id
                            JOIN users u ON b.user_id = u.id
                            WHERE p.owner_id = ? AND b.status IN ('confirmed', 'paid')
                            ORDER BY p.property_name, pr.room_number, u.username");
$rooms_stmt->execute([$owner_id]);
$booked_rooms = $rooms_stmt->fetchAll();

// Get all rooms
$all_rooms_stmt = $pdo->prepare("SELECT pr.id, pr.room_number, p.id as property_id, p.property_name
                                FROM property_rooms pr
                                JOIN property p ON pr.property_id = p.id
                                WHERE p.owner_id = ?");
$all_rooms_stmt->execute([$owner_id]);
$all_rooms = $all_rooms_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Announcement | Landlords & Tenants</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #2c3e50;
            --accent: #e74c3c;
            --success: #4cc9f0;
            --warning: #f8961e; 
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --white: #ffffff;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
            min-height: 100vh;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background: var(--secondary);
            color: white;
            transition: all 0.3s;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .sidebar-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }
        
        .sidebar-menu {
            padding: 15px 0;
        }
        
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
            font-size: 1rem;
        }
        
        .sidebar-menu li a:hover, 
        .sidebar-menu li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar-menu li a i {
            width: 24px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .top-nav {
            background: var(--white);
            padding: 15px 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary);
            display: none;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .owner-badge {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .content-area {
            padding: 25px;
            flex: 1;
        }
        
        .page-header {
            margin-bottom: 25px;
        }
        
        .page-title h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .breadcrumb {
            list-style: none;
            display: flex;
            padding: 0;
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .breadcrumb li {
            margin-right: 10px;
        }
        
        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin-left: 10px;
            color: var(--gray);
        }
        
        .breadcrumb li a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .announcement-container {
            display: flex;
            gap: 25px;
        }
        
        .announcement-form {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
        }
        
        .announcement-form h2 {
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .announcement-form h2 i {
            color: var(--primary);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .target-option {
            display: none;
        }
        
        .announcement-list {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
        }
        
        .announcement-list h2 {
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--secondary);
        }
        
        .announcement-item {
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
            transition: all 0.3s;
        }
        
        .announcement-item:hover {
            background-color: #f9f9f9;
        }
        
        .announcement-item:last-child {
            border-bottom: none;
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .announcement-title {
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--secondary);
        }
        
        .announcement-meta {
            display: flex;
            gap: 15px;
            color: var(--gray);
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        
        .urgent {
            background: var(--accent);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .announcement-message {
            margin-bottom: 15px;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        .announcement-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--light-gray);
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                position: fixed;
                left: -100%;
                top: 0;
                bottom: 0;
                z-index: 1000;
                transition: left 0.3s;
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .announcement-container {
                flex-direction: column;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 15px;
            }
            
            .announcement-header {
                flex-direction: column;
            }
            
            .announcement-meta {
                margin-top: 5px;
            }
            
            .announcement-actions {
                flex-wrap: wrap;
            }
        }
        
        /* Custom Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-left: 10px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--accent);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Loading spinner */
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile Picture">
                <div>
                    <h3><?= htmlspecialchars($_SESSION['username']) ?></h3>
                    <small>Property Owner</small>
                </div>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="property_dashboard.php"><i class="fas fa-home"></i> My Properties</a></li>
                    <li><a href="owner/bookings/"><i class="fas fa-calendar-alt"></i> Bookings</a></li>
                    <li><a href="owner/payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="announcement.php" class="active"><i class="fa-solid fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li>
                        <form action="../../auth/logout.php" method="POST">
                            <button type="submit" class="btn btn-link text-start w-100 p-0" style="color: rgba(255, 255, 255, 0.8);">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </div>
                <div class="user-profile">
                    <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                    <span><?= htmlspecialchars($_SESSION['username']) ?> <span class="owner-badge">OWNER</span></span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <div class="page-title">
                        <h1><i class="fa-solid fa-bullhorn me-2"></i> Announcements</h1>
                    </div>
                    <ul class="breadcrumb">
                        <li><a href="../dashboard.php">Home</a></li>
                        <li>Announcements</li>
                    </ul>
                </div>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> 
                        <div><?= htmlspecialchars($_SESSION['error_message']) ?></div>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 
                        <div><?= htmlspecialchars($_SESSION['success_message']) ?></div>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <div class="announcement-container">
                    <div class="announcement-form">
                        <h2><i class="fas fa-paper-plane"></i> Create New Announcement</h2>
                        <form id="announcementForm" method="POST">
                            <div class="form-group">
                                <label for="title">Title</label>
                                <input type="text" id="title" name="title" class="form-control" placeholder="Important message for tenants..." required>
                            </div>
                            
                            <div class="form-group">
                                <label for="message">Message</label>
                                <textarea id="message" name="message" class="form-control" placeholder="Write your announcement here..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="target_group">Target Audience</label>
                                <select id="target_group" name="target_group" class="form-control" required>
                                    <option value="">Select Target Group</option>
                                    <option value="all">All Users</option>
                                    <option value="admin">Administrators Only</option>
                                    <option value="my_properties">My Properties (All Tenants)</option>
                                    <option value="specific_property">Specific Property</option>
                                    <option value="specific_room">Specific Room</option>
                                    <option value="specific_student">Specific Student</option>
                                </select>
                            </div>
                            
                            <!-- Target Options -->
                            <div id="specific_property_option" class="target-option form-group">
                                <label for="specific_property">Select Property</label>
                                <select id="specific_property" name="specific_property" class="form-control">
                                    <?php foreach ($properties as $property): ?>
                                        <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['property_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="specific_room_option" class="target-option form-group">
                                <label for="specific_room">Select Room</label>
                                <select id="specific_room" name="specific_room" class="form-control">
                                    <?php foreach ($all_rooms as $room): ?>
                                        <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['property_name']) ?> - Room <?= $room['room_number'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="specific_student_option" class="target-option form-group">
                                <label for="specific_student">Select Student</label>
                                <select id="specific_student" name="specific_student" class="form-control">
                                    <?php foreach ($booked_rooms as $room): ?>
                                        <option value="<?= $room['user_id'] ?>"><?= htmlspecialchars($room['username']) ?> (Room <?= $room['room_number'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_urgent" id="is_urgent"> 
                                    Mark as Urgent
                                    <span class="switch">
                                        <input type="checkbox" id="urgent_toggle">
                                        <span class="slider"></span>
                                    </span>
                                </label>
                            </div>
                            
                            <button type="submit" id="submitBtn" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Announcement
                            </button>
                        </form>
                    </div>
                    
                    <div class="announcement-list">
                        <h2><i class="fas fa-history"></i> Sent Announcements</h2>
                        
                        <?php if (!empty($announcements)): ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="announcement-item">
                                    <div class="announcement-header">
                                        <div class="announcement-title">
                                            <?= htmlspecialchars($announcement['title']) ?>
                                        </div>
                                        <div class="announcement-meta">
                                            <?php if ($announcement['is_urgent']): ?>
                                                <span class="urgent">URGENT</span>
                                            <?php endif; ?>
                                            <span><i class="far fa-calendar"></i> <?= date('M j, Y g:i a', strtotime($announcement['created_at'])) ?></span>
                                            <span><i class="fas fa-users"></i> Sent to <?= $announcement['recipient_count'] ?> recipients</span>
                                        </div>
                                    </div>
                                    <div class="announcement-message">
                                        <?= nl2br(htmlspecialchars($announcement['message'])) ?>
                                    </div>
                                    <div class="announcement-actions">
                                        <button class="btn btn-outline btn-sm">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <h3>No Announcements Yet</h3>
                                <p>Create your first announcement using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Handle target group selection
        const targetGroup = document.getElementById('target_group');
        const targetOptions = document.querySelectorAll('.target-option');
        
        targetGroup.addEventListener('change', function() {
            // Hide all options
            targetOptions.forEach(option => {
                option.style.display = 'none';
            });
            
            // Show selected option
            const selectedOption = this.value + '_option';
            if (selectedOption) {
                const element = document.getElementById(selectedOption);
                if (element) {
                    element.style.display = 'block';
                }
            }
        });
        
        // Toggle urgent switch
        const urgentToggle = document.getElementById('urgent_toggle');
        const isUrgent = document.getElementById('is_urgent');
        
        urgentToggle.addEventListener('change', function() {
            isUrgent.checked = this.checked;
        });
        
        // Form submission handler
        const announcementForm = document.getElementById('announcementForm');
        
        announcementForm.addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Sending...';
        });
        
        // Initialize target options
        targetOptions.forEach(option => {
            option.style.display = 'none';
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
    </script>
</body>
</html>