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
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $target_group = filter_input(INPUT_POST, 'target_group', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
    
    try {
        // Insert announcement
        $stmt = $pdo->prepare("INSERT INTO announcements 
                              (sender_id, title, message, target_group, is_urgent) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$owner_id, $title, $message, $target_group, $is_urgent]);
        
        $announcement_id = $pdo->lastInsertId();
        
        // Get recipients based on target group
        $recipients = [];
        
        if ($target_group === 'all') {
            $stmt = $pdo->prepare("SELECT id, email FROM users");
            $stmt->execute();
            $recipients = $stmt->fetchAll();
        } elseif ($target_group === 'admin') {
            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE status = 'admin'");
            $stmt->execute();
            $recipients = $stmt->fetchAll();
        }
        
        // Send emails
        foreach ($recipients as $recipient) {
            // Simple email sending function
            $to = $recipient['email'];
            $subject = $is_urgent ? "[URGENT] $title" : $title;
            $headers = "From: noreply@landlords-tenant.com\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $message_html = "<html><body>";
            $message_html .= "<h2>$subject</h2>";
            $message_html .= "<p>$message</p>";
            $message_html .= "</body></html>";
            
            mail($to, $subject, $message_html, $headers);
        }
        
        $_SESSION['success_message'] = "Announcement sent successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error sending announcement: " . $e->getMessage();
    }
    header("Location: announcement.php");
    exit();
}

// Get announcements sent by this owner
$announcements_stmt = $pdo->prepare("SELECT * FROM announcements 
                                    WHERE sender_id = ? 
                                    ORDER BY created_at DESC");
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
$all_rooms_stmt = $pdo->prepare("SELECT pr.id, pr.room_number, p.property_name
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
        /* Add your custom styles here */
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../../assets/images/ktu logo.png" alt="Landlords&Tenants Logo">
                <span>Landlords&Tenants</span>
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
                            <form action="../../auth/logout.php" method="POST">
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
                <button class="toggle-btn
