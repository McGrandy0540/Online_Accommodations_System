<?php
// owner/property_dashboard.php - Property Owner Dashboard
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

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

if (!$owner) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get admin phone number for Paystack
$admin_stmt = $pdo->prepare("SELECT phone_number FROM users WHERE status = 'admin' LIMIT 1");
$admin_stmt->execute();
$admin = $admin_stmt->fetch();
$admin_phone = $admin['phone_number'] ?? '';

// Calculate pending and approved rooms with payment status
$rooms_stmt = $pdo->prepare("
    SELECT 
        p.id as property_id,
        p.property_name,
        COUNT(pr.id) as total_rooms,
        SUM(CASE WHEN pr.levy_payment_status = 'pending' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date < CURDATE()) THEN 1 ELSE 0 END) as pending_payment_rooms,
        SUM(CASE WHEN pr.levy_payment_status = 'paid' THEN 1 ELSE 0 END) as paid_rooms,
        SUM(CASE WHEN pr.levy_payment_status = 'approved' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE()) THEN 1 ELSE 0 END) as approved_rooms,
        SUM(CASE WHEN pr.levy_expiry_date IS NOT NULL AND pr.levy_expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_rooms
    FROM property p
    LEFT JOIN property_rooms pr ON p.id = pr.property_id
    WHERE p.owner_id = ? AND p.deleted = 0
    GROUP BY p.id
");
$rooms_stmt->execute([$owner_id]);
$properties_with_rooms = $rooms_stmt->fetchAll();

// Calculate total pending payment rooms and amount due
$total_pending_payment_rooms = 0;
$total_paid_rooms = 0;
$total_approved_rooms = 0;
$total_expired_rooms = 0;
$total_amount_due = 0;

foreach ($properties_with_rooms as $property) {
    $total_pending_payment_rooms += $property['pending_payment_rooms'];
    $total_paid_rooms += $property['paid_rooms'];
    $total_approved_rooms += $property['approved_rooms'];
    $total_expired_rooms += $property['expired_rooms'];
}

// Calculate amount due with possible discount
$room_fee = 50; // GHS 50 per room
$total_amount_due = ($total_pending_payment_rooms + $total_expired_rooms) * $room_fee;

// Apply 10% discount if more than 10 pending/expired rooms
$discount = 0;
if (($total_pending_payment_rooms + $total_expired_rooms) > 10) {
    $discount = $total_amount_due * 0.1;
    $total_amount_due -= $discount;
}



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

$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');

// Get properties owned by this user
$properties = [];
$properties_stmt = $pdo->prepare("
    SELECT 
        p.*, 
        COUNT(DISTINCT b.id) as booking_count,
        (SELECT COUNT(*) FROM property_rooms WHERE property_id = p.id AND levy_payment_status = 'approved' AND (levy_expiry_date IS NULL OR levy_expiry_date >= CURDATE())) as total_rooms,
        (SELECT SUM(capacity) FROM property_rooms WHERE property_id = p.id AND levy_payment_status = 'approved' AND (levy_expiry_date IS NULL OR levy_expiry_date >= CURDATE())) as total_capacity
    FROM property p
    LEFT JOIN bookings b ON p.id = b.property_id
    WHERE p.owner_id = ? AND p.deleted = 0
    GROUP BY p.id
    ORDER BY p.created_at DESC
");

try {
    $properties_stmt->execute([$owner_id]);
    $properties = $properties_stmt->fetchAll() ?: [];
    
    foreach ($properties as &$property) {
        $available_stmt = $pdo->prepare("
            SELECT SUM(pr.capacity) - 
            (SELECT COUNT(b.id) FROM bookings b 
             JOIN property_rooms pr ON b.room_id = pr.id 
             WHERE pr.property_id = ? AND b.status IN ('confirmed', 'paid')) 
            AS available_spots
            FROM property_rooms pr
            WHERE pr.property_id = ? AND pr.levy_payment_status = 'approved' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
        ");
        $available_stmt->execute([$property['id'], $property['id']]);
        $available = $available_stmt->fetch();
        $property['available_spots'] = $available['available_spots'] ?? 0;
        
        $rooms_stmt = $pdo->prepare("
            SELECT 
                pr.*, 
                (SELECT COUNT(b.id) FROM bookings b 
                 WHERE b.room_id = pr.id AND b.status IN ('confirmed', 'paid')) as current_occupants
            FROM property_rooms pr
            WHERE pr.property_id = ? AND pr.levy_payment_status = 'approved' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
            ORDER BY pr.room_number ASC
        ");
        $rooms_stmt->execute([$property['id']]);
        $property['rooms'] = $rooms_stmt->fetchAll();
        
        // Get pending and expired rooms for this property
        $pending_rooms_stmt = $pdo->prepare("
            SELECT * FROM property_rooms 
            WHERE property_id = ? AND (
                (levy_payment_status = 'pending' AND (levy_expiry_date IS NULL OR levy_expiry_date < CURDATE())) OR 
                (levy_expiry_date IS NOT NULL AND levy_expiry_date < CURDATE())
            )
            ORDER BY room_number ASC
        ");
        $pending_rooms_stmt->execute([$property['id']]);
        $property['pending_rooms'] = $pending_rooms_stmt->fetchAll();
    }
    unset($property);
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $properties = [];
}

// Get recent bookings for owner's properties
$bookings_stmt = $pdo->prepare("
    SELECT b.*, p.property_name, u.username as student_name, pr.room_number, pr.capacity
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY b.booking_date DESC LIMIT 5
");
$bookings_stmt->execute([$owner_id]);
$recent_bookings = $bookings_stmt->fetchAll();

// Get recent payments for owner's properties
$payments_stmt = $pdo->prepare("
    SELECT py.*, p.property_name, u.username as student_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY py.created_at DESC LIMIT 3
");
$payments_stmt->execute([$owner_id]);
$recent_payments = $payments_stmt->fetchAll();

// Get unread notifications
$notifications_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$notifications_stmt->execute([$owner_id]);
$unread_notifications = $notifications_stmt->fetchAll();

// Calculate stats based on room availability
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT p.id) as total_properties,
        SUM(pr.capacity) - 
        (SELECT COUNT(b.id) FROM bookings b 
         JOIN property_rooms pr ON b.room_id = pr.id 
         JOIN property p ON pr.property_id = p.id
         WHERE p.owner_id = ? AND p.deleted = 0 AND b.status IN ('confirmed', 'paid')) as available_spots,
        (SELECT COUNT(b.id) FROM bookings b 
         JOIN property_rooms pr ON b.room_id = pr.id 
         JOIN property p ON pr.property_id = p.id
         WHERE p.owner_id = ? AND p.deleted = 0 AND b.status IN ('confirmed', 'paid')) as booked_spots,
        SUM(CASE WHEN pr.status = 'maintenance' THEN pr.capacity ELSE 0 END) as maintenance_spots
    FROM property p
    JOIN property_rooms pr ON p.id = pr.property_id
    WHERE p.owner_id = ? AND p.deleted = 0 AND pr.levy_payment_status = 'approved' 
    AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
");
$stats_stmt->execute([$owner_id, $owner_id, $owner_id]);
$stats = $stats_stmt->fetch();

// Calculate total earnings
$earnings_stmt = $pdo->prepare("
    SELECT SUM(py.amount) as total_earnings
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    WHERE p.owner_id = ? AND py.status = 'completed' AND p.deleted = 0
");
$earnings_stmt->execute([$owner_id]);
$earnings = $earnings_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Dashboard | Landlords&Tenant</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Paystack inline script -->
    <script src="https://js.paystack.co/v1/inline.js"></script>

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
            --danger-color: #dc3545;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            padding-top: var(--header-height);
        }
        
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background-color: white;
            box-shadow: var(--box-shadow);
            z-index: 1000;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
            padding: 0 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .logo img {
            height: 40px;
            margin-right: 10px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--secondary-color);
            cursor: pointer;
            display: none;
        }
        
        .user-controls {
            display: flex;
            align-items: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            transition: all var(--transition-speed) ease;
            position: fixed;
            top: var(--header-height);
            bottom: 0;
            left: 0;
            overflow-y: auto;
        }
        
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu li a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-menu li a.active {
            background-color: var(--primary-color);
        }
        
        .sidebar-menu li a i {
            width: 24px;
            margin-right: 10px;
            text-align: center;
        }
        
        .sidebar-menu li a span {
            transition: opacity var(--transition-speed) ease;
        }
        
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left var(--transition-speed) ease;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .card-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .bg-available {
            background-color: #d4edda;
            color: #155724;
        }
        
        .bg-partial {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .bg-booked {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .bg-maintenance {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .bg-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .bg-paid {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .bg-expired {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: var(--box-shadow);
        }
        
        .profile-avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            border: 3px solid white;
            box-shadow: var(--box-shadow);
        }
        
        .quick-link {
            display: block;
            padding: 15px;
            border-radius: var(--border-radius);
            background: white;
            color: var(--dark-color);
            text-decoration: none;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            border-left: 3px solid var(--primary-color);
        }
        
        .quick-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateX(5px);
        }
        
        .quick-link i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .notification-item {
            border-left: 3px solid var(--primary-color);
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            background-color: var(--light-color);
        }
        
        .notification-unread {
            background-color: #f0f8ff;
            font-weight: 500;
        }
        
        .property-img {
            height: 180px;
            object-fit: cover;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        .carousel-item img {
            height: 180px;
            object-fit: cover;
            width: 100%;
        }
        
        .carousel-indicators button {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin: 0 5px;
        }
        
        .room-status {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Pending Rooms Section */
        .pending-rooms-card {
            border-left: 4px solid var(--warning-color);
        }
        
        .pending-rooms-header {
            background-color: var(--warning-color);
            color: white;
        }
        
        .pending-room-item {
            border-left: 3px solid var(--warning-color);
            margin-bottom: 10px;
            padding: 10px;
            background-color: rgba(255, 193, 7, 0.1);
        }
        
        .expired-room-item {
            border-left: 3px solid var(--danger-color);
            margin-bottom: 10px;
            padding: 10px;
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        /* Mobile Styles */
        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .sidebar-menu li a span {
                opacity: 1;
            }
            
            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar.collapsed .sidebar-menu li a span {
                opacity: 0;
                width: 0;
                display: none;
            }
            
            .sidebar.collapsed .sidebar-menu li a i {
                margin-right: 0;
                font-size: 1.2rem;
            }
            
            .sidebar.collapsed .sidebar-menu li a {
                justify-content: center;
                padding: 15px 10px;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1.5rem;
            }
            
            .property-img {
                height: 150px;
            }
            
            .profile-avatar, .profile-avatar-placeholder {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1rem;
            }
            
            .property-img {
                height: 120px;
            }
            
            .quick-link {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
        
        /* Payment processing spinner */
        .payment-processing {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .payment-spinner {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
        }
        
        .payment-spinner i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Renewal badge */
        .renewal-badge {
            font-size: 0.7rem;
            padding: 3px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../assets/images/logo-removebg-preview.png" alt="UniHomes Logo">
                <span>Landlords&Tenant</span>
            </a>
            
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="profile-avatar-placeholder">
                                <?= substr($owner['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($owner['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
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
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                    <li><a href="property_dashboard.php" class="active"><i class="fas fa-home"></i> <span>My Properties</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span>Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span>Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span>Reviews</span></a></li>
                    <li><a href="chat/"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                    <li><a href="virtual-tours/"><i class="fas fa-video"></i> <span>Virtual Tours</span></a></li>
                    <li><a href="announcement.php"><i class="fas fa-bullhorn"></i> <span>Announcements</span></a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="container">
                <!-- Welcome Header -->
                <div class="dashboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8 d-flex align-items-center">
                            <?php if (!empty($profile_pic_path)): ?>
                                <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar me-4" alt="Profile Picture">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder me-4">
                                    <?= substr($owner['username'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h2>My Properties, <?= htmlspecialchars($owner['username']) ?></h2>
                                <p class="mb-0">Manage your properties and view room status</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-flex align-items-center justify-content-end">
                                <div class="me-3 position-relative">
                                    <a href="notification/index.php" class="text-white position-relative">
                                        <i class="fas fa-bell fa-lg"></i>
                                        <?php if(count($unread_notifications) > 0): ?>
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                            <?= count($unread_notifications) ?>
                                        </span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-user-tie me-1"></i> Property Owner
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Rooms Payment Section -->
                <?php if ($total_pending_payment_rooms > 0 || $total_expired_rooms > 0): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card pending-rooms-card">
                            <div class="card-header bg-warning text-white">
                                <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>Pending Room Approvals & Renewals</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5>Payment Required for Room Approvals/Renewals</h5>
                                        <p>
                                            <?php if ($total_pending_payment_rooms > 0): ?>
                                                You have <strong><?= $total_pending_payment_rooms ?> new rooms</strong> pending approval.
                                            <?php endif; ?>
                                            <?php if ($total_expired_rooms > 0): ?>
                                                <?php if ($total_pending_payment_rooms > 0) echo 'and'; ?>
                                                <strong><?= $total_expired_rooms ?> rooms</strong> that need renewal.
                                            <?php endif; ?>
                                            Each room requires a payment of GHS 50 to be listed to students for 1 year.
                                        </p>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-info-circle me-2"></i>
                                            These rooms will not be visible to students until payment is completed and approved by admin.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="bg-light p-3 rounded">
                                            <h5>Payment Summary</h5>
                                            <table class="table table-sm">
                                                <?php if ($total_pending_payment_rooms > 0): ?>
                                                <tr>
                                                    <td>New Pending Rooms:</td>
                                                    <td class="text-end"><?= $total_pending_payment_rooms ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($total_expired_rooms > 0): ?>
                                                <tr>
                                                    <td>Expired Rooms (Renewal):</td>
                                                    <td class="text-end"><?= $total_expired_rooms ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td>Fee per Room:</td>
                                                    <td class="text-end">GHS 50.00</td>
                                                </tr>
                                                <?php if ($discount > 0): ?>
                                                <tr class="text-success">
                                                    <td>Discount (10% for 10+ rooms):</td>
                                                    <td class="text-end">-GHS <?= number_format($discount, 2) ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr class="fw-bold">
                                                    <td>Total Amount Due:</td>
                                                    <td class="text-end">GHS <?= number_format($total_amount_due, 2) ?></td>
                                                </tr>
                                            </table>
                                            
                                            <form method="POST" id="paymentForm">
                                                <input type="hidden" name="pay_now" value="1">
                                                <button type="submit" class="btn btn-success w-100" id="payButton">
                                                    <i class="fas fa-credit-card me-2"></i> Pay Now via Paystack
                                                </button>
                                                <p class="text-muted small mt-2">
                                                    <i class="fas fa-lock me-1"></i> Secure payment processed by Paystack
                                                </p>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- List of pending and expired rooms by property -->
                                <div class="mt-4">
                                    <h6><i class="fas fa-list me-2"></i>Pending & Expired Rooms Details</h6>
                                    <div class="row">
                                        <?php foreach ($properties as $property): ?>
                                            <?php if (count($property['pending_rooms']) > 0): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><?= htmlspecialchars($property['property_name']) ?></h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php foreach ($property['pending_rooms'] as $room): ?>
                                                            <?php 
                                                            $isExpired = $room['levy_expiry_date'] && strtotime($room['levy_expiry_date']) < time();
                                                            ?>
                                                            <div class="<?= $isExpired ? 'expired-room-item' : 'pending-room-item' ?>">
                                                                <div class="d-flex justify-content-between">
                                                                    <span>
                                                                        <i class="fas fa-door-open me-2"></i>
                                                                        Room <?= htmlspecialchars($room['room_number']) ?>
                                                                    </span>
                                                                    <span class="badge bg-<?= 
                                                                        $isExpired ? 'danger' : (
                                                                        $room['levy_payment_status'] === 'paid' ? 'primary' : 
                                                                        ($room['levy_payment_status'] === 'approved' ? 'success' : 'warning')
                                                                    ) ?>">
                                                                        <?= $isExpired ? 'Expired' : ucfirst($room['levy_payment_status']) ?>
                                                                    </span>
                                                                </div>
                                                                <div class="small text-muted mt-1">
                                                                    Capacity: <?= $room['capacity'] ?> students
                                                                    <?php if ($isExpired): ?>
                                                                        <br>
                                                                        <i class="fas fa-calendar-times text-danger me-1"></i> 
                                                                        Expired on <?= date('M j, Y', strtotime($room['levy_expiry_date'])) ?>
                                                                    <?php elseif ($room['levy_payment_status'] === 'paid'): ?>
                                                                        <br><i class="fas fa-check-circle text-primary me-1"></i> Paid, awaiting admin approval
                                                                    <?php elseif ($room['levy_payment_status'] === 'pending'): ?>
                                                                        <br><i class="fas fa-exclamation-circle text-warning me-1"></i> Payment required
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-4">
                        <!-- Quick Links -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <a href="add.php" class="quick-link">
                                    <i class="fas fa-plus"></i> Add New Property
                                </a>
                                <a href="add_room.php" class="quick-link">
                                    <i class="fas fa-door-open"></i> Add New Room
                                </a>
                                <a href="bookings/" class="quick-link">
                                    <i class="fas fa-calendar-check"></i> View Bookings
                                </a>
                                <a href="payments/" class="quick-link">
                                    <i class="fas fa-money-bill-wave"></i> View Payments
                                </a>
                                <a href="reports.php" class="quick-link"> 
                                    <i class="fas fa-file-pdf"></i> Generate Report
                                </a>
                            </div>
                        </div>
                        
                        <!-- Stats Summary -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Property Stats</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Total Properties</h6>
                                    <h3 class="mb-0"><?= $stats['total_properties'] ?></h3>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <h6 class="text-muted mb-1">Available Spots</h6>
                                        <h4 class="mb-0"><?= $stats['available_spots'] ?></h4>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <h6 class="text-muted mb-1">Booked Spots</h6>
                                        <h4 class="mb-0"><?= $stats['booked_spots'] ?></h4>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-muted mb-1">Maintenance</h6>
                                        <h4 class="mb-0"><?= $stats['maintenance_spots'] ?></h4>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-muted mb-1">Total Earnings</h6>
                                        <h4 class="mb-0">GHS <?= number_format($earnings['total_earnings'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Room Status Summary -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-door-open me-2"></i>Room Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Active Rooms</h6>
                                    <h3 class="mb-0"><?= $total_approved_rooms ?></h3>
                                </div>
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Paid Rooms (Pending Approval)</h6>
                                    <h3 class="mb-0"><?= $total_paid_rooms ?></h3>
                                </div>
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Pending Payment Rooms</h6>
                                    <h3 class="mb-0"><?= $total_pending_payment_rooms ?></h3>
                                </div>
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Expired Rooms (Need Renewal)</h6>
                                    <h3 class="mb-0"><?= $total_expired_rooms ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="col-lg-8">
                        <!-- My Properties -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-home me-2"></i>My Properties</h5>
                                    <a href="add.php" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-plus me-1"></i> Add Property
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(count($properties) > 0): ?>
                                    <div class="row">
                                        <?php foreach($properties as $property): ?>
                                            <div class="col-md-6 mb-4">
                                                <div class="card h-100">
                                                    <!-- Property Images Carousel -->
                                                    <?php
                                                    $image_query = "SELECT image_url FROM property_images WHERE property_id = ?";
                                                    $image_stmt = $pdo->prepare($image_query);
                                                    $image_stmt->execute([$property['id']]);
                                                    $images = $image_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    ?>
                                                    
                                                    <div id="carousel-<?= $property['id'] ?>" class="carousel slide">
                                                        <div class="carousel-inner">
                                                            <?php if (!empty($images)): ?>
                                                                <?php foreach ($images as $index => $image): ?>
                                                                    <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                                                        <img src="../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                                                             class="d-block w-100 property-img" 
                                                                             alt="Property image">
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <div class="carousel-item active">
                                                                    <img src="../../assets/images/default-property.jpg" 
                                                                         class="d-block w-100 property-img" 
                                                                         alt="Default property image">
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (count($images) > 1): ?>
                                                            <button class="carousel-control-prev" type="button" 
                                                                    data-bs-target="#carousel-<?= $property['id'] ?>" 
                                                                    data-bs-slide="prev">
                                                                <span class="carousel-control-prev-icon"></span>
                                                            </button>
                                                            <button class="carousel-control-next" type="button" 
                                                                    data-bs-target="#carousel-<?= $property['id'] ?>" 
                                                                    data-bs-slide="next">
                                                                <span class="carousel-control-next-icon"></span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div class="card-body">
                                                        <h5><?= htmlspecialchars($property['property_name']) ?></h5>
                                                        <p class="text-muted">
                                                            <i class="fas fa-map-marker-alt me-1"></i>
                                                            <?= htmlspecialchars($property['location']) ?>
                                                        </p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-<?= 
                                                                ($property['available_spots'] == $property['total_capacity']) ? 'available' : 
                                                                (($property['available_spots'] > 0) ? 'partial' : 'booked')
                                                            ?>">
                                                                <?= 
                                                                    ($property['available_spots'] == $property['total_capacity']) ? 'Available' : 
                                                                    (($property['available_spots'] > 0) ? 'Partially Booked' : 'Fully Booked')
                                                                ?>
                                                            </span>
                                                            <span class="text-primary fw-bold">
                                                                GHS <?= number_format($property['price'], 2) ?> per head
                                                            </span>
                                                        </div>
                                                        <div class="mt-2 d-flex justify-content-between">
                                                            <span class="badge bg-light text-dark">
                                                                <i class="fas fa-calendar-check me-1"></i>
                                                                <?= $property['booking_count'] ?> bookings
                                                            </span>
                                                            <span class="room-status">
                                                                <?= $property['available_spots'] ?> of <?= $property['total_capacity'] ?> spots available
                                                            </span>
                                                        </div>
                                                        
                                                        <!-- Room Details -->
                                                        <div class="mt-3">
                                                            <h6>Room Details:</h6>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Room #</th>
                                                                            <th>Capacity</th>
                                                                            <th>Gender</th>
                                                                            <th>Available</th>
                                                                            <th>Occupied</th>
                                                                            <th>Levy Status</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php 
                                                                        // Get all rooms including their levy expiry status
                                                                        $all_rooms_stmt = $pdo->prepare("
                                                                            SELECT 
                                                                                pr.*, 
                                                                                (SELECT COUNT(b.id) FROM bookings b 
                                                                                 WHERE b.room_id = pr.id AND b.status IN ('confirmed', 'paid')) as current_occupants
                                                                            FROM property_rooms pr
                                                                            WHERE pr.property_id = ?
                                                                            ORDER BY pr.room_number ASC
                                                                        ");
                                                                        $all_rooms_stmt->execute([$property['id']]);
                                                                        $all_rooms = $all_rooms_stmt->fetchAll();
                                                                        
                                                                        foreach($all_rooms as $room): 
                                                                            $isExpired = $room['levy_expiry_date'] && strtotime($room['levy_expiry_date']) < time();
                                                                            $isActive = $room['levy_payment_status'] === 'approved' && !$isExpired;
                                                                        ?>
                                                                        <tr>
                                                                            <td><?= $room['room_number'] ?></td>
                                                                            <td><?= $room['capacity'] ?></td>
                                                                            <td><?= ucfirst($room['gender']) ?></td>
                                                                            <td><?= $room['capacity'] - $room['current_occupants'] ?></td>
                                                                            <td><?= $room['current_occupants'] ?></td>
                                                                            <td>
                                                                                <?php if ($isActive): ?>
                                                                                    <span class="badge bg-success renewal-badge">
                                                                                        <i class="fas fa-check-circle me-1"></i>
                                                                                        Active until <?= date('M Y', strtotime($room['levy_expiry_date'])) ?>
                                                                                    </span>
                                                                                <?php elseif ($isExpired): ?>
                                                                                    <span class="badge bg-danger renewal-badge">
                                                                                        <i class="fas fa-exclamation-circle me-1"></i>
                                                                                        Expired
                                                                                    </span>
                                                                                <?php elseif ($room['levy_payment_status'] === 'paid'): ?>
                                                                                    <span class="badge bg-primary renewal-badge">
                                                                                        <i class="fas fa-clock me-1"></i>
                                                                                        Pending approval
                                                                                    </span>
                                                                                <?php else: ?>
                                                                                    <span class="badge bg-warning renewal-badge">
                                                                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                                                                        Payment needed
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Pending/Expired Rooms Badge -->
                                                        <?php 
                                                        $pending_count = 0;
                                                        $paid_count = 0;
                                                        $expired_count = 0;
                                                        
                                                        foreach ($property['pending_rooms'] as $room) {
                                                            if ($room['levy_expiry_date'] && strtotime($room['levy_expiry_date']) < time()) {
                                                                $expired_count++;
                                                            } elseif ($room['levy_payment_status'] === 'pending') {
                                                                $pending_count++;
                                                            } elseif ($room['levy_payment_status'] === 'paid') {
                                                                $paid_count++;
                                                            }
                                                        }
                                                        ?>
                                                        <?php if ($pending_count > 0 || $paid_count > 0 || $expired_count > 0): ?>
                                                        <div class="mt-2">
                                                            <?php if ($pending_count > 0): ?>
                                                            <span class="badge bg-warning me-1">
                                                                <i class="fas fa-exclamation-circle me-1"></i>
                                                                <?= $pending_count ?> rooms need payment
                                                            </span>
                                                            <?php endif; ?>
                                                            <?php if ($paid_count > 0): ?>
                                                            <span class="badge bg-primary me-1">
                                                                <i class="fas fa-clock me-1"></i>
                                                                <?= $paid_count ?> rooms awaiting approval
                                                            </span>
                                                            <?php endif; ?>
                                                            <?php if ($expired_count > 0): ?>
                                                            <span class="badge bg-danger">
                                                                <i class="fas fa-calendar-times me-1"></i>
                                                                <?= $expired_count ?> rooms expired
                                                            </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="card-footer bg-white">
                                                        <div class="d-flex justify-content-between">
                                                            <a href="view.php?id=<?= $property['id'] ?>" class="btn btn-outline-primary">
                                                                <i class="fas fa-eye me-1"></i> View
                                                            </a>
                                                            <a href="add_room.php?property_id=<?= $property['id'] ?>" class="btn btn-outline-success">
                                                                <i class="fas fa-door-open me-1"></i> Add Room
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-home fa-3x text-muted mb-3"></i>
                                        <h5>No Properties Listed</h5>
                                        <p class="text-muted">You haven't listed any properties yet</p>
                                        <a href="add.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-1"></i> Add Property
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Recent Bookings -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Recent Bookings</h5>
                                    <a href="../bookings/" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(count($recent_bookings) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                                <tr>
                                                    <th>Property</th>
                                                    <th>Room</th>
                                                    <th>Student</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($recent_bookings as $booking): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($booking['property_name']) ?></td>
                                                    <td>
                                                        <?php if (!empty($booking['room_number'])): ?>
                                                            <?= htmlspecialchars($booking['room_number']) ?> (Capacity: <?= $booking['capacity'] ?>)
                                                        <?php elseif (!empty($booking['room_id'])): ?>
                                                            Room #<?= htmlspecialchars($booking['room_id']) ?>
                                                        <?php else: ?>
                                                            N/A
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($booking['student_name']) ?></td>
                                                    <td><?= date('M j, Y', strtotime($booking['booking_date'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= 
                                                            $booking['status'] === 'confirmed' ? 'success' : 
                                                            ($booking['status'] === 'pending' ? 'warning' : 
                                                            ($booking['status'] === 'paid' ? 'primary' : 'danger'))
                                                        ?>">
                                                            <?= ucfirst($booking['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="bookings/view.php?id=<?= $booking['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar fa-3x text-muted mb-3"></i>
                                        <h5>No Recent Bookings</h5>
                                        <p class="text-muted">You don't have any booking requests yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Processing Modal -->
    <div class="payment-processing" id="paymentProcessing">
        <div class="payment-spinner">
            <i class="fas fa-spinner"></i>
            <h4>Processing Payment...</h4>
            <p>Please wait while we connect to Paystack</p>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Load scripts in correct order -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
    
    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Initialize carousels
        document.addEventListener('DOMContentLoaded', function() {
            var carousels = document.querySelectorAll('.carousel');
            carousels.forEach(function(carousel) {
                new bootstrap.Carousel(carousel, {
                    interval: 5000,
                    ride: 'carousel'
                });
            });
        });

  const paymentForm = document.getElementById('paymentForm');
    paymentForm.addEventListener("submit", function(e) {
        e.preventDefault();
        payWithPaystack();
    });

    function payWithPaystack() {
        console.log('Paystack payment initiated'); // Debugging
        
        // Show loading spinner
        const processingModal = document.getElementById('paymentProcessing');
        processingModal.style.display = 'flex';
        
        // Disable pay button
        const payButton = document.getElementById('payButton');
        payButton.disabled = true;
        payButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
        
        // Create form data
        const formData = {
            email: '<?= htmlspecialchars($owner['email']) ?>',
            amount: <?= $total_amount_due * 100 ?>, // Paystack uses amount in kobo
            reference: 'ROOM_<?= $owner_id ?>_' + Date.now(),
            pending_rooms: <?= $total_pending_payment_rooms ?>,
            expired_rooms: <?= $total_expired_rooms ?>,
            discount: <?= $discount ?>,
            admin_phone: '<?= htmlspecialchars($admin_phone) ?>'
        };

        console.log('Form data:', formData); // Debugging

        // Initialize Paystack payment
        const handler = PaystackPop.setup({
            key: '<?= PAYSTACK_PUBLIC_KEY ?>',
            email: formData.email,
            amount: formData.amount,
            currency: 'GHS',
            ref: formData.reference,
            metadata: {
                custom_fields: [
                    {
                        display_name: "Admin Phone",
                        variable_name: "admin_phone",
                        value: formData.admin_phone
                    },
                    {
                        display_name: "Pending Rooms",
                        variable_name: "pending_rooms",
                        value: formData.pending_rooms
                    },
                    {
                        display_name: "Expired Rooms",
                        variable_name: "expired_rooms",
                        value: formData.expired_rooms
                    },
                    {
                        display_name: "Discount Applied",
                        variable_name: "discount",
                        value: formData.discount
                    }
                ]
            },
            callback: function(response) {
                // Payment was successful
                console.log('Paystack response:', response);
                processPayment(response, formData);
            },
            onClose: function() {
                // User closed payment window
                processingModal.style.display = 'none';
                payButton.disabled = false;
                payButton.innerHTML = '<i class="fas fa-credit-card me-2"></i> Pay Now via Paystack';
                
                // Show alert
                showAlert('Payment window closed - please try again', 'orange');
            }
        });
        
        handler.openIframe();
    }

// Payment processing function
function processPayment( response, formData) {
    const processingModal = document.getElementById('paymentProcessing');
    const payButton = document.getElementById('payButton');
    
    // Update modal message
    const spinnerText = processingModal.querySelector('p');
    spinnerText.textContent = 'Verifying payment with Paystack...';
    
    // Prepare data to send to server
    const postData = {
        reference: response.reference,
        amount: formData.amount / 100, // Convert back to GHS
        pending_rooms: formData.pending_rooms,
        expired_rooms: formData.expired_rooms,
        discount: formData.discount
    };

    console.log('Payment data to send:', postData);

    // Send verification request to server
    fetch("verify_room_payment.php", {
        method: "POST",
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(postData),
        credentials: 'same-origin'
    })
    .then(response => {
        console.log('Payment verification response 1:', response);
        if (!response.ok) {
            return response.json().then(err => { throw err; });
        }
        return response.json();
        console.log('Payment verification response 2:', response);
    })
    .then(data => {
        if (data.success) {
            // Update modal with success message
            processingModal.querySelector('i').className = 'fas fa-check-circle text-success';
            processingModal.querySelector('h4').textContent = 'Payment Successful!';
            spinnerText.textContent = 'Redirecting to payment confirmation...';
            
            // Redirect to success page
            setTimeout(() => {
                window.location.href = `payment_success.php?reference=${data.reference}`;
            }, 5000);
        } else {
            throw new Error(data.message || 'Payment verification failed');
        }
    })
    .catch(error => {
        console.error('Payment Error:', error);
        
        // Update modal with error message
        processingModal.querySelector('i').className = 'fas fa-times-circle text-danger';
        processingModal.querySelector('h4').textContent = 'Payment Failed';
        spinnerText.textContent = error.message || 'Payment processing failed';
        
        
        
        // Hide modal after delay
        setTimeout(() => {
            processingModal.style.display = 'none';
        }, 3000);
        
        // Special handling for session expiration
        if (error.message.includes('session') || error.message.includes('expired')) {
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
        }
    });
}

        // Helper function to show alerts
        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} fixed-top mx-auto mt-3`;
            alertDiv.style.maxWidth = '500px';
            alertDiv.style.zIndex = '2000';
            alertDiv.textContent = message;
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
    </script>
</body>
</html>