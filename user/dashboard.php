<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get student stats
$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
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
    
    return '../uploads/profile_prictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');

// Get active bookings count
$bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status IN ('confirmed', 'paid')");
$bookings->execute([$student_id]);
$active_bookings = $bookings->fetchColumn();

// Get pending bookings count
$pending = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'pending'");
$pending->execute([$student_id]);
$pending_bookings = $pending->fetchColumn();

// Get total payments made
$payments = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = ?)");
$payments->execute([$student_id]);
$total_payments = $payments->fetchColumn() ?? 0;

// Get unread messages count
$messages = $pdo->prepare("SELECT COUNT(*) FROM chat_messages cm
                          JOIN chat_conversations cc ON cm.conversation_id = cc.id
                          WHERE cc.student_id = ? AND cm.sender_id != ? AND cm.is_read = 0");
$messages->execute([$student_id, $student_id]);
$unread_messages = $messages->fetchColumn();

// Get maintenance requests count
$maintenance = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE user_id = ? AND status = 'pending'");
$maintenance->execute([$student_id]);
$maintenance_requests = $maintenance->fetchColumn();

// Get unread notifications count
$notifications = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$notifications->execute([$student_id]);
$unread_notifications = $notifications->fetchColumn();

// Get recent announcements
$announcements = $pdo->prepare("SELECT a.* FROM announcements a
                              JOIN announcement_recipients ar ON a.id = ar.announcement_id
                              WHERE ar.user_id = ? AND a.target_group IN ('all', 'students')
                              ORDER BY a.created_at DESC LIMIT 5");
$announcements->execute([$student_id]);
$recent_announcements = $announcements->fetchAll();

// Get comprehensive activity logs for the student from multiple sources
$all_activities = [];

// 1. Get booking activities
// In the booking_activities query
$booking_activities = $pdo->prepare("
    SELECT 
        'booking' as activity_type,
        CONCAT('Booking ', 
            CASE 
                WHEN b.status = 'pending' THEN 'submitted for '  -- Changed to b.status
                WHEN b.status = 'confirmed' THEN 'confirmed for '
                WHEN b.status = 'paid' THEN 'payment completed for '
                WHEN b.status = 'cancelled' THEN 'cancelled for '
                WHEN b.status = 'rejected' THEN 'rejected for '
                ELSE CONCAT(b.status, ' for ')
            END,
            p.property_name, ' - Room ', pr.room_number
        ) as action,
        'property' as entity_type,
        b.created_at,
        b.property_id as entity_id,
        b.status as activity_status
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
");
$booking_activities->execute([$student_id]);
$booking_results = $booking_activities->fetchAll();
foreach($booking_results as $activity) {
    $all_activities[] = $activity;
}

// 2. Get payment activities
$payment_activities = $pdo->prepare("
    SELECT 
        'payment' as activity_type,
        CONCAT('Payment of GHS ', FORMAT(p.amount, 2), ' ', 
            CASE 
                WHEN p.status = 'completed' THEN 'completed'
                WHEN p.status = 'pending' THEN 'initiated'
                WHEN p.status = 'failed' THEN 'failed'
                ELSE p.status
            END,
            ' for ', prop.property_name
        ) as action,
        'payment' as entity_type,
        p.created_at,
        p.id as entity_id,
        p.status as activity_status
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN property prop ON b.property_id = prop.id
    WHERE b.user_id = ?
    ORDER BY p.created_at DESC
");
$payment_activities->execute([$student_id]);
$payment_results = $payment_activities->fetchAll();
foreach($payment_results as $activity) {
    $all_activities[] = $activity;
}

// 3. Get message activities
$message_activities = $pdo->prepare("
    SELECT 
        'message' as activity_type,
        CONCAT(
            CASE 
                WHEN cm.sender_id = ? THEN 'Sent message to '
                ELSE 'Received message from '
            END,
            u.username,
            CASE 
                WHEN cc.property_id IS NOT NULL THEN CONCAT(' about ', p.property_name)
                ELSE ''
            END
        ) as action,
        'message' as entity_type,
        cm.created_at,
        cm.id as entity_id,
        CASE WHEN cm.is_read = 1 THEN 'read' ELSE 'unread' END as activity_status
    FROM chat_messages cm
    JOIN chat_conversations cc ON cm.conversation_id = cc.id
    JOIN users u ON (CASE WHEN cm.sender_id = ? THEN cc.owner_id ELSE cm.sender_id END) = u.id
    LEFT JOIN property p ON cc.property_id = p.id
    WHERE cc.student_id = ?
    ORDER BY cm.created_at DESC
    LIMIT 50
");
$message_activities->execute([$student_id, $student_id, $student_id]);
$message_results = $message_activities->fetchAll();
foreach($message_results as $activity) {
    $all_activities[] = $activity;
}

// 4. Get maintenance activities
$maintenance_activities = $pdo->prepare("
    SELECT 
        'maintenance' as activity_type,
        CONCAT('Maintenance request ', 
            CASE 
                WHEN mr.status = 'pending' THEN 'submitted'
                WHEN mr.status = 'in_progress' THEN 'is in progress'
                WHEN mr.status = 'completed' THEN 'completed'
                WHEN mr.status = 'cancelled' THEN 'cancelled'
                ELSE mr.status
            END,
            ' for \"', mr.title, '\" at ', p.property_name
        ) as action,
        'maintenance' as entity_type,
        mr.created_at,
        mr.id as entity_id,
        mr.status as activity_status
    FROM maintenance_requests mr
    JOIN property p ON mr.property_id = p.id
    WHERE mr.user_id = ?
    ORDER BY mr.created_at DESC
");
$maintenance_activities->execute([$student_id]);
$maintenance_results = $maintenance_activities->fetchAll();
foreach($maintenance_results as $activity) {
    $all_activities[] = $activity;
}

// 5. Get review activities
$review_activities = $pdo->prepare("
    SELECT 
        'review' as activity_type,
        CONCAT('Left a ', r.rating, '-star review for ', p.property_name) as action,
        'review' as entity_type,
        r.created_at,
        r.id as entity_id,
        'completed' as activity_status
    FROM reviews r
    JOIN property p ON r.property_id = p.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$review_activities->execute([$student_id]);
$review_results = $review_activities->fetchAll();
foreach($review_results as $activity) {
    $all_activities[] = $activity;
}

// 6. Get system activities from activity_logs table (if it exists)
try {
    $system_activities = $pdo->prepare("
        SELECT 
            'system' as activity_type,
            action,
            entity_type,
            created_at,
            entity_id,
            'completed' as activity_status
        FROM activity_logs 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $system_activities->execute([$student_id]);
    $system_results = $system_activities->fetchAll();
    foreach($system_results as $activity) {
        $all_activities[] = $activity;
    }
} catch (PDOException $e) {
    // activity_logs table might not exist, continue without it
}

// Sort all activities by date (most recent first)
usort($all_activities, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Limit to most recent 100 activities
$all_activities = array_slice($all_activities, 0, 100);

// Get profile picture path
$profile_pic_path = getProfilePicturePath($student['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenants Dashboard | Landlords&Tenant</title>
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
            height: 60px;
            width: 100px;
            margin-right: 0px;
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

        /* Activity Log */
        .activity-log-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            grid-column: 1 / -1;
        }

        .activity-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .activity-log-header h2 {
            font-size: 1.25rem;
            margin: 0;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .activity-filters {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            background-color: #f1f1f1;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .filter-btn.active {
            background-color: var(--primary-color);
            color: white;
        }

        .activity-list {
            max-height: 500px;
            overflow-y: auto;
            padding: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s;
        }

        .activity-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
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

        .activity-content h5 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
            color: var(--secondary-color);
        }

        .activity-content p {
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .activity-meta {
            display: flex;
            justify-content: space-between;
            color: #6c757d;
            font-size: 0.8rem;
        }

        .activity-type {
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: #f1f1f1;
        }

        .booking-type { background-color: rgba(52, 152, 219, 0.1); color: #3498db; }
        .payment-type { background-color: rgba(46, 204, 113, 0.1); color: #2ecc71; }
        .message-type { background-color: rgba(155, 89, 182, 0.1); color: #9b59b6; }
        .maintenance-type { background-color: rgba(241, 196, 15, 0.1); color: #f1c40f; }
        .review-type { background-color: rgba(230, 126, 34, 0.1); color: #e67e22; }
        .system-type { background-color: rgba(149, 165, 166, 0.1); color: #95a5a6; }

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

        /* Announcements */
        .announcement-item {
            padding: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: all var(--transition-speed);
        }

        .announcement-item:last-child {
            border-bottom: none;
        }

        .announcement-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .announcement-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .announcement-date {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .announcement-content {
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
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
            
            .activity-filters {
                flex-wrap: wrap;
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
                <img src="../assets/images/logo-removebg-preview.png" alt="Landlords&Tenant Logo">
                <span>landlords&Tenants</span>
            </a>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($student['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($student['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile/index.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="profile/index.php#settings-tab"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="logout.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt"></i> Logout
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
                <h2>Tenant Dashboard</h2>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="search/"><i class="fas fa-search"></i> <span class="menu-text">Find Accommodation</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">My Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="profile/index.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                    <li><a href="notification/"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
                    <li><a href="sms/"><i class="fas fa-sms"></i> <span class="menu-text">SMS Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Welcome back, <?= htmlspecialchars(explode(' ', $student['username'])[0]) ?>!</h1>
                <button class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="badge"><?= $unread_notifications ?></span>
                </button>
            </div>

            <div class="main-content-wrapper">
                <div class="left-column">
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Tenant Profile</h5>
                        </div>
                        <div class="profile-card-body">
                            <div class="profile-avatar-container">
                                <?php if (!empty($profile_pic_path)): ?>
                                    <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar" alt="Profile Picture">
                                <?php else: ?>
                                    <div class="profile-avatar-placeholder">
                                        <?= substr($student['username'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="profile-info">
                                <h4><?= htmlspecialchars($student['username']) ?></h4>
                                <p class="text-muted"><?= htmlspecialchars($student['email']) ?></p>
                                <?php if (!empty($student['phone_number'])): ?>
                                    <p class="text-muted"><?= htmlspecialchars($student['phone_number']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($student['location'])): ?>
                                    <p class="location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($student['location']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <a href="profile/index.php" class="btn btn-outline w-100">
                                <i class="fas fa-edit me-2"></i>Edit Profile
                            </a>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h2>Quick Actions</h2>
                        <div class="action-buttons">
                            <a href="search/" class="action-btn">
                                <i class="fas fa-search"></i> Find Accommodation
                            </a>
                            <a href="bookings/" class="action-btn">
                                <i class="fas fa-calendar"></i> View Bookings
                            </a>
                            <a href="payments/" class="action-btn">
                                <i class="fas fa-wallet"></i> Make Payment
                            </a>
                        </div>
                    </div>

                    <!-- Announcements -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Announcements</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_announcements)): ?>
                                <?php foreach($recent_announcements as $announcement): ?>
                                <div class="announcement-item">
                                    <div class="announcement-title"><?= htmlspecialchars($announcement['title']) ?></div>
                                    <div class="announcement-date">
                                        <?= date('M j, Y g:i a', strtotime($announcement['created_at'])) ?>
                                    </div>
                                    <div class="announcement-content">
                                        <?= htmlspecialchars(substr($announcement['message'], 0, 100)) ?>...
                                    </div>
                                    <a href="announcement.php?id=<?= $announcement['id'] ?>" class="btn btn-sm btn-outline mt-2">Read More</a>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No recent announcements</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="right-column">
                    <!-- Stats Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Active Bookings</h3>
                            <p><?= $active_bookings ?></p>
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="stat-card">
                            <h3>Pending Bookings</h3>
                            <p><?= $pending_bookings ?></p>
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-card">
                            <h3>Total Payments</h3>
                            <p>GHS<?= number_format($total_payments, 2) ?></p>
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>

                    <!-- Activity Log Section -->
                    <div class="activity-log-container">
                        <div class="activity-log-header">
                            <h2><i class="fas fa-history me-2"></i>Activity Log</h2>
                            <div class="activity-filters">
                                <button class="filter-btn active" data-filter="all">All</button>
                                <button class="filter-btn" data-filter="booking">Bookings</button>
                                <button class="filter-btn" data-filter="payment">Payments</button>
                                <button class="filter-btn" data-filter="message">Messages</button>
                                <button class="filter-btn" data-filter="maintenance">Maintenance</button>
                                <button class="filter-btn" data-filter="review">Reviews</button>
                            </div>
                        </div>
                        <div class="activity-list">
                            <?php if (!empty($all_activities)): ?>
                                <?php foreach($all_activities as $activity): 
                                    // Use the activity_type from our comprehensive query
                                    $activity_type = $activity['activity_type'];
                                    $type_class = $activity_type . '-type';
                                    
                                    // Set appropriate icons based on activity type
                                    switch($activity_type) {
                                        case 'booking':
                                            $icon = 'fas fa-calendar-check';
                                            break;
                                        case 'payment':
                                            $icon = 'fas fa-money-bill-wave';
                                            break;
                                        case 'message':
                                            $icon = 'fas fa-comments';
                                            break;
                                        case 'maintenance':
                                            $icon = 'fas fa-tools';
                                            break;
                                        case 'review':
                                            $icon = 'fas fa-star';
                                            $type_class = 'review-type';
                                            break;
                                        default:
                                            $icon = 'fas fa-info-circle';
                                            $type_class = 'system-type';
                                    }
                                ?>
                                <div class="activity-item" data-type="<?= $activity_type ?>">
                                    <div class="activity-icon">
                                        <i class="<?= $icon ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h5><?= htmlspecialchars($activity['action']) ?></h5>
                                        <?php if (!empty($activity['entity_type']) && $activity['entity_type'] !== 'system'): ?>
                                            <p>Related to: <?= htmlspecialchars(ucfirst($activity['entity_type'])) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($activity['activity_status']) && $activity['activity_status'] !== 'completed'): ?>
                                            <p class="text-muted">Status: <?= htmlspecialchars(ucfirst($activity['activity_status'])) ?></p>
                                        <?php endif; ?>
                                        <div class="activity-meta">
                                            <span class="activity-type <?= $type_class ?>">
                                                <?= ucfirst($activity_type) ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-clock me-1"></i>
                                                <?= date('M j, Y g:i a', strtotime($activity['created_at'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No activity recorded yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
            window.location.href = 'notification/';
        });

        // Resize handler
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });

        // Activity filter functionality
        document.querySelectorAll('.filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                const filter = this.getAttribute('data-filter');
                const activityItems = document.querySelectorAll('.activity-item');
                
                activityItems.forEach(item => {
                    if (filter === 'all') {
                        item.style.display = 'flex';
                    } else {
                        if (item.getAttribute('data-type') === filter) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    }
                });
            });
        });


                // Toggle tabs
        const tabs = document.querySelectorAll('.profile-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
    </script>
</body>
</html>
