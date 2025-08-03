<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get booking ID from URL
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$booking_id) {
    header("Location: index.php");
    exit();
}

// Get booking details
$stmt = $pdo->prepare("
    SELECT 
        b.*, 
        p.property_name, 
        p.location AS property_location,
        p.description AS property_description,
        p.price AS property_price,
        pr.room_number,
        pr.gender AS room_gender,
        pr.capacity AS room_capacity,
        u_student.username AS student_name, 
        u_student.email AS student_email,
        u_student.phone_number AS student_phone,
        u_student.profile_picture AS student_avatar,
        u_owner.username AS owner_name,
        u_owner.email AS owner_email,
        u_owner.phone_number AS owner_phone,
        u_owner.profile_picture AS owner_avatar,
        py.amount AS payment_amount,
        py.status AS payment_status,
        py.transaction_id AS payment_transaction,
        py.payment_method AS payment_method,
        py.created_at AS payment_date
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u_student ON b.user_id = u_student.id
    JOIN users u_owner ON p.owner_id = u_owner.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    LEFT JOIN payments py ON b.id = py.booking_id
    WHERE b.id = ?
");

$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header("Location: index.php");
    exit();
}

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $new_status = $_POST['status'];
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    $update_stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = ?, admin_notes = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    
    $update_stmt->execute([$new_status, $admin_notes, $booking_id]);
    
    // Refresh booking data
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Add notification to student
    $message = "Your booking #{$booking_id} status has been updated to " . ucfirst($new_status);
    $notif_stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, message, type, notification_type, created_at)
        VALUES (?, ?, 'booking_update', 'in_app', NOW())
    ");
    $notif_stmt->execute([$booking['user_id'], $message]);
    
    // Add notification to owner
    $owner_message = "Booking #{$booking_id} for your property has been updated to " . ucfirst($new_status);
    $notif_stmt->execute([$booking['owner_id'], $owner_message]);
    
    $success_message = "Booking status updated successfully!";
}

