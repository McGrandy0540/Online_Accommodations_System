<?php
// owner/reports.php - Property Owner Reports
session_start();
require_once '../config/database.php';

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

// Process report generation
$report_data = [];
$report_type = $_GET['report'] ?? '';

// Property Listings Report
if ($report_type === 'properties') {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               COUNT(pr.id) AS total_rooms,
               SUM(CASE WHEN pr.levy_payment_status = 'approved' THEN 1 ELSE 0 END) AS active_rooms,
               SUM(CASE WHEN pr.levy_payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_rooms,
               SUM(CASE WHEN pr.levy_payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_rooms,
               SUM(CASE WHEN pr.levy_expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_rooms
        FROM property p
        LEFT JOIN property_rooms pr ON p.id = pr.property_id
        WHERE p.owner_id = ? AND p.deleted = 0
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$owner_id]);
    $report_data = $stmt->fetchAll();
}

// Financial Earnings Report
if ($report_type === 'earnings') {
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(py.created_at) AS month,
            YEAR(py.created_at) AS year,
            SUM(py.amount) AS total_earnings,
            COUNT(py.id) AS payment_count,
            p.property_name
        FROM payments py
        JOIN bookings b ON py.booking_id = b.id
        JOIN property p ON b.property_id = p.id
        WHERE p.owner_id = ? AND py.status = 'completed'
        GROUP BY YEAR(py.created_at), MONTH(py.created_at), p.id
        ORDER BY year DESC, month DESC
    ");
    $stmt->execute([$owner_id]);
    $report_data = $stmt->fetchAll();
}

// Room Levy Status Report
if ($report_type === 'levy') {
    $stmt = $pdo->prepare("
        SELECT 
            pr.room_number,
            p.property_name,
            pr.capacity,
            pr.gender,
            pr.levy_payment_status,
            pr.levy_expiry_date,
            pr.renewal_count,
            pr.payment_amount,
            DATEDIFF(pr.levy_expiry_date, CURDATE()) AS days_remaining
        FROM property_rooms pr
        JOIN property p ON pr.property_id = p.id
        WHERE p.owner_id = ? AND p.deleted = 0
        ORDER BY p.property_name, pr.room_number
    ");
    $stmt->execute([$owner_id]);
    $report_data = $stmt->fetchAll();
}

// Booking Report
if ($report_type === 'bookings') {
    $stmt = $pdo->prepare("
        SELECT 
            b.id,
            b.start_date,
            b.end_date,
            b.status,
            b.booking_date,
            p.property_name,
            pr.room_number,
            u.username AS student_name,
            py.amount AS payment_amount,
            py.created_at AS payment_date
        FROM bookings b
        JOIN property p ON b.property_id = p.id
        LEFT JOIN property_rooms pr ON b.room_id = pr.id
        JOIN users u ON b.user_id = u.id
        LEFT JOIN payments py ON b.id = py.booking_id AND py.status = 'completed'
        WHERE p.owner_id = ?
        ORDER BY b.booking_date DESC
    ");
    $stmt->execute([$owner_id]);
    $report_data = $stmt->fetchAll();
}

// Maintenance Report
if ($report_type === 'maintenance') {
    $stmt = $pdo->prepare("
        SELECT 
            mr.id,
            mr.title,
            mr.description,
            mr.status,
            mr.priority,
            mr.created_at,
            mr.completed_at,
            p.property_name,
            pr.room_number,
            u.username AS reporter
        FROM maintenance_requests mr
        JOIN property p ON mr.property_id = p.id
        LEFT JOIN property_rooms pr ON mr.id = pr.id
        JOIN users u ON mr.user_id = u.id
        WHERE p.owner_id = ?
        ORDER BY mr.created_at DESC
    ");
    $stmt->execute([$owner_id]);
    $report_data = $stmt->fetchAll();
}

// Get report title based on type
$report_titles = [
    'properties' => 'Property Listings Report',
    'earnings' => 'Financial Earnings Report',
    'levy' => 'Room Levy Status Report',
    'bookings' => 'Booking History Report',
    'maintenance' => 'Maintenance Requests Report'
];

$current_report_title = $report_titles[$report_type] ?? 'Reports Dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Landlords&Tenant</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
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
        
        .report-card {
            cursor: pointer;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .report-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .report-actions {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
            margin-bottom: 20px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .bg-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .bg-confirmed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .bg-paid {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .bg-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .bg-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .bg-in-progress {
            background-color: #fff3cd;
            color: #856404;
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
        
        /* Report Filter Section */
        .report-filters {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
        }
        
        /* Report Table */
        .report-table {
            font-size: 0.9rem;
        }
        
        .report-table th {
            background-color: var(--primary-color);
            color: white;
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
            
            .quick-link {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
        
        /* Export buttons */
        .export-btn {
            position: relative;
        }
        
        .export-options {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            z-index: 100;
            min-width: 150px;
            display: none;
        }
        
        .export-options.show {
            display: block;
        }
        
        .export-option {
            padding: 10px 15px;
            display: block;
            text-decoration: none;
            color: var(--dark-color);
            transition: all 0.2s;
        }
        
        .export-option:hover {
            background-color: var(--light-color);
        }
        
        .export-option i {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../assets/images/ktu logo.png" alt="landlords&tenants Logo">
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
                    <li><a href="property_dashboard.php"><i class="fas fa-home"></i> <span>My Properties</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span>Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span>Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span>Reviews</span></a></li>
                    <li><a href="chat/"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                    <li><a href="virtual-tours/"><i class="fas fa-video"></i> <span>Virtual Tours</span></a></li>
                    <li><a href="announcement.php"><i class="fas fa-bullhorn"></i> <span>Announcements</span></a></li>
                    <li><a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> <span>Reports</span></a></li>
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
                                <h2><?= $current_report_title ?></h2>
                                <p class="mb-0">Generate and analyze your property performance reports</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-flex align-items-center justify-content-end">
                                <div class="me-3 position-relative">
                                    <a href="notifications.php" class="text-white position-relative">
                                        <i class="fas fa-bell fa-lg"></i>
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
                
                <?php if (empty($report_type)): ?>
                <!-- Reports Dashboard -->
                <div class="row">
                    <h3 class="mb-4">Select a Report</h3>
                    
                    <div class="col-md-4 mb-4">
                        <a href="?report=properties" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-home text-primary"></i>
                                <h4>Property Listings</h4>
                                <p class="text-muted">Overview of all your properties and rooms</p>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <a href="?report=earnings" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-money-bill-wave text-success"></i>
                                <h4>Financial Earnings</h4>
                                <p class="text-muted">View your income and payment history</p>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <a href="?report=levy" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-file-invoice-dollar text-info"></i>
                                <h4>Room Levy Status</h4>
                                <p class="text-muted">Track your room levy payments and renewals</p>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <a href="?report=bookings" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-calendar-check text-warning"></i>
                                <h4>Booking History</h4>
                                <p class="text-muted">Review all bookings and occupancy rates</p>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <a href="?report=maintenance" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-tools text-danger"></i>
                                <h4>Maintenance Requests</h4>
                                <p class="text-muted">Track maintenance issues and resolutions</p>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <a href="#" class="text-decoration-none">
                            <div class="card report-card text-center p-4">
                                <i class="fas fa-star-half-alt text-secondary"></i>
                                <h4>Review Analytics</h4>
                                <p class="text-muted">Analyze feedback and ratings from Tenants</p>
                            </div>
                        </a>
                    </div>
                </div>
                
                <!-- Financial Summary -->
                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Financial Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="earningsChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Report Content -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3><?= $current_report_title ?></h3>
                    <div class="export-btn position-relative">
                        <button class="btn btn-primary" id="exportBtn">
                            <i class="fas fa-download me-1"></i> Export Report
                        </button>
                        <div class="export-options" id="exportOptions">
                            <a href="#" class="export-option" data-type="pdf">
                                <i class="fas fa-file-pdf text-danger"></i> PDF
                            </a>
                            <a href="#" class="export-option" data-type="excel">
                                <i class="fas fa-file-excel text-success"></i> Excel
                            </a>
                            <a href="#" class="export-option" data-type="csv">
                                <i class="fas fa-file-csv text-info"></i> CSV
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Report Filters -->
                <div class="report-filters mb-4">
                    <form id="reportFilterForm">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="dateRange" class="form-label">Date Range</label>
                                <select class="form-select" id="dateRange">
                                    <option value="all">All Time</option>
                                    <option value="month">This Month</option>
                                    <option value="quarter">This Quarter</option>
                                    <option value="year">This Year</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3" id="customDateRange" style="display: none;">
                                <label for="startDate" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="startDate">
                            </div>
                            
                            <div class="col-md-4 mb-3" id="customDateRangeEnd" style="display: none;">
                                <label for="endDate" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="endDate">
                            </div>
                            
                            <?php if ($report_type === 'properties' || $report_type === 'levy'): ?>
                            <div class="col-md-4 mb-3">
                                <label for="propertySelect" class="form-label">Property</label>
                                <select class="form-select" id="propertySelect">
                                    <option value="all">All Properties</option>
                                    <!-- Will be populated dynamically -->
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($report_type === 'bookings'): ?>
                            <div class="col-md-4 mb-3">
                                <label for="bookingStatus" class="form-label">Booking Status</label>
                                <select class="form-select" id="bookingStatus">
                                    <option value="all">All Statuses</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="pending">Pending</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($report_type === 'maintenance'): ?>
                            <div class="col-md-4 mb-3">
                                <label for="maintenanceStatus" class="form-label">Status</label>
                                <select class="form-select" id="maintenanceStatus">
                                    <option value="all">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-sync me-1"></i> Reset Filters
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Report Results -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped report-table" id="reportTable">
                                <thead>
                                    <?php if ($report_type === 'properties'): ?>
                                    <tr>
                                        <th>Property Name</th>
                                        <th>Location</th>
                                        <th>Total Rooms</th>
                                        <th>Active Rooms</th>
                                        <th>Pending Rooms</th>
                                        <th>Expired Rooms</th>
                                        <th>Price per Head</th>
                                    </tr>
                                    <?php elseif ($report_type === 'earnings'): ?>
                                    <tr>
                                        <th>Month</th>
                                        <th>Property</th>
                                        <th>Payment Count</th>
                                        <th>Total Earnings</th>
                                        <th>Avg. per Payment</th>
                                    </tr>
                                    <?php elseif ($report_type === 'levy'): ?>
                                    <tr>
                                        <th>Property</th>
                                        <th>Room #</th>
                                        <th>Capacity</th>
                                        <th>Gender</th>
                                        <th>Levy Status</th>
                                        <th>Expiry Date</th>
                                        <th>Days Remaining</th>
                                        <th>Renewals</th>
                                    </tr>
                                    <?php elseif ($report_type === 'bookings'): ?>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Property</th>
                                        <th>Room</th>
                                        <th>Tenant</th>
                                        <th>Booking Date</th>
                                        <th>Status</th>
                                        <th>Payment Amount</th>
                                    </tr>
                                    <?php elseif ($report_type === 'maintenance'): ?>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Property</th>
                                        <th>Room</th>
                                        <th>Title</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Date Created</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php if (!empty($report_data)): ?>
                                        <?php foreach ($report_data as $row): ?>
                                            <?php if ($report_type === 'properties'): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['property_name']) ?></td>
                                                <td><?= htmlspecialchars($row['location']) ?></td>
                                                <td><?= $row['total_rooms'] ?></td>
                                                <td><?= $row['active_rooms'] ?></td>
                                                <td><?= $row['pending_rooms'] + $row['paid_rooms'] ?></td>
                                                <td><?= $row['expired_rooms'] ?></td>
                                                <td>GHS <?= number_format($row['price'], 2) ?></td>
                                            </tr>
                                            <?php elseif ($report_type === 'earnings'): ?>
                                            <tr>
                                                <td><?= date('F Y', mktime(0, 0, 0, $row['month'], 1, $row['year'])) ?></td>
                                                <td><?= htmlspecialchars($row['property_name']) ?></td>
                                                <td><?= $row['payment_count'] ?></td>
                                                <td>GHS <?= number_format($row['total_earnings'], 2) ?></td>
                                                <td>GHS <?= number_format($row['total_earnings'] / $row['payment_count'], 2) ?></td>
                                            </tr>
                                            <?php elseif ($report_type === 'levy'): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['property_name']) ?></td>
                                                <td><?= $row['room_number'] ?></td>
                                                <td><?= $row['capacity'] ?></td>
                                                <td><?= ucfirst($row['gender']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $row['levy_payment_status'] === 'approved' ? 'success' : 
                                                        ($row['levy_payment_status'] === 'paid' ? 'primary' : 'warning')
                                                    ?>">
                                                        <?= ucfirst($row['levy_payment_status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= $row['levy_expiry_date'] ? date('M j, Y', strtotime($row['levy_expiry_date'])) : 'N/A' ?></td>
                                                <td><?= $row['days_remaining'] ?? 'N/A' ?></td>
                                                <td><?= $row['renewal_count'] ?></td>
                                            </tr>
                                            <?php elseif ($report_type === 'bookings'): ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= htmlspecialchars($row['property_name']) ?></td>
                                                <td><?= $row['room_number'] ?? 'N/A' ?></td>
                                                <td><?= htmlspecialchars($row['student_name']) ?></td>
                                                <td><?= date('M j, Y', strtotime($row['booking_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $row['status'] === 'confirmed' ? 'success' : 
                                                        ($row['status'] === 'pending' ? 'warning' : 
                                                        ($row['status'] === 'paid' ? 'primary' : 'danger'))
                                                    ?>">
                                                        <?= ucfirst($row['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= $row['payment_amount'] ? 'GHS '.number_format($row['payment_amount'], 2) : 'N/A' ?></td>
                                            </tr>
                                            <?php elseif ($report_type === 'maintenance'): ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= htmlspecialchars($row['property_name']) ?></td>
                                                <td><?= $row['room_number'] ?? 'N/A' ?></td>
                                                <td><?= htmlspecialchars($row['title']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $row['priority'] === 'high' ? 'danger' : 
                                                        ($row['priority'] === 'medium' ? 'warning' : 'info')
                                                    ?>">
                                                        <?= ucfirst($row['priority']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $row['status'] === 'completed' ? 'success' : 
                                                        ($row['status'] === 'in_progress' ? 'primary' : 'warning')
                                                    ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4">
                                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                                <h5>No Data Available</h5>
                                                <p class="text-muted">No records found for this report</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Report Summary -->
                <?php if ($report_type === 'earnings' && !empty($report_data)): ?>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Earnings by Property</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="propertyEarningsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Monthly Earnings Trend</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="monthlyTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Toggle export options
        document.getElementById('exportBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('exportOptions').classList.toggle('show');
        });

        // Close export options when clicking elsewhere
        document.addEventListener('click', function() {
            document.getElementById('exportOptions').classList.remove('show');
        });

        // Handle export option clicks
        document.querySelectorAll('.export-option').forEach(option => {
            option.addEventListener('click', function(e) {
                e.preventDefault();
                const type = this.getAttribute('data-type');
                exportReport(type);
                document.getElementById('exportOptions').classList.remove('show');
            });
        });

        // Export report function
        function exportReport(type) {
            const table = document.getElementById('reportTable');
            const reportTitle = "<?= $current_report_title ?>";
            const date = new Date().toLocaleDateString();
            
            if (type === 'pdf') {
                // Use jsPDF to export as PDF
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                
                // Add title
                doc.setFontSize(18);
                doc.text(reportTitle, 14, 15);
                doc.setFontSize(10);
                doc.text(`Generated on: ${date}`, 14, 22);
                
                // Add table
                doc.autoTable({
                    html: table,
                    startY: 25,
                    theme: 'grid',
                    headStyles: {
                        fillColor: [52, 152, 219],
                        textColor: 255,
                        fontStyle: 'bold'
                    }
                });
                
                doc.save(`${reportTitle.replace(/\s+/g, '_')}_${date}.pdf`);
            }
            else if (type === 'excel') {
                // Use SheetJS to export as Excel
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.table_to_sheet(table);
                
                // Add title row
                XLSX.utils.sheet_add_aoa(ws, [[reportTitle]], { origin: "A1" });
                XLSX.utils.sheet_add_aoa(ws, [[`Generated on: ${date}`]], { origin: "A2" });
                
                // Add some styling
                const range = XLSX.utils.decode_range(ws['!ref']);
                for (let R = range.s.r; R <= range.e.r; ++R) {
                    if (R === 0 || R === 1) {
                        ws[`A${R+1}`].s = { font: { bold: true } };
                    }
                }
                
                XLSX.utils.book_append_sheet(wb, ws, "Report");
                XLSX.writeFile(wb, `${reportTitle.replace(/\s+/g, '_')}_${date}.xlsx`);
            }
            else if (type === 'csv') {
                // Export as CSV
                let csv = [];
                const rows = table.querySelectorAll('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    const row = [], cols = rows[i].querySelectorAll('td, th');
                    
                    for (let j = 0; j < cols.length; j++) {
                        row.push(cols[j].innerText);
                    }
                    
                    csv.push(row.join(','));
                }
                
                // Download CSV file
                const csvContent = "data:text/csv;charset=utf-8," + csv.join('\n');
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", `${reportTitle.replace(/\s+/g, '_')}_${date}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Show/hide custom date range
        document.getElementById('dateRange').addEventListener('change', function() {
            const customRange = this.value === 'custom';
            document.getElementById('customDateRange').style.display = customRange ? 'block' : 'none';
            document.getElementById('customDateRangeEnd').style.display = customRange ? 'block' : 'none';
        });

        // Chart for financial summary
        <?php if (empty($report_type)): ?>
        // Sample data for financial summary chart
        const ctx = document.getElementById('earningsChart').getContext('2d');
        const earningsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Monthly Earnings (GHS)',
                    data: [1200, 1900, 1500, 1800, 2200, 2500, 2800, 2700, 2400, 2600, 3000, 3500],
                    backgroundColor: 'rgba(52, 152, 219, 0.2)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Charts for earnings report
        <?php if ($report_type === 'earnings' && !empty($report_data)): ?>
        // Property Earnings Chart
        const propertyCtx = document.getElementById('propertyEarningsChart').getContext('2d');
        
        // Extract unique properties and their earnings
        const properties = [...new Set(<?= json_encode(array_column($report_data, 'property_name')) ?>)];
        const propertyEarnings = properties.map(prop => {
            return <?= json_encode($report_data) ?>.reduce((sum, item) => {
                return item.property_name === prop ? sum + parseFloat(item.total_earnings) : sum;
            }, 0);
        });
        
        const propertyEarningsChart = new Chart(propertyCtx, {
            type: 'doughnut',
            data: {
                labels: properties,
                datasets: [{
                    data: propertyEarnings,
                    backgroundColor: [
                        'rgba(52, 152, 219, 0.7)',
                        'rgba(46, 204, 113, 0.7)',
                        'rgba(155, 89, 182, 0.7)',
                        'rgba(241, 196, 15, 0.7)',
                        'rgba(230, 126, 34, 0.7)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `GHS ${context.parsed.toFixed(2)}`;
                            }
                        }
                    }
                }
            }
        });
        
        // Monthly Trend Chart
        const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        
        // Group earnings by month
        const monthlyEarnings = {};
        <?= json_encode($report_data) ?>.forEach(item => {
            const monthYear = `${item.year}-${String(item.month).padStart(2, '0')}`;
            if (!monthlyEarnings[monthYear]) {
                monthlyEarnings[monthYear] = 0;
            }
            monthlyEarnings[monthYear] += parseFloat(item.total_earnings);
        });
        
        // Sort months chronologically
        const sortedMonths = Object.keys(monthlyEarnings).sort();
        const earningsData = sortedMonths.map(month => monthlyEarnings[month]);
        const monthLabels = sortedMonths.map(month => {
            const [year, monthNum] = month.split('-');
            const date = new Date(year, monthNum - 1);
            return date.toLocaleString('default', { month: 'short' }) + ' ' + year;
        });
        
        const monthlyTrendChart = new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'Total Earnings (GHS)',
                    data: earningsData,
                    backgroundColor: 'rgba(46, 204, 113, 0.7)',
                    borderColor: 'rgba(46, 204, 113, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>