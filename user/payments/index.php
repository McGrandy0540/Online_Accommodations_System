<?php
// student/payment.php - Student Payment Page
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);
require_once __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../config/constants.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    header('HTTP/1.0 403 Forbidden');
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: /auth/login.php');
    exit();
}

// Get active bookings that require payment
$bookings_stmt = $pdo->prepare("
    SELECT 
        b.id as booking_id,
        b.status,
        b.start_date,
        b.end_date,
        b.duration_months,
        b.payment_required,
        p.id as property_id,
        p.property_name,
        p.price,
        pr.id as room_id,
        pr.room_number,
        pr.capacity,
        u.id as owner_id,
        u.username as owner_name,
        u.phone_number as owner_phone,
        u.email as owner_email
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON p.owner_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.user_id = ? 
    AND b.status IN ('pending_payment', 'pending')
    AND b.payment_required = 1
    ORDER BY b.booking_date DESC
");
$bookings_stmt->execute([$student_id]);
$pending_bookings = $bookings_stmt->fetchAll();

// Calculate total amount due and group by owner
$total_amount_due = 0;
$booking_fees = [];
$owners = [];

foreach ($pending_bookings as $booking) {
    $booking_fee = ($booking['price'] / 12) * $booking['duration_months'];
    $booking_fees[$booking['booking_id']] = $booking_fee;
    $total_amount_due += $booking_fee;
    
    // Group by owner
    if (!isset($owners[$booking['owner_id']])) {
        $owners[$booking['owner_id']] = [
            'id' => $booking['owner_id'],
            'name' => $booking['owner_name'],
            'phone' => $booking['owner_phone'],
            'email' => $booking['owner_email'],
            'amount' => 0,
            'bookings' => []
        ];
    }
    
    $owners[$booking['owner_id']]['amount'] += $booking_fee;
    $owners[$booking['owner_id']]['bookings'][] = $booking['booking_id'];
}

// Process Paystack payment if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    $reference = 'BOOKING_' . time() . '_' . bin2hex(random_bytes(4));
    
    // Store payment data in session
    $_SESSION['booking_payment'] = [
        'student_id' => $student_id,
        'amount' => $total_amount_due,
        'reference' => $reference,
        'booking_ids' => array_column($pending_bookings, 'booking_id'),
        'booking_fees' => $booking_fees,
        'owners' => $owners
    ];
    
    // Return JSON response for AJAX handling
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'reference' => $reference,
        'amount' => $total_amount_due * 100 , // Paystack uses amount in kobo
        'email' => $student['email'],
        'metadata' => [
            'student_id' => $student_id,
            'owners' => array_values($owners)
        ]
    ]);
    exit();
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../../assets/images/ktu logo.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../uploads/profile_prictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($student['profile_picture'] ?? '');

// Get recent payments with owner info
$payments_stmt = $pdo->prepare("
    SELECT 
        py.*, 
        p.property_name,
        u.username as owner_name,
        py.amount,
        py.created_at as payment_date
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    JOIN users u ON p.owner_id = u.id
    WHERE b.user_id = ?
    ORDER BY py.created_at DESC LIMIT 5
");
$payments_stmt->execute([$student_id]);
$recent_payments = $payments_stmt->fetchAll();

// Get unread notifications
$notifications_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$notifications_stmt->execute([$student_id]);
$unread_notifications = $notifications_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment | Landlords&Tenants</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
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

        .owner-payment {
            border-left: 3px solid var(--primary-color);
            margin-bottom: 15px;
            padding: 15px;
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .owner-payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
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
            max-width: 400px;
            width: 90%;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../../assets/images/logo-removebg-preview.png" alt="Landlords&Tenants Logo">
                <span>Landlords&Tenants</span>
            </a>
            
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile" class="rounded-circle" width="40" height="40">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <?= strtoupper(substr($student['username'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline ms-2"><?= htmlspecialchars($student['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="logout.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
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
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span></a></li>
                    <li><a href="../search/index.php"><i class="fas fa-home me-2"></i> <span>Find Accommodation</span></a></li>
                    <li><a href="../bookings/index.php"><i class="fas fa-calendar-alt me-2"></i> <span>My Bookings</span></a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-wallet me-2"></i> <span>Payments</span></a></li>
                    <li><a href="../reviews/index.php"><i class="fas fa-star me-2"></i> <span>Reviews</span></a></li>
                    <li><a href="../maintenance/index.php"><i class="fas fa-tools me-2"></i> <span>Maintenance</span></a></li>
                    <li><a href="../announcements/index.php"><i class="fas fa-bullhorn me-2"></i> <span>Announcements</span></a></li>
                    <li><a href="../profile/index.php"><i class="fas fa-cog me-2"></i> <span>Settings</span></a></li>
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
                                    <?= strtoupper(substr($student['username'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h2>Payment Portal, <?= htmlspecialchars($student['username']) ?></h2>
                                <p class="mb-0">Manage your accommodation payments</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-flex align-items-center justify-content-end">
                                <div class="me-3 position-relative">
                                    <a href="notifications.php" class="text-white position-relative">
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
                                        <i class="fa-solid fa-people-roof me-1"></i> Tenant
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Bookings Payment Section -->
                <?php if (count($pending_bookings) > 0): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-white">
                                <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>Pending Payments</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5>Payment Required for Bookings</h5>
                                        <p class="lead">
                                            You have <strong><?= count($pending_bookings) ?> booking(s)</strong> pending payment.
                                            Please complete payment to confirm your accommodation.
                                        </p>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-info-circle me-2"></i>
                                            Your bookings will not be confirmed until payment is completed.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="bg-light p-3 rounded border">
                                            <h5>Payment Summary</h5>
                                            <table class="table table-sm">
                                                <?php foreach ($pending_bookings as $booking): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($booking['property_name']) ?> (<?= $booking['duration_months'] ?> months)</td>
                                                    <td class="text-end">GHS <?= number_format(($booking['price'] / 12) * $booking['duration_months'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <tr class="fw-bold table-active">
                                                    <td>Total Amount Due:</td>
                                                    <td class="text-end">GHS <?= number_format($total_amount_due, 2) ?></td>
                                                </tr>
                                            </table>
                                            
                                            <form method="POST" id="paymentForm">
                                                <input type="hidden" name="pay_now" value="1">
                                                <button type="submit" class="btn btn-success w-100 py-2" id="payButton">
                                                    <i class="fas fa-credit-card me-2"></i> Pay Now via Paystack
                                                </button>
                                                <p class="text-muted small mt-2 mb-0">
                                                    <i class="fas fa-lock me-1"></i> Secure payment processed by Paystack
                                                </p>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Distribution Section -->
                                <div class="mt-4">
                                    <h5><i class="fas fa-money-bill-wave me-2"></i>Payment Distribution</h5>
                                    <p>Your payment will be distributed to the following property owners:</p>
                                    
                                    <?php foreach ($owners as $owner): ?>
                                    <div class="owner-payment mb-3">
                                        <div class="owner-payment-header">
                                            <h6 class="mb-0"><?= htmlspecialchars($owner['name']) ?></h6>
                                            <span class="badge bg-primary">GHS <?= number_format($owner['amount'], 2) ?></span>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1"><i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($owner['email']) ?></p>
                                                <p class="mb-1"><i class="fas fa-phone me-2"></i> <?= htmlspecialchars($owner['phone']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Bookings:</strong> <?= count($owner['bookings']) ?></p>
                                                <p class="mb-0"><strong>Payment Method:</strong> Paystack Transfer</p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- List of pending bookings -->
                                <div class="mt-4">
                                    <h5><i class="fas fa-list me-2"></i>Pending Bookings Details</h5>
                                    <div class="row">
                                        <?php foreach ($pending_bookings as $booking): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0"><?= htmlspecialchars($booking['property_name']) ?></h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="pending-booking-item">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <span class="text-muted">
                                                                <i class="fas fa-calendar-alt me-2"></i>
                                                                Booking #<?= htmlspecialchars($booking['booking_id']) ?>
                                                            </span>
                                                            <span class="badge bg-warning">
                                                                <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                                                            </span>
                                                        </div>
                                                        <div class="small">
                                                            <div class="mb-1">
                                                                <i class="fas fa-door-open me-2"></i>
                                                                <?= !empty($booking['room_number']) ? 
                                                                    'Room ' . htmlspecialchars($booking['room_number']) : 
                                                                    'Room not yet assigned' ?>
                                                            </div>
                                                            <div class="mb-1">
                                                                <i class="fas fa-calendar-day me-2"></i>
                                                                <?= date('M j, Y', strtotime($booking['start_date'])) ?> to 
                                                                <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                                                            </div>
                                                            <div class="mb-1">
                                                                <i class="fas fa-clock me-2"></i>
                                                                <?= $booking['duration_months'] ?> month(s)
                                                            </div>
                                                            <div class="mt-2 fw-bold">
                                                                <i class="fas fa-money-bill-wave me-2"></i>
                                                                GHS <?= number_format(($booking['price'] / 12) * $booking['duration_months'], 2) ?>
                                                            </div>
                                                            <div class="mt-2">
                                                                <i class="fas fa-user-tie me-2"></i>
                                                                Owner: <?= htmlspecialchars($booking['owner_name']) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    You have no pending payments at this time.
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-4">
                        <!-- Quick Links -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body p-2">
                                <a href="../properties/index.php" class="quick-link">
                                    <i class="fas fa-search me-2"></i> Find Accommodation
                                </a>
                                <a href="../bookings/index.php" class="quick-link">
                                    <i class="fas fa-calendar-check me-2"></i> View Bookings
                                </a>
                                <a href="index.php" class="quick-link">
                                    <i class="fas fa-money-bill-wave me-2"></i> Make Payment
                                </a>
                                <a href="../reviews/index.php" class="quick-link">
                                    <i class="fas fa-star me-2"></i> Leave Reviews
                                </a>
                                <a href="../settings/index.php" class="quick-link">
                                    <i class="fas fa-cog me-2"></i> Account Settings
                                </a>
                            </div>
                        </div>
                        
                        <!-- Payment Methods -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Methods</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6 class="text-muted mb-1">Default Payment Method</h6>
                                    <p class="mb-0">
                                        <i class="fas fa-mobile-alt me-2"></i>
                                        <?= ucfirst(str_replace('_', ' ', $student['payment_method'] ?? 'mobile_money')) ?>
                                    </p>
                                </div>
                                <a href="payment_methods.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-plus me-1"></i> Add Payment Method
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="col-lg-8">
                        <!-- Recent Payments -->
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Payment History</h5>
                                    <a href="payment_history.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(count($recent_payments) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Property</th>
                                                    <th>Owner</th>
                                                    <th>Amount</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($recent_payments as $payment): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($payment['property_name']) ?></td>
                                                    <td><?= htmlspecialchars($payment['owner_name']) ?></td>
                                                    <td>GHS <?= number_format($payment['amount'], 2) ?></td>
                                                    <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-success">
                                                            Completed
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="receipt.php?id=<?= $payment['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-receipt"></i> Receipt
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                        <h5>No Payment History</h5>
                                        <p class="text-muted">You haven't made any payments yet</p>
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
            <i class="fas fa-spinner fa-spin fa-3x mb-3 text-primary"></i>
            <h4>Processing Payment...</h4>
            <p id="paymentStatusText">Please wait while we connect to Paystack</p>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Load scripts in correct order -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar on mobile
    document.getElementById('menuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('active');
    });

    // Payment form handler
    const paymentForm = document.getElementById('paymentForm');
    paymentForm.addEventListener('submit', payWithPaystack, false);
});

// Helper function to show alerts
function showAlert(message, type = 'success') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    const container = document.querySelector('.container');
    container.prepend(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

function payWithPaystack(e) {
    e.preventDefault();
    
    // UI Loading state
    const processingModal = document.getElementById('paymentProcessing');
    const payButton = document.getElementById('payButton');
    const statusText = document.getElementById('paymentStatusText');
    
    processingModal.style.display = 'flex';
    payButton.disabled = true;
    payButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
    
    // Get payment data via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'pay_now=1',
        credentials: 'include'
    })
    .then(async response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            throw new Error('Server returned invalid response: ' + text);
        }
        return response.json();
    })
    .then(data => {
        console.log('Payment data received:', data);
        if (!data.success) {
            throw new Error(data.error || 'Payment initialization failed');
        }
        // Initialize Paystack payment
        const handler = PaystackPop.setup({
            key: '<?= PAYSTACK_PUBLIC_KEY ?>',
            email: data.email,
            amount: data.amount,
            currency: 'GHS',
            ref: data.reference,
        
            metadata: {
                custom_fields: [
                    {
                        display_name: "Student ID",
                        variable_name: "student_id",
                        value: data.metadata.student_id
                    },
                    {
                        display_name: "Booking IDs",
                        variable_name: "booking_ids",
                        value: data.metadata.owners
                    }
                ]
            },

            callback: () => {
                statusText.textContent = 'Verifying payment...';
                processPayment(data);
            },
            onClose: function() {
                processingModal.style.display = 'none';
                payButton.disabled = false;
                payButton.innerHTML = '<i class="fas fa-credit-card me-2"></i> Pay Now via Paystack';
                showAlert('Payment window closed - please try again', 'warning');
            }
        });
        handler.openIframe();
        
    })
    .catch(error => {
        console.error('Error:', error);
        statusText.textContent = 'Error: ' + error.message;
        
        const spinnerIcon = processingModal.querySelector('.fa-spinner');
        if (spinnerIcon) {
            spinnerIcon.className = 'fas fa-times-circle fa-3x mb-3 text-danger';
        }
        
        processingModal.querySelector('h4').textContent = 'Payment Failed';
        
        setTimeout(() => {
            processingModal.style.display = 'none';
            payButton.disabled = false;
            payButton.innerHTML = '<i class="fas fa-credit-card me-2"></i> Pay Now via Paystack';
        }, 3000);
    });
}

// Payment processing function
function processPayment( data) {
    // Define these at the top of the function
    const processingModal = document.getElementById('paymentProcessing');
    const payButton = document.getElementById('payButton');
    const statusText = document.getElementById('paymentStatusText');
    
    statusText.textContent = 'Verifying payment...';
    payButton.disabled = true;
    payButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';

    // Prepare the data structure for verification
    const postData = {
        reference: data.reference,
        student_id:  data.metadata.student_id
    };

    console.log('Sending payment verification:', postData);
 // Validate booking data

    fetch("verify_booking_payment.php", {
        method: "POST",
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify( postData),
        credentials: 'include'
    })
    .then(response => {
        console.log('Payment verification response 1:', response);
        
        
        if (!response.ok) {
            return response.json().then(err => {throw err;
            });
            
        }
        return response.json();
        console.log('Payment verification data 2:', response.json());
    })
    .then(data => {
        if (data.success) {

            showAlert('Payment successful! Redirecting...', 'green');
            setTimeout(() => {
                window.location.href = `payment_success.php?reference=${data.reference}`;
            }, 5000);
           
        } else {
            throw new Error(data.message || 'Payment verification failed');
        }
    })
     .catch(error => {
        console.error('Payment Error:', error);
        statusText.textContent = 'Error: ' + error.message;
        
        const spinnerIcon = processingModal.querySelector('.fa-spinner');
        if (spinnerIcon) {
            spinnerIcon.className = 'fas fa-times-circle fa-3x mb-3 text-danger';
        }
        
        processingModal.querySelector('h4').textContent = 'Verification Failed';
        
        // Special handling for session expiration
        if (error.message.includes('session') || error.message.includes('expired')) {
            setTimeout(() => {
                window.location.href = '../../auth/login.php';
            }, 2000);
        } else {
            payButton.disabled = false;
            payButton.innerHTML = '<i class="fas fa-credit-card me-2"></i> Pay Now via Paystack';
        }
    })
    .finally(() => {
        // Always hide processing modal
        processingModal.style.display = 'none';
    });
}
</script>

   
</body>
</html>