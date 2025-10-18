<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get user data
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Admin';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get booking ID from POST or GET
$booking_id = $_POST['booking_id'] ?? $_GET['id'] ?? 0;
$action = $_POST['action'] ?? '';

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

// Fetch user details
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

$profile_pic_path = getProfilePicturePath($user['profile_picture'] ?? '');

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please try again.";
    } else {
        $booking_id = $_POST['booking_id'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        try {
            if ($action === 'approve') {
                // Update booking status to confirmed
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $result = $stmt->execute([$booking_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Get booking details for notification
                    $stmt = $pdo->prepare("
                        SELECT b.user_id, p.property_name, u_student.username, u_student.email,
                               p.owner_id, u_owner.username as owner_name,
                               pr.room_number, pr.id as room_id, pr.capacity
                        FROM bookings b 
                        JOIN property p ON b.property_id = p.id 
                        JOIN users u_student ON b.user_id = u_student.id 
                        JOIN users u_owner ON p.owner_id = u_owner.id
                        LEFT JOIN property_rooms pr ON b.room_id = pr.id
                        WHERE b.id = ?
                    ");
                    $stmt->execute([$booking_id]);
                    $booking = $stmt->fetch();
                    
                    if ($booking) {
                        // Update room occupancy if room is specified
                        if (!empty($booking['room_id'])) {
                            // Get current occupancy first
                            $stmt = $pdo->prepare("SELECT current_occupancy, capacity FROM property_rooms WHERE id = ?");
                            $stmt->execute([$booking['room_id']]);
                            $room = $stmt->fetch();
                            
                            if ($room && $room['current_occupancy'] < $room['capacity']) {
                                $new_occupancy = $room['current_occupancy'] + 1;
                                $stmt = $pdo->prepare("
                                    UPDATE property_rooms 
                                    SET current_occupancy = ?, 
                                        available_spots = capacity - ?,
                                        status = CASE WHEN ? >= capacity THEN 'occupied' ELSE 'available' END
                                    WHERE id = ?
                                ");
                                $stmt->execute([$new_occupancy, $new_occupancy, $new_occupancy, $booking['room_id']]);
                            }
                        }
                        
                        // Create notification for student
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, admin_id, booking_id, message, type, notification_type, created_at) 
                            VALUES (?, ?, ?, ?, 'booking_approved', 'in_app', CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            $booking['user_id'],
                            $user_id,
                            $booking_id,
                            "Your booking for {$booking['property_name']} has been approved"
                        ]);
                        
                        // Create notification for property owner
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, admin_id, booking_id, message, type, notification_type, created_at) 
                            VALUES (?, ?, ?, ?, 'booking_confirmed', 'in_app', CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            $booking['owner_id'],
                            $user_id,
                            $booking_id,
                            "New booking confirmed for {$booking['property_name']} by {$booking['username']}"
                        ]);
                        
                        // Send email notification to student (if email queue is enabled)
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO email_queue (user_id, email_data, created_at) 
                                VALUES (?, ?, CURRENT_TIMESTAMP)
                            ");
                            $email_data = json_encode([
                                'to' => $booking['email'],
                                'subject' => 'Booking Approved - ' . $booking['property_name'],
                                'message' => "Dear {$booking['username']}, your booking for {$booking['property_name']} has been approved by the admin. You can now proceed with payment.",
                                'type' => 'booking_approved'
                            ]);
                            $stmt->execute([$booking['user_id'], $email_data]);
                        } catch (Exception $e) {
                            error_log("Email queue error: " . $e->getMessage());
                        }
                    }
                    
                    $success = "Booking approved successfully! Student and property owner have been notified.";
                } else {
                    $error = "Failed to update booking status. Booking may not exist or is already processed.";
                }
                
            } elseif ($action === 'reject') {
                // Update booking status to cancelled
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $result = $stmt->execute([$booking_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Get booking details for notification
                    $stmt = $pdo->prepare("
                        SELECT b.user_id, p.property_name, u_student.username, u_student.email
                        FROM bookings b 
                        JOIN property p ON b.property_id = p.id 
                        JOIN users u_student ON b.user_id = u_student.id 
                        WHERE b.id = ?
                    ");
                    $stmt->execute([$booking_id]);
                    $booking = $stmt->fetch();
                    
                    if ($booking) {
                        // Create notification for student
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, admin_id, booking_id, message, type, notification_type, created_at) 
                            VALUES (?, ?, ?, ?, 'booking_rejected', 'in_app', CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            $booking['user_id'],
                            $user_id,
                            $booking_id,
                            "Your booking for {$booking['property_name']} has been rejected"
                        ]);
                    }
                    
                    $success = "Booking rejected successfully! Student has been notified.";
                } else {
                    $error = "Failed to update booking status. Booking may not exist or is already processed.";
                }
            }
            
            // Log admin action
            if (isset($success)) {
                $stmt = $pdo->prepare("
                    INSERT INTO admin_actions (admin_id, action_type, target_id, target_type, details, created_at) 
                    VALUES (?, ?, ?, 'booking', ?, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    $user_id,
                    $action,
                    $booking_id,
                    "Admin {$username} {$action}d booking ID {$booking_id}"
                ]);
                
                // Redirect back to bookings page after successful action
                header("Location: index.php?success=" . urlencode($success));
                exit();
            }
            
        } catch (PDOException $e) {
            error_log("Approval Error: " . $e->getMessage());
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get booking details
$booking_details = [];
$back_url = "index.php";

if (!empty($booking_id)) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, p.property_name, p.location, p.price,
                   u_student.username as student_name, 
                   u_student.email as student_email,
                   u_student.phone_number as student_phone,
                   u_owner.username as owner_name,
                   u_owner.email as owner_email,
                   pr.room_number,
                   pr.gender as room_gender,
                   pr.capacity as room_capacity,
                   pr.current_occupancy as room_occupancy
            FROM bookings b
            JOIN property p ON b.property_id = p.id
            JOIN users u_student ON b.user_id = u_student.id
            JOIN users u_owner ON p.owner_id = u_owner.id
            LEFT JOIN property_rooms pr ON b.room_id = pr.id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking_details) {
            $error = "Booking not found with ID: " . $booking_id;
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $error = "Failed to load booking details.";
    }
}