// Get booking images
$image_stmt = $pdo->prepare("
    SELECT pi.image_url 
    FROM property_images pi
    WHERE pi.property_id = ?
");
$image_stmt->execute([$booking['property_id']]);
$property_images = $image_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// If no images, use a default
if (empty($property_images)) {
    $property_images = ['../../assets/images/default-property.jpg'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details - Hostel Admin</title>
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

        .booking-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 992px) {
            .booking-details {
                grid-template-columns: 1fr;
            }
        }

        .detail-section {
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }

        .detail-section h3 {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: var(--secondary-color);
        }

        .detail-row {
            display: flex;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f5f5f5;
        }

        .detail-label {
            flex: 0 0 40%;
            font-weight: 600;
            color: #666;
        }

        .detail-value {
            flex: 1;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }
        
        .badge-confirmed {
            background-color: var(--info-color);
            color: white;
        }
        
        .badge-paid {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-cancelled {
            background-color: var(--accent-color);
            color: white;
        }

        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            border: none;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
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

        .text-center {
            text-align: center;
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
            .card-header {
                flex-direction: column;
                align-items: flex-start;
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
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #eee;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .bg-pending { background-color: #fff3cd; color: #856404; }
        .bg-confirmed { background-color: #cce5ff; color: #004085; }
        .bg-paid { background-color: #d4edda; color: #155724; }
        .bg-cancelled { background-color: #f8d7da; color: #721c24; }
        
        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
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
        
        .property-images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .property-image {
            width: 100%;
            height: 120px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: var(--box-shadow);
            transition: transform 0.3s;
        }
        
        .property-image:hover {
            transform: scale(1.05);
        }
        
        .status-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: var(--border-radius);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 6px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #ddd;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 5px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid white;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: #6c757d;
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
                    <li><a href="index.php" class="active"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
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
                <h1>Booking Details</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li><a href="index.php">Bookings</a></li>
                    <li>#<?= $booking_id ?></li>
                </ul>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                </div>
            <?php endif; ?>

            <!-- Booking Overview -->
            <div class="card">
                <div class="card-header">
                    <h2>Booking #<?= $booking_id ?></h2>
                    <?php 
                        $status_class = '';
                        switch ($booking['status']) {
                            case 'pending': $status_class = 'bg-pending'; break;
                            case 'confirmed': $status_class = 'bg-confirmed'; break;
                            case 'paid': $status_class = 'bg-paid'; break;
                            case 'cancelled': $status_class = 'bg-cancelled'; break;
                        }
                    ?>
                    <span class="status-badge <?= $status_class ?>">
                        <?= ucfirst($booking['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="booking-details">
                        <!-- Property Details -->
                        <div class="detail-section">
                            <h3><i class="fas fa-home"></i> Property Details</h3>
                            
                            <div class="detail-row">
                                <div class="detail-label">Property Name:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['property_name']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Location:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['property_location']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Description:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['property_description']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Price per Student:</div>
                                <div class="detail-value">GHS <?= number_format($booking['property_price'], 2) ?></div>
                            </div>
                            
                            <?php if (!empty($booking['room_number'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Room Number:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['room_number']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Room Gender:</div>
                                <div class="detail-value"><?= ucfirst($booking['room_gender']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Room Capacity:</div>
                                <div class="detail-value"><?= $booking['room_capacity'] ?> students</div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="property-images">
                                <?php foreach ($property_images as $image): ?>
                                    <img src="../../uploads/<?= htmlspecialchars($image) ?>" class="property-image" alt="Property Image">
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Booking Information -->
                        <div class="detail-section">
                            <h3><i class="fas fa-calendar-alt"></i> Booking Information</h3>
                            
                            <div class="detail-row">
                                <div class="detail-label">Booking Date:</div>
                                <div class="detail-value">
                                    <?= date('M j, Y H:i', strtotime($booking['booking_date'])) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Check-in Date:</div>
                                <div class="detail-value">
                                    <?= date('M j, Y', strtotime($booking['start_date'])) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Check-out Date:</div>
                                <div class="detail-value">
                                    <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Duration:</div>
                                <div class="detail-value">
                                    <?= $booking['duration_months'] ?> months
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Special Requests:</div>
                                <div class="detail-value">
                                    <?= !empty($booking['special_requests']) ? htmlspecialchars($booking['special_requests']) : 'None' ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Cancellation Policy:</div>
                                <div class="detail-value">
                                    <?= ucfirst($booking['cancellation_policy']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Admin Notes:</div>
                                <div class="detail-value">
                                    <?= !empty($booking['admin_notes']) ? htmlspecialchars($booking['admin_notes']) : 'None' ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Student Information -->
                        <div class="detail-section">
                            <h3><i class="fas fa-user-graduate"></i> Student Information</h3>
                            
                            <div class="detail-row">
                                <div class="detail-label">Name:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['student_name']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Email:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['student_email']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Phone:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['student_phone']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Profile:</div>
                                <div class="detail-value">
                                    <?php if (!empty($booking['student_avatar'])): ?>
                                        <img src="../../<?= htmlspecialchars($booking['student_avatar']) ?>" class="user-avatar">
                                    <?php else: ?>
                                        <div class="user-avatar-placeholder">
                                            <?= substr($booking['student_name'], 0, 1) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Owner Information -->
                        <div class="detail-section">
                            <h3><i class="fas fa-user-tie"></i> Owner Information</h3>
                            
                            <div class="detail-row">
                                <div class="detail-label">Name:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['owner_name']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Email:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['owner_email']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Phone:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['owner_phone']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Profile:</div>
                                <div class="detail-value">
                                    <?php if (!empty($booking['owner_avatar'])): ?>
                                        <img src="../../<?= htmlspecialchars($booking['owner_avatar']) ?>" class="user-avatar">
                                    <?php else: ?>
                                        <div class="user-avatar-placeholder">
                                            <?= substr($booking['owner_name'], 0, 1) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Information -->
                        <div class="detail-section">
                            <h3><i class="fas fa-money-bill-wave"></i> Payment Information</h3>
                            
                            <?php if ($booking['payment_amount']): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Amount:</div>
                                    <div class="detail-value">
                                        GHS <?= number_format($booking['payment_amount'], 2) ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label">Status:</div>
                                    <div class="detail-value">
                                        <span class="status-badge <?= $booking['payment_status'] === 'completed' ? 'bg-paid' : 'bg-pending' ?>">
                                            <?= ucfirst($booking['payment_status']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label">Transaction ID:</div>
                                    <div class="detail-value">
                                        <?= $booking['payment_transaction'] ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label">Payment Method:</div>
                                    <div class="detail-value">
                                        <?= ucfirst(str_replace('_', ' ', $booking['payment_method'])) ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label">Payment Date:</div>
                                    <div class="detail-value">
                                        <?= date('M j, Y H:i', strtotime($booking['payment_date'])) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="detail-row">
                                    <div class="detail-value text-center">
                                        <i class="fas fa-exclamation-circle"></i> No payment recorded for this booking
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Booking Timeline -->
                        <div class="detail-section">
                            <h3><i class="fas fa-history"></i> Booking Timeline</h3>
                            <div class="timeline">
                                <div class="timeline-item">
                                    <div class="timeline-date"><?= date('M j, Y H:i', strtotime($booking['booking_date'])) ?></div>
                                    <div>Booking created</div>
                                </div>
                                
                                <?php if ($booking['updated_at'] && $booking['updated_at'] !== $booking['booking_date']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date"><?= date('M j, Y H:i', strtotime($booking['updated_at'])) ?></div>
                                    <div>Booking updated to <?= ucfirst($booking['status']) ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($booking['payment_date']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date"><?= date('M j, Y H:i', strtotime($booking['payment_date'])) ?></div>
                                    <div>Payment <?= $booking['payment_status'] === 'completed' ? 'completed' : 'attempted' ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status Update Form -->
                    <div class="detail-section status-form">
                        <h3><i class="fas fa-sync-alt"></i> Update Booking Status</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label for="status">New Status</label>
                                <select id="status" name="status" required>
                                    <option value="pending" <?= $booking['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="confirmed" <?= $booking['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                    <option value="paid" <?= $booking['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="cancelled" <?= $booking['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="admin_notes">Admin Notes</label>
                                <textarea id="admin_notes" name="admin_notes" placeholder="Add any notes about this status change"><?= htmlspecialchars($booking['admin_notes'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" name="change_status" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                                <a href="index.php" class="btn btn-outline">
                                    <i class="fas fa-arrow-left"></i> Back to Bookings
                                </a>
                            </div>
                        </form>
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
    </script>
</body>
</html>