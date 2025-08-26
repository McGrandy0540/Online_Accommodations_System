<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Function to get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return 'https://ui-avatars.com/api/?name=User&background=random';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

// Get user information
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$student_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Get active bookings for the student
$booking_stmt = $pdo->prepare("
    SELECT b.id AS booking_id, 
           p.id AS property_id,
           p.property_name, 
           pr.room_number 
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.user_id = ? AND b.status = 'paid'
    AND p.id IS NOT NULL  -- Ensure property exists
");
$booking_stmt->execute([$student_id]);
$active_bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_request'])) {
        // Create new maintenance request
        $property_id = $_POST['property'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $priority = $_POST['priority'];
        
        // Validate that property_id exists and belongs to user's bookings
        $validation_stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM bookings b
            JOIN property p ON b.property_id = p.id
            WHERE b.user_id = ? AND b.property_id = ? AND b.status = 'paid'
        ");
        $validation_stmt->execute([$student_id, $property_id]);
        $validation_result = $validation_stmt->fetch();
        
        if ($validation_result['count'] == 0) {
            $error_message = "Invalid property selection. Please select a property from your active bookings.";
        } else {
            try {
                $insert_stmt = $pdo->prepare("
                   INSERT INTO maintenance_requests 
                   (property_id, user_id, title, description, priority, status) 
                   VALUES (?, ?, ?, ?, ?, 'pending')
                 ");
                $insert_stmt->execute([$property_id, $student_id, $title, $description, $priority]);
                $success_message = "Maintenance request submitted successfully!";
            } catch (PDOException $e) {
                $error_message = "Error submitting maintenance request. Please try again.";
                error_log("Maintenance request error: " . $e->getMessage());
            }
        }
        
        // Handle file uploads
        if (!empty($_FILES['photos']['name'][0])) {
            $request_id = $pdo->lastInsertId();
            $upload_dir = '../../uploads/maintenance/' . $request_id . '/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_paths = [];
            foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                $file_name = basename($_FILES['photos']['name'][$key]);
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($tmp_name, $target_file)) {
                    $file_paths[] = $target_file;
                }
            }
        }
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } 
    elseif (isset($_POST['send_message'])) {
        $request_id = $_POST['request_id'];
        $message = trim($_POST['message']);
        
        if (!empty($message)) {
            // Insert message into maintenance_messages table
            $stmt = $pdo->prepare("INSERT INTO maintenance_messages 
                                  (maintenance_request_id, sender_id, sender_type, message) 
                                  VALUES (?, ?, 'student', ?)");
            $stmt->execute([$request_id, $student_id, $message]);
            
            // Update maintenance request timestamp
            $pdo->prepare("UPDATE maintenance_requests SET updated_at = NOW() WHERE id = ?")
                ->execute([$request_id]);
            
            // Add notification for property owner
            $pdo->prepare("INSERT INTO notifications (user_id, property_id, message, type)
                          SELECT po.owner_id, mr.property_id, 
                                 CONCAT('New message for maintenance request #', mr.id, ' from student'),
                                 'maintenance_message'
                          FROM maintenance_requests mr
                          JOIN property_owners po ON mr.property_id = po.property_id
                          WHERE mr.id = ?")
                ->execute([$request_id]);
                
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=message_sent&request_id=" . $request_id);
            exit();
        }
    }
    elseif (isset($_POST['submit_feedback'])) {
        // Submit feedback for a completed request
        $request_id = $_POST['request_id'];
        $rating = $_POST['rating'];
        $feedback = $_POST['feedback'];
        
        $feedback_stmt = $pdo->prepare("
            UPDATE maintenance_requests 
            SET rating = ?, feedback = ?, feedback_date = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $feedback_stmt->execute([$rating, $feedback, $request_id, $student_id]);
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=feedback");
        exit();
    }
}

// Get maintenance requests for the student with message counts
$active_requests = [];
$history_requests = [];
$feedback_requests = [];

// Active requests (pending and in_progress) with message counts
$request_stmt = $pdo->prepare("
    SELECT mr.*, p.property_name, pr.room_number,
           (SELECT COUNT(*) FROM maintenance_messages mm 
            WHERE mm.maintenance_request_id = mr.id AND mm.sender_type = 'owner' AND mm.is_read = FALSE) as unread_owner_messages,
           (SELECT COUNT(*) FROM maintenance_messages mm 
            WHERE mm.maintenance_request_id = mr.id) as total_messages
    FROM maintenance_requests mr
    JOIN property p ON mr.property_id = p.id
    LEFT JOIN property_rooms pr ON p.id = pr.property_id
    WHERE mr.user_id = ? AND mr.status IN ('pending', 'in_progress')
    ORDER BY mr.created_at DESC
");
$request_stmt->execute([$student_id]);
$active_requests = $request_stmt->fetchAll(PDO::FETCH_ASSOC);

// History requests (completed) with message counts
$history_stmt = $pdo->prepare("
    SELECT mr.*, p.property_name, pr.room_number,
           (SELECT COUNT(*) FROM maintenance_messages mm 
            WHERE mm.maintenance_request_id = mr.id) as total_messages
    FROM maintenance_requests mr
    JOIN property p ON mr.property_id = p.id
    LEFT JOIN property_rooms pr ON p.id = pr.property_id
    WHERE mr.user_id = ? AND mr.status = 'completed'
    ORDER BY mr.completed_at DESC
");
$history_stmt->execute([$student_id]);
$history_requests = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Feedback requests (completed without feedback)
$feedback_stmt = $pdo->prepare("
    SELECT mr.*, p.property_name, pr.room_number 
    FROM maintenance_requests mr
    JOIN property p ON mr.property_id = p.id
    LEFT JOIN property_rooms pr ON p.id = pr.property_id
    WHERE mr.user_id = ? AND mr.status = 'completed' AND mr.rating IS NULL
    ORDER BY mr.completed_at DESC
");
$feedback_stmt->execute([$student_id]);
$feedback_requests = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current tab from URL
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'new';
$allowed_tabs = ['new', 'active', 'history', 'feedback'];
if (!in_array($current_tab, $allowed_tabs)) {
    $current_tab = 'new';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests</title>
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

        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, var(--secondary-color), #1a2530);
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            overflow-y: auto;
            box-shadow: var(--box-shadow);
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 1.4rem;
            margin-bottom: 10px;
            color: white;
        }

        .toggle-btn {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .toggle-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary-color);
        }

        .sidebar-menu i {
            width: 25px;
            font-size: 18px;
            margin-right: 15px;
        }

        .menu-text {
            flex: 1;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 0;
            box-shadow: var(--box-shadow);
            text-align: center;
            margin-bottom: 30px;
            position: relative;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .user-info {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 30px;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
        }

        h1, h2, h3 {
            margin-bottom: 20px;
            color: var(--secondary-color);
        }

        .tabs {
            display: flex;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            text-decoration: none;
            color: var(--secondary-color);
        }

        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .tab:hover:not(.active) {
            background-color: var(--light-color);
        }

        .tab-content {
            display: none;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-bottom: 30px;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s ease;
            text-align: center;
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--primary-hover);
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .btn-success {
            background-color: var(--success-color);
        }

        .btn-warning {
            background-color: var(--warning-color);
        }

        .btn-danger {
            background-color: var(--accent-color);
        }

        .btn-info {
            background-color: var(--info-color);
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending {
            background-color: #ffeeba;
            color: #856404;
        }

        .status-in-progress {
            background-color: #b8daff;
            color: #004085;
        }

        .status-completed {
            background-color: #c3e6cb;
            color: #155724;
        }

        .priority-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .priority-low {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .priority-medium {
            background-color: #fff3cd;
            color: #856404;
        }

        .priority-high {
            background-color: #f8d7da;
            color: #721c24;
        }

        .priority-emergency {
            background-color: #721c24;
            color: white;
        }

        .request-item {
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .request-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            align-items: center;
        }

        .request-title {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-color);
        }

        .request-date {
            color: #777;
            font-size: 0.9rem;
        }

        .request-meta {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .request-content {
            margin-bottom: 15px;
            line-height: 1.7;
        }

        .request-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .feedback-container {
            background-color: var(--light-color);
            border-left: 4px solid var(--info-color);
            padding: 15px;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            margin-top: 15px;
        }

        .feedback-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--info-color);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #777;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        .empty-state h3 {
            color: #777;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .rating {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
        }

        .rating input {
            display: none;
        }

        .rating label {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .rating input:checked ~ label,
        .rating label:hover,
        .rating label:hover ~ label {
            color: var(--warning-color);
        }

        .rating input:checked + label {
            color: var(--warning-color);
        }
        
        .photo-preview {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .photo-preview img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--border-radius);
            border: 1px solid #ddd;
        }
        
        .ribbon {
            position: absolute;
            top: 10px;
            right: -30px;
            padding: 5px 30px;
            transform: rotate(45deg);
            font-size: 0.8rem;
            font-weight: bold;
            color: white;
            background: var(--accent-color);
            z-index: 1;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .menu-text, .sidebar-header h2 {
                display: none;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 15px 0;
            }
            
            .sidebar-menu i {
                margin-right: 0;
                font-size: 20px;
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                padding: 10px 0;
            }
            
            .sidebar-menu ul {
                display: flex;
                overflow-x: auto;
                padding: 10px 0;
            }
            
            .sidebar-menu li {
                margin: 0 5px;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .request-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .request-date {
                margin-top: 5px;
            }
            
            .container {
                padding: 10px;
            }
            
            .tab-content {
                padding: 20px 15px;
            }
            
            .user-info {
                position: static;
                justify-content: center;
                margin-top: 10px;
                margin-bottom: 20px;
            }
            
            .request-actions {
                flex-direction: column;
                gap: 8px;
            }
            
            .request-meta {
                flex-direction: column;
                gap: 8px;
            }
            
            .photo-preview img {
                width: 80px;
                height: 80px;
            }
        }

        @media (max-width: 576px) {
            .rating label {
                font-size: 1.5rem;
            }
            
            .btn {
                padding: 10px 15px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
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
                <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                <li><a href="../search/"><i class="fas fa-search"></i> <span class="menu-text">Find Accommodation</span></a></li>
                <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">My Bookings</span></a></li>
                <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                <li><a href="../reviews/" class="active"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                <li><a href="../maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                <li><a href="../profile/index.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                <li><a href="../notification/"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <header>
            <div class="container">
                <h1>Maintenance Requests</h1>
                <p>Report and track maintenance issues in your accommodation</p>
                
                <div class="user-info">
                    <img src="<?= getProfilePicturePath($user['profile_picture']) ?>" alt="Profile">
                    <span><?= htmlspecialchars($user['username']) ?></span>
                </div>
            </div>
        </header>

        <div class="container">
            <div class="tabs">
                <a href="?tab=new" class="tab <?= $current_tab === 'new' ? 'active' : '' ?>" data-tab="new">New Request</a>
                <a href="?tab=active" class="tab <?= $current_tab === 'active' ? 'active' : '' ?>" data-tab="active">Active Requests</a>
                <a href="?tab=history" class="tab <?= $current_tab === 'history' ? 'active' : '' ?>" data-tab="history">Request History</a>
                <a href="?tab=feedback" class="tab <?= $current_tab === 'feedback' ? 'active' : '' ?>" data-tab="feedback">Feedback</a>
            </div>

            <!-- New Request Tab -->
            <div id="new" class="tab-content <?= $current_tab === 'new' ? 'active' : '' ?>">
                <h2>Create New Maintenance Request</h2>
                <div class="card">
                    <?php if (empty($active_bookings)): ?>
                        <div class="ribbon">Booking Required</div>
                        <div class="alert alert-info">
                            <p>You need to have an active, paid booking to submit maintenance requests.</p>
                        </div>
                    <?php else: ?>
                        <form id="requestForm" method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="property">Select Property</label>
                                <select id="property" name="property" required>
                                    <option value="">Choose your accommodation...</option>
                                    <?php foreach ($active_bookings as $booking): ?>
                                        <option value="<?= $booking['property_id'] ?>">
                                            <?= htmlspecialchars($booking['property_name']) ?> - 
                                            <?= $booking['room_number'] ? 'Room ' . htmlspecialchars($booking['room_number']) : 'Property' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="title">Issue Title</label>
                                <input type="text" id="title" name="title" placeholder="Brief description of the issue" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="6" placeholder="Please describe the issue in detail..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="priority">Priority Level</label>
                                <select id="priority" name="priority" required>
                                    <option value="">Select priority level...</option>
                                    <option value="low">Low - Minor issue, not urgent</option>
                                    <option value="medium">Medium - Needs attention soon</option>
                                    <option value="high">High - Urgent issue</option>
                                    <option value="emergency">Emergency - Immediate attention required</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="photos">Upload Photos (Optional)</label>
                                <input type="file" id="photos" name="photos[]" accept="image/*" multiple>
                                <small>You can upload up to 5 photos</small>
                                <div class="photo-preview" id="photoPreview"></div>
                            </div>
                            
                            <button type="submit" name="submit_request" class="btn btn-block">Submit Request</button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> How to Submit a Maintenance Request</h3>
                    <p>Please follow these guidelines when submitting a maintenance request:</p>
                    <ul style="padding-left: 20px; margin-top: 10px;">
                        <li>Describe the issue in as much detail as possible</li>
                        <li>Include the location of the problem within your accommodation</li>
                        <li>Upload clear photos if possible to help us understand the issue</li>
                        <li>Select the appropriate priority level based on urgency</li>
                        <li>Emergency issues include: flooding, electrical hazards, security issues</li>
                    </ul>
                </div>
            </div>

            <!-- Active Requests Tab -->
            <div id="active" class="tab-content <?= $current_tab === 'active' ? 'active' : '' ?>">
                <h2>Active Maintenance Requests</h2>
                
                <div class="requests-list">
                    <?php if (!empty($active_requests)): ?>
                        <div class="card">
                            <?php foreach ($active_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <span class="request-title"><?= htmlspecialchars($request['title']) ?></span>
                                        <span class="request-date">Submitted: <?= date('d M Y', strtotime($request['created_at'])) ?></span>
                                    </div>
                                    <div class="request-meta">
                                        <span class="status-badge 
                                            <?= $request['status'] === 'pending' ? 'status-pending' : '' ?>
                                            <?= $request['status'] === 'in_progress' ? 'status-in-progress' : '' ?>
                                        ">
                                            <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                                        </span>
                                        <span class="priority-badge 
                                            <?= $request['priority'] === 'low' ? 'priority-low' : '' ?>
                                            <?= $request['priority'] === 'medium' ? 'priority-medium' : '' ?>
                                            <?= $request['priority'] === 'high' ? 'priority-high' : '' ?>
                                            <?= $request['priority'] === 'emergency' ? 'priority-emergency' : '' ?>
                                        ">
                                            <?= ucfirst($request['priority']) ?> Priority
                                        </span>
                                        <span>
                                            <?= htmlspecialchars($request['property_name']) ?> - 
                                            <?= $request['room_number'] ? 'Room ' . htmlspecialchars($request['room_number']) : 'Property' ?>
                                        </span>
                                    </div>
                                    <div class="request-content">
                                        <p><?= htmlspecialchars($request['description']) ?></p>
                                    </div>
                                    <!-- Messages Section -->
                                    <?php if ($request['total_messages'] > 0): ?>
                                        <div class="messages-section" style="margin-top: 15px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                                <h6 style="margin: 0;">
                                                    <i class="fas fa-comments"></i> Messages (<?= $request['total_messages'] ?>)
                                                    <?php if ($request['unread_owner_messages'] > 0): ?>
                                                        <span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;"><?= $request['unread_owner_messages'] ?> new</span>
                                                    <?php endif; ?>
                                                </h6>
                                            </div>
                                            <button class="btn btn-info" style="font-size: 0.85rem; padding: 8px 15px;" onclick="toggleMessages(<?= $request['id'] ?>)">
                                                <i class="fas fa-eye"></i> View Messages
                                            </button>
                                        </div>
                                        
                                        <!-- Messages Container -->
                                        <div id="messages-<?= $request['id'] ?>" style="display: none; margin-top: 15px; border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                                            <h6>Conversation with Property Owner</h6>
                                            <div style="max-height: 300px; overflow-y: auto; margin-bottom: 15px;">
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
                                                    <div style="margin-bottom: 15px; <?= $message['sender_type'] === 'student' ? 'text-align: right;' : 'text-align: left;' ?>">
                                                        <div style="display: inline-block; max-width: 70%; padding: 10px 15px; border-radius: 15px; <?= $message['sender_type'] === 'student' ? 'background: #007bff; color: white;' : 'background: #e9ecef; color: #333;' ?>">
                                                            <div style="margin-bottom: 5px; font-size: 0.8rem; <?= $message['sender_type'] === 'student' ? 'color: rgba(255,255,255,0.8);' : 'color: #666;' ?>">
                                                                <strong><?= htmlspecialchars($message['username']) ?></strong>
                                                                <?= $message['sender_type'] === 'student' ? '(You)' : '(Owner)' ?>
                                                                - <?= date('M j, Y g:i A', strtotime($message['created_at'])) ?>
                                                            </div>
                                                            <div>
                                                                <?= nl2br(htmlspecialchars($message['message'])) ?>
                                                            </div>
                                                            <?php if ($message['sender_type'] === 'owner' && !$message['is_read']): ?>
                                                                <div style="margin-top: 5px;">
                                                                    <small style="background: #ffc107; color: #333; padding: 2px 6px; border-radius: 10px; font-size: 0.7rem;">New</small>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            
                                            <!-- Send Message Form -->
                                            <form method="POST" style="display: flex; gap: 10px;">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <textarea name="message" placeholder="Type your message..." style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: none;" rows="2" required></textarea>
                                                <button type="submit" name="send_message" class="btn" style="align-self: flex-end;">
                                                    <i class="fas fa-paper-plane"></i> Send
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="request-actions">
                                        <button class="btn btn-primary" onclick="showMessageModal(<?= $request['id'] ?>, '<?= htmlspecialchars($request['title']) ?>')">
                                            <i class="fas fa-comment"></i> Send Message
                                        </button>
                                        <button class="btn btn-info">Update Request</button>
                                        <button class="btn btn-danger">Cancel Request</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i>🔧</i>
                            <h3>No Active Requests</h3>
                            <p>You don't have any active maintenance requests at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request History Tab -->
            <div id="history" class="tab-content <?= $current_tab === 'history' ? 'active' : '' ?>">
                <h2>Maintenance Request History</h2>
                
                <div class="requests-list">
                    <?php if (!empty($history_requests)): ?>
                        <div class="card">
                            <?php foreach ($history_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <span class="request-title"><?= htmlspecialchars($request['title']) ?></span>
                                        <span class="request-date">Completed: <?= date('d M Y', strtotime($request['completed_at'])) ?></span>
                                    </div>
                                    <div class="request-meta">
                                        <span class="status-badge status-completed">Completed</span>
                                        <span class="priority-badge 
                                            <?= $request['priority'] === 'low' ? 'priority-low' : '' ?>
                                            <?= $request['priority'] === 'medium' ? 'priority-medium' : '' ?>
                                            <?= $request['priority'] === 'high' ? 'priority-high' : '' ?>
                                            <?= $request['priority'] === 'emergency' ? 'priority-emergency' : '' ?>
                                        ">
                                            <?= ucfirst($request['priority']) ?> Priority
                                        </span>
                                        <span>
                                            <?= htmlspecialchars($request['property_name']) ?> - 
                                            <?= $request['room_number'] ? 'Room ' . htmlspecialchars($request['room_number']) : 'Property' ?>
                                        </span>
                                    </div>
                                    <div class="request-content">
                                        <p><?= htmlspecialchars($request['description']) ?></p>
                                    </div>
                                    <?php if ($request['rating']): ?>
                                        <div class="feedback-container">
                                            <div class="feedback-header">
                                                <span>Your Feedback</span>
                                                <span>Rating: <?= str_repeat('★', $request['rating']) . str_repeat('☆', 5 - $request['rating']) ?></span>
                                            </div>
                                            <p><?= htmlspecialchars($request['feedback']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i>📜</i>
                            <h3>No Request History</h3>
                            <p>You haven't submitted any maintenance requests yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Feedback Tab -->
            <div id="feedback" class="tab-content <?= $current_tab === 'feedback' ? 'active' : '' ?>">
                <h2>Provide Feedback on Completed Requests</h2>
                
                <div class="feedback-list">
                    <?php if (!empty($feedback_requests)): ?>
                        <?php foreach ($feedback_requests as $request): ?>
                            <div class="card">
                                <div class="request-item">
                                    <div class="request-header">
                                        <span class="request-title"><?= htmlspecialchars($request['title']) ?></span>
                                        <span class="request-date">Completed: <?= date('d M Y', strtotime($request['completed_at'])) ?></span>
                                    </div>
                                    <div class="request-meta">
                                        <span class="status-badge status-completed">Completed</span>
                                        <span class="priority-badge 
                                            <?= $request['priority'] === 'low' ? 'priority-low' : '' ?>
                                            <?= $request['priority'] === 'medium' ? 'priority-medium' : '' ?>
                                            <?= $request['priority'] === 'high' ? 'priority-high' : '' ?>
                                            <?= $request['priority'] === 'emergency' ? 'priority-emergency' : '' ?>
                                        ">
                                            <?= ucfirst($request['priority']) ?> Priority
                                        </span>
                                        <span>
                                            <?= htmlspecialchars($request['property_name']) ?> - 
                                            <?= $request['room_number'] ? 'Room ' . htmlspecialchars($request['room_number']) : 'Property' ?>
                                        </span>
                                    </div>
                                    <div class="request-content">
                                        <p><?= htmlspecialchars($request['description']) ?></p>
                                    </div>
                                    
                                    <form class="feedback-form" method="POST">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <div class="alert alert-info">
                                            <p>Please provide feedback on how this maintenance request was handled.</p>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>How satisfied are you with the service?</label>
                                            <div class="rating">
                                                <input type="radio" id="star5_<?= $request['id'] ?>" name="rating" value="5" required>
                                                <label for="star5_<?= $request['id'] ?>">★</label>
                                                <input type="radio" id="star4_<?= $request['id'] ?>" name="rating" value="4">
                                                <label for="star4_<?= $request['id'] ?>">★</label>
                                                <input type="radio" id="star3_<?= $request['id'] ?>" name="rating" value="3">
                                                <label for="star3_<?= $request['id'] ?>">★</label>
                                                <input type="radio" id="star2_<?= $request['id'] ?>" name="rating" value="2">
                                                <label for="star2_<?= $request['id'] ?>">★</label>
                                                <input type="radio" id="star1_<?= $request['id'] ?>" name="rating" value="1">
                                                <label for="star1_<?= $request['id'] ?>">★</label>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="feedback-text">Your Feedback</label>
                                            <textarea id="feedback-text" name="feedback" rows="4" placeholder="Share your experience with how this request was handled..." required></textarea>
                                        </div>
                                        
                                        <button type="submit" name="submit_feedback" class="btn btn-success">Submit Feedback</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i>✅</i>
                            <h3>No Requests Need Feedback</h3>
                            <p>You don't have any completed requests that require feedback at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; width: 90%; max-width: 500px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h5 id="modalTitle">Send Message to Owner</h5>
                <button onclick="closeMessageModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" id="modalRequestId" name="request_id">
                <div style="margin-bottom: 15px; padding: 10px; background: #e3f2fd; border-radius: 4px;">
                    <strong>Request:</strong> <span id="modalRequestTitle"></span>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Your Message</label>
                    <textarea name="message" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Type your message to the property owner..." required></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeMessageModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" name="send_message" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function(e) {
                // Prevent default for anchor links
                if (this.tagName === 'A') {
                    e.preventDefault();
                }
                
                // Remove active class from all tabs and content
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Show corresponding content
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Photo preview functionality
        document.getElementById('photos')?.addEventListener('change', function(e) {
            const preview = document.getElementById('photoPreview');
            preview.innerHTML = '';
            
            for (const file of this.files) {
                if (file.type.match('image.*')) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        preview.appendChild(img);
                    }
                    
                    reader.readAsDataURL(file);
                }
            }
        });

        // Toggle sidebar on mobile
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('sidebarToggle').addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.toggle('collapsed');
                
                if (sidebar.classList.contains('collapsed')) {
                    this.innerHTML = '<i class="fas fa-chevron-right"></i>';
                } else {
                    this.innerHTML = '<i class="fas fa-chevron-left"></i>';
                }
            });
        });

        // Message functionality
        function toggleMessages(requestId) {
            const messagesDiv = document.getElementById('messages-' + requestId);
            if (messagesDiv.style.display === 'none') {
                messagesDiv.style.display = 'block';
            } else {
                messagesDiv.style.display = 'none';
            }
        }

        function showMessageModal(requestId, requestTitle) {
            document.getElementById('modalRequestId').value = requestId;
            document.getElementById('modalRequestTitle').textContent = requestTitle;
            document.getElementById('messageModal').style.display = 'block';
        }

        function closeMessageModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('messageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeMessageModal();
            }
        });
    </script>
</body>
</html>