// Show success message from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Booking - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS styles remain the same */
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
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            background-color: rgba(0, 0, 0, 0.1);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #b8c7ce;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .sidebar-menu li a:hover, 
        .sidebar-menu li a.active {
            color: white;
            background-color: rgba(0, 0, 0, 0.2);
            border-left-color: var(--primary-color);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            font-size: 16px;
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
            flex-wrap: wrap;
            gap: 15px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            color: var(--secondary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .breadcrumb {
            list-style: none;
            display: flex;
            font-size: 14px;
            color: #6c757d;
            flex-wrap: wrap;
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
            padding: 20px;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
        }

        .card-header h2 {
            font-size: 20px;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 25px;
        }

        .item-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 5px;
            display: block;
        }

        .detail-value {
            padding: 10px 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .bg-pending { background-color: #fff3cd; color: #856404; }
        .bg-confirmed { background-color: #d4edda; color: #155724; }
        .bg-cancelled { background-color: #f8d7da; color: #721c24; }

        .approval-form {
            background-color: #f8f9fa;
            padding: 25px;
            border-radius: var(--border-radius);
            border: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 25px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border: none;
            text-align: center;
            justify-content: center;
            flex: 1;
            min-width: 120px;
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border-color: var(--accent-color);
            color: #721c24;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ddd;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary-color);
            padding: 5px;
        }

        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .menu-toggle {
                display: block;
            }

            .item-details {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                flex: none;
            }
        }

        @media (max-width: 768px) {
            .top-nav {
                flex-direction: column;
                align-items: flex-start;
            }

            .user-profile {
                align-self: flex-end;
            }

            .card-body {
                padding: 15px;
            }

            .page-header h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .card-header h2 {
                font-size: 18px;
            }

            .detail-value {
                padding: 8px 12px;
                font-size: 14px;
            }

            .btn {
                padding: 10px 15px;
                font-size: 13px;
            }
        }

        /* Animation for alerts */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert {
            animation: slideIn 0.3s ease-out;
        }

        /* Loading state */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
                    <li><a href="index.php" class="active"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="../reports/financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="../reports/occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li>
                        <form action="../logout.php" method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" class="sidebar-logout-btn" style="background: none; border: none; color: #b8c7ce; padding: 12px 20px; width: 100%; text-align: left; cursor: pointer; border-left: 3px solid transparent;">
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
                    <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile" class="user-avatar">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="fas fa-calendar-check"></i>
                    Approve Tenant Booking
                </h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li><a href="<?= $back_url ?>">Bookings</a></li>
                    <li>Approve Booking</li>
                </ul>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <?php if (empty($booking_details)): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Booking Not Found</h3>
                            <p>The booking you're trying to approve could not be found.</p>
                            <a href="<?= $back_url ?>" class="btn btn-outline">
                                <i class="fas fa-arrow-left"></i> Back to Bookings
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Booking Details -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-info-circle"></i>
                            Booking Details - #<?= $booking_details['id'] ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="item-details">
                            <!-- Student Information -->
                            <div>
                                <h3 style="color: var(--secondary-color); margin-bottom: 15px; border-bottom: 2px solid var(--primary-color); padding-bottom: 8px;">
                                    <i class="fas fa-user-graduate"></i> Tenant Information
                                </h3>
                                <div class="detail-group">
                                    <span class="detail-label">Tenant Name</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['student_name']) ?></div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Email</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['student_email']) ?></div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Phone</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['student_phone']) ?></div>
                                </div>
                            </div>

                            <!-- Property Information -->
                            <div>
                                <h3 style="color: var(--secondary-color); margin-bottom: 15px; border-bottom: 2px solid var(--primary-color); padding-bottom: 8px;">
                                    <i class="fas fa-home"></i> Property Information
                                </h3>
                                <div class="detail-group">
                                    <span class="detail-label">Property Name</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['property_name']) ?></div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Location</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['location']) ?></div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Room Details</span>
                                    <div class="detail-value">
                                        <?php if (!empty($booking_details['room_number'])): ?>
                                            Room <?= htmlspecialchars($booking_details['room_number']) ?>
                                            <?php if (!empty($booking_details['room_gender'])): ?>
                                                <br><small>(<?= ucfirst($booking_details['room_gender']) ?> room)</small>
                                            <?php endif; ?>
                                            <?php if (!empty($booking_details['room_capacity'])): ?>
                                                <br><small>Capacity: <?= $booking_details['room_occupancy'] ?>/<?= $booking_details['room_capacity'] ?> occupied</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Not specified
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Property Owner</span>
                                    <div class="detail-value"><?= htmlspecialchars($booking_details['owner_name']) ?></div>
                                </div>
                            </div>

                            <!-- Booking Details -->
                            <div>
                                <h3 style="color: var(--secondary-color); margin-bottom: 15px; border-bottom: 2px solid var(--primary-color); padding-bottom: 8px;">
                                    <i class="fas fa-calendar-alt"></i> Booking Details
                                </h3>
                                <div class="detail-group">
                                    <span class="detail-label">Booking Date</span>
                                    <div class="detail-value"><?= date('M j, Y g:i A', strtotime($booking_details['booking_date'])) ?></div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Stay Period</span>
                                    <div class="detail-value">
                                        <?= date('M j, Y', strtotime($booking_details['start_date'])) ?> to 
                                        <?= date('M j, Y', strtotime($booking_details['end_date'])) ?>
                                    </div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Duration</span>
                                    <div class="detail-value"><?= $booking_details['duration_months'] ?> months</div>
                                </div>
                                <div class="detail-group">
                                    <span class="detail-label">Current Status</span>
                                    <div class="detail-value">
                                        <?php 
                                            $status_class = 'bg-pending';
                                            if ($booking_details['status'] === 'confirmed') $status_class = 'bg-confirmed';
                                            if ($booking_details['status'] === 'cancelled') $status_class = 'bg-cancelled';
                                        ?>
                                        <span class="status-badge <?= $status_class ?>">
                                            <?= ucfirst($booking_details['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Approval Form -->
                        <?php if ($booking_details['status'] === 'pending'): ?>
                        <div class="approval-form">
                            <h3 style="margin-bottom: 20px; color: var(--secondary-color);">
                                <i class="fas fa-tasks"></i> Approval Actions
                            </h3>
                            <form method="POST" id="approvalForm">
                                <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                
                                <div class="form-group">
                                    <label class="form-label" for="notes">
                                        <i class="fas fa-sticky-note"></i> Notes (Optional)
                                    </label>
                                    <textarea 
                                        class="form-control" 
                                        id="notes" 
                                        name="notes" 
                                        placeholder="Add any notes or comments about this booking approval..."
                                    ></textarea>
                                </div>

                                <div class="btn-group">
                                    <button type="submit" name="action" value="approve" class="btn btn-success">
                                        <i class="fas fa-check"></i> Approve Booking
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                                        <i class="fas fa-times"></i> Reject Booking
                                    </button>
                                    <a href="<?= $back_url ?>" class="btn btn-outline">
                                        <i class="fas fa-arrow-left"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            This booking is already <strong><?= $booking_details['status'] ?></strong> and cannot be modified.
                            <a href="<?= $back_url ?>" class="btn btn-outline" style="margin-left: 15px;">
                                <i class="fas fa-arrow-left"></i> Back to Bookings
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Handle form submission with loading state
        document.getElementById('approvalForm')?.addEventListener('submit', function(e) {
            // Remove the loading state code that might prevent form submission
            const buttons = this.querySelectorAll('button[type="submit"]');
            buttons.forEach(btn => {
                btn.disabled = true;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner"></span> Processing...';
                
                // Revert after 10 seconds if still processing (fallback)
                setTimeout(() => {
                    if (btn.disabled) {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                }, 10000);
            });
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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>