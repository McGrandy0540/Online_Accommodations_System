<?php
// student/payment/payment_history.php - Student Payment History Page
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

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build base query for payment history with filters
$query = "
    SELECT 
        py.*,
        p.property_name,
        p.location as property_location,
        u.username as owner_name,
        u.email as owner_email,
        u.phone_number as owner_phone,
        b.start_date,
        b.end_date,
        b.duration_months,
        pr.room_number
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    JOIN users u ON p.owner_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.user_id = ?
";

$params = [$student_id];
$count_params = [$student_id];

// Add filters to query
if ($status_filter) {
    $query .= " AND py.status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
}

if ($date_from) {
    $query .= " AND DATE(py.created_at) >= ?";
    $params[] = $date_from;
    $count_params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(py.created_at) <= ?";
    $params[] = $date_to;
    $count_params[] = $date_to;
}

// Add ordering
$query .= " ORDER BY py.created_at DESC";

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total_count
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    WHERE b.user_id = ?
";

if ($status_filter) {
    $count_query .= " AND py.status = ?";
}

if ($date_from) {
    $count_query .= " AND DATE(py.created_at) >= ?";
}

if ($date_to) {
    $count_query .= " AND DATE(py.created_at) <= ?";
}

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_count = $count_stmt->fetch()['total_count'];
$total_pages = ceil($total_count / $limit);

// Add pagination to main query - using string concatenation for integers
$query .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);

// Get payment history
$payments_stmt = $pdo->prepare($query);
$payments_stmt->execute($params);
$payments = $payments_stmt->fetchAll();

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

// Get unread notifications
$notifications_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$notifications_stmt->execute([$student_id]);
$unread_notifications = $notifications_stmt->fetchAll();

// Get payment statistics - FIXED: Specify py.created_at to avoid ambiguity
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(py.amount) as total_amount,
        AVG(py.amount) as average_amount,
        MIN(py.created_at) as first_payment,
        MAX(py.created_at) as last_payment
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    WHERE b.user_id = ? AND py.status = 'completed'
");
$stats_stmt->execute([$student_id]);
$payment_stats = $stats_stmt->fetch();

// Get status distribution - FIXED: Specify py.created_at to avoid ambiguity
$status_stmt = $pdo->prepare("
    SELECT 
        py.status,
        COUNT(*) as count,
        SUM(py.amount) as total_amount
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    WHERE b.user_id = ?
    GROUP BY py.status
");
$status_stmt->execute([$student_id]);
$status_distribution = $status_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History | Landlords&Tenants</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
            overflow-x: hidden;
        }
        
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background-color: white;
            box-shadow: var(--box-shadow);
            z-index: 1030;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
            padding: 0 15px;
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
            display: block;
            z-index: 1031;
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
            z-index: 1020;
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
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 1.5rem;
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
        
        /* Payment History Specific Styles */
        .stats-card {
            text-align: center;
            padding: 1.5rem;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .filter-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }
        
        .payment-item {
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .payment-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .payment-status-completed {
            border-left-color: var(--success-color);
        }
        
        .payment-status-pending {
            border-left-color: var(--warning-color);
        }
        
        .payment-status-failed {
            border-left-color: var(--danger-color);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .bg-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .bg-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .bg-failed {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }
        
        .export-btn {
            background: var(--success-color);
            color: white;
            border: none;
        }
        
        .export-btn:hover {
            background: #218838;
            color: white;
        }
        
        /* Mobile Styles */
        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
                width: var(--sidebar-width);
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
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
            
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 1015;
            }
            
            .overlay.active {
                display: block;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1.2rem;
            }
            
            .profile-avatar, .profile-avatar-placeholder {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .stats-card {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .filter-card {
                padding: 1rem;
            }
            
            .card-header h5 {
                font-size: 1.1rem;
            }
            
            .btn-group-sm > .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1rem;
            }
            
            .dashboard-header h2 {
                font-size: 1.5rem;
            }
            
            .filter-card {
                padding: 1rem;
            }
            
            .quick-link {
                padding: 10px;
                font-size: 0.9rem;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .stats-card {
                padding: 0.8rem;
            }
            
            .stats-number {
                font-size: 1.3rem;
            }
            
            .stats-label {
                font-size: 0.8rem;
            }
            
            .btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.9rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .payment-item td {
                padding: 0.75rem 0.5rem;
            }
            
            .user-profile span {
                display: none;
            }
            
            .logo span {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-header .row {
                flex-direction: column;
                text-align: center;
            }
            
            .dashboard-header .col-md-8 {
                margin-bottom: 1rem;
            }
            
            .dashboard-header .col-md-4 {
                text-align: center !important;
            }
            
            .profile-avatar, .profile-avatar-placeholder {
                margin: 0 auto 1rem auto;
            }
            
            .filter-card .row {
                flex-direction: column;
            }
            
            .filter-card .col-md-3 {
                margin-bottom: 1rem;
            }
            
            .filter-card .col-md-3:last-child {
                margin-bottom: 0;
            }
            
            .table-responsive {
                font-size: 0.8rem;
            }
            
            .payment-item td {
                padding: 0.5rem 0.25rem;
            }
            
            .status-badge {
                font-size: 0.7rem;
                padding: 3px 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../../assets/images/landlords-logo2.png" alt="Landlords&Tenants Logo">
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
                        <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="../logout.php" method="POST">
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

    <!-- Mobile Overlay -->
    <div class="overlay" id="overlay"></div>

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
                    <li><a href="../profile/index.php"><i class="fas fa-cog me-2"></i> <span>Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="container-fluid">
                <!-- Welcome Header -->
                <div class="dashboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8 d-flex align-items-center flex-column flex-md-row">
                            <?php if (!empty($profile_pic_path)): ?>
                                <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar me-0 me-md-4 mb-3 mb-md-0" alt="Profile Picture">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder me-0 me-md-4 mb-3 mb-md-0">
                                    <?= strtoupper(substr($student['username'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-center text-md-start">
                                <h2>Payment History, <?= htmlspecialchars($student['username']) ?></h2>
                                <p class="mb-0">View and manage your payment records</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-center text-md-end mt-3 mt-md-0">
                            <div class="d-flex align-items-center justify-content-center justify-content-md-end">
                                <div class="me-3 position-relative">
                                    <a href="../notification/index.php" class="text-white position-relative">
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
                
                <!-- Payment Statistics -->
                <div class="row mb-4">
                    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
                        <div class="card stats-card h-100">
                            <div class="stats-number"><?= $payment_stats['total_payments'] ?? 0 ?></div>
                            <div class="stats-label">Total Payments</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
                        <div class="card stats-card h-100">
                            <div class="stats-number">GHS <?= number_format($payment_stats['total_amount'] ?? 0, 2) ?></div>
                            <div class="stats-label">Total Amount</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3 mb-3 mb-sm-0">
                        <div class="card stats-card h-100">
                            <div class="stats-number">GHS <?= number_format($payment_stats['average_amount'] ?? 0, 2) ?></div>
                            <div class="stats-label">Average Payment</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card stats-card h-100">
                            <div class="stats-number">
                                <?php if ($payment_stats['last_payment']): ?>
                                    <?= date('M Y', strtotime($payment_stats['last_payment'])) ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </div>
                            <div class="stats-label">Last Payment</div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="filter-card">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Payments</h5>
                    <form method="GET" action="">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2 flex-fill">
                                    <i class="fas fa-search me-1"></i> Filter
                                </button>
                                <a href="payment_history.php" class="btn btn-outline-secondary flex-fill">
                                    <i class="fas fa-refresh me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Payment History -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                            <h5 class="mb-2 mb-md-0"><i class="fas fa-history me-2"></i>Payment History</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="index.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-arrow-left me-1"></i> Back to Payments
                                </a>
                                <button class="btn btn-success btn-sm export-btn" onclick="exportPayments()">
                                    <i class="fas fa-download me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(count($payments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Transaction ID</th>
                                            <th>Property</th>
                                            <th>Owner</th>
                                            <th>Room</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($payments as $payment): 
                                            $reference = !empty($payment['transaction_id']) ? $payment['transaction_id'] : 'PAY-' . $payment['id'];
                                        ?>
                                        <tr class="payment-item payment-status-<?= $payment['status'] ?>">
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($reference) ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($payment['property_name']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($payment['property_location']) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($payment['owner_name']) ?>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($payment['owner_email']) ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($payment['room_number'])): ?>
                                                    Room <?= htmlspecialchars($payment['room_number']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong>GHS <?= number_format($payment['amount'], 2) ?></strong>
                                            </td>
                                            <td>
                                                <?= ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? 'paystack')) ?>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($payment['created_at'])) ?>
                                                <br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($payment['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge bg-<?= $payment['status'] ?>">
                                                    <?= ucfirst($payment['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm flex-wrap">
                                                    <a href="receipt.php?id=<?= $payment['id'] ?>" class="btn btn-outline-primary">
                                                        <i class="fas fa-receipt"></i> Receipt
                                                    </a>
                                                    <?php if ($payment['status'] === 'pending'): ?>
                                                    <a href="index.php" class="btn btn-outline-warning">
                                                        <i class="fas fa-credit-card"></i> Retry
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <nav aria-label="Payment history pagination">
                                <ul class="pagination">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= $status_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&status=<?= $status_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= $status_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-money-bill-wave fa-4x text-muted mb-3"></i>
                                <h4>No Payment History Found</h4>
                                <p class="text-muted mb-4">You haven't made any payments yet or no payments match your filters.</p>
                                <a href="index.php" class="btn btn-primary">
                                    <i class="fas fa-credit-card me-2"></i> Make Your First Payment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Status Distribution -->
                <?php if(count($status_distribution) > 0): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Payment Status Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach($status_distribution as $status): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="d-flex justify-content-between align-items-center p-3 border rounded">
                                            <div>
                                                <h6 class="mb-1 text-capitalize"><?= $status['status'] ?></h6>
                                                <p class="mb-0 text-muted"><?= $status['count'] ?> payments</p>
                                            </div>
                                            <div class="text-end">
                                                <strong>GHS <?= number_format($status['total_amount'], 2) ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            
            // Toggle sidebar on mobile
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
            
            // Close sidebar when clicking on overlay
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });
            
            // Close sidebar when clicking on a menu item (on mobile)
            if (window.innerWidth < 992) {
                const menuItems = document.querySelectorAll('.sidebar-menu a');
                menuItems.forEach(item => {
                    item.addEventListener('click', function() {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                });
            }
        });

        function exportPayments() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            
            // Show loading state
            const exportBtn = document.querySelector('.export-btn');
            const originalText = exportBtn.innerHTML;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Exporting...';
            exportBtn.disabled = true;
            
            // Create download link
            const exportUrl = `export_payments.php?${params.toString()}`;
            const link = document.createElement('a');
            link.href = exportUrl;
            link.download = 'payment_history.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Reset button state
            setTimeout(() => {
                exportBtn.innerHTML = originalText;
                exportBtn.disabled = false;
            }, 2000);
        }
    </script>
</body>
</html>