<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
// require_once __DIR__ . '/../../includes/fraud_detection.php';
// require_once __DIR__ . '/../../includes/credit_scoring.php';
// require_once '../roommate-matching/index.php';

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
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
    
    return '../../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');


// Fetch bookings with AI enhancements
$stmt = $pdo->prepare("
    SELECT b.*, p.property_name, p.location, p.price,
           u.username as student_name, u.email as student_email, u.credit_score,
           (SELECT COUNT(*) FROM reviews WHERE user_id = u.id) as review_count
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    WHERE p.owner_id = ?
    ORDER BY b.booking_date DESC
");
$stmt->execute([$owner_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Booking Management | UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vanilla-datatables@1.6.16/dist/vanilla-dataTables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-color);
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Top Navigation Bar */
        .top-nav {
            background: var(--secondary-color);
            color: white;
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            z-index: 1000;
            transition: all var(--transition-speed);
            box-shadow: var(--box-shadow);
        }

        .top-nav-collapsed {
            left: var(--sidebar-collapsed-width);
        }

        .top-nav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            cursor: pointer;
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
        .sidebar {
            background: var(--secondary-color);
            color: white;
            width: var(--sidebar-width);
            min-height: 100vh;
            transition: all var(--transition-speed);
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
        }

        .sidebar-header {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: var(--header-height);
            display: flex;
            align-items: center;
        }

        .sidebar-menu {
            padding: 1rem 0;
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }

        .sidebar-menu a {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 0;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all var(--transition-speed);
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            color: white;
            background: rgba(0, 0, 0, 0.2);
            border-left: 3px solid var(--primary-color);
        }

        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 1.5rem;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            flex: 1;
            padding: 2rem;
            transition: all var(--transition-speed);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-group h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .range-slider {
            width: 100%;
            margin: 1rem 0;
        }

        .range-slider input[type="range"] {
            width: 100%;
            height: 8px;
            -webkit-appearance: none;
            background: #ddd;
            border-radius: 5px;
            outline: none;
        }

        .range-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            background: var(--primary-color);
            border-radius: 50%;
            cursor: pointer;
        }

        .range-values {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--secondary-color);
        }

        /* Booking Cards */
        .booking-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            transition: all var(--transition-speed);
            overflow: hidden;
            position: relative;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .booking-card .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
            position: relative;
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .status-confirmed {
            background-color: var(--success-color);
            color: white;
        }

        .status-cancelled {
            background-color: var(--accent-color);
            color: white;
        }

        .fraud-alert {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background-color: var(--accent-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .credit-score {
            position: absolute;
            top: 3rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: hsl(calc(var(--score) * 1.2), 100%, 50%);
            color: white;
        }

        .booking-details {
            display: flex;
            flex-wrap: wrap;
            padding: 1.5rem;
        }

        .booking-detail {
            flex: 1 1 200px;
            margin-bottom: 1rem;
            padding-right: 1rem;
        }

        .booking-detail h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .booking-detail p {
            margin-bottom: 0;
            color: var(--dark-color);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            padding: 0 1.5rem 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all var(--transition-speed);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow);
        }

        .ai-recommendations {
            background-color: rgba(52, 152, 219, 0.1);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 0 1.5rem 1.5rem;
            border-left: 3px solid var(--primary-color);
        }

        /* Stats Cards */
        .stats-card {
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--card-shadow);
        }

        .stats-card-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
        }

        .stats-card-success {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
        }

        .stats-card-warning {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
        }

        .stats-card-danger {
            background: linear-gradient(135deg, var(--accent-color), #c0392b);
        }

        .stats-card h3 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stats-card p {
            margin-bottom: 0;
            opacity: 0.9;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-header span, .sidebar-menu a span {
                display: none;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 0.75rem;
            }
            
            .sidebar-menu a i {
                margin-right: 0;
                font-size: 1.25rem;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }

            .top-nav {
                left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .booking-detail {
                flex: 1 1 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }

            .stats-card {
                margin-bottom: 1rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }
            
            .booking-card .card-header {
                padding: 1rem;
            }
            
            .booking-details {
                padding: 1rem;
            }
            
            .status-badge, .fraud-alert, .credit-score {
                position: static;
                display: inline-block;
                margin-bottom: 0.5rem;
            }

            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
            }

            .filter-section {
                padding: 1rem;
            }
        }

        /* Dark Mode Toggle */
        .dark-mode-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-right: 1rem;
        }

        .dark-mode-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .dark-mode-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .dark-mode-slider:before {
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

        input:checked + .dark-mode-slider {
            background-color: var(--primary-color);
        }

        input:checked + .dark-mode-slider:before {
            transform: translateX(26px);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-right: 1rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .top-nav {
                left: 0;
            }
            
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }
            
            .sidebar-overlay-open {
                display: block;
            }
        }

        /* Loading Animation */
        .loading-spinner {
            display: none;
            width: 40px;
            height: 40px;
            margin: 0 auto;
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>Landlords&Tenant</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="../dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../property_dashboard.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="index.php" class="active">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="../payments/index.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="../chat/index.php">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../maintenance/index.php">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="../virtual-tours/index">
                <i class="fas fa-vr-cardboard"></i>
                <span>Virtual Tours</span>
            </a>
            <a href="../settings/index.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav" id="topNav">
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-calendar-check me-2"></i>Booking Management</h5>
        
        <div class="top-nav-right">
            <label class="dark-mode-toggle">
                <input type="checkbox" id="darkModeToggle">
                <span class="dark-mode-slider"></span>
            </label>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
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
                           <i class="fas fa-sign-out-alt "></i> Logout
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
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card stats-card-primary">
                        <h3><?= count($bookings) ?></h3>
                        <p>Total Bookings</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card stats-card-success">
                        <h3><?= count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed')) ?></h3>
                        <p>Confirmed</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card stats-card-warning">
                        <h3><?= count(array_filter($bookings, fn($b) => $b['status'] === 'pending')) ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card stats-card-danger">
                        <h3><?= count(array_filter($bookings, fn($b) => $b['status'] === 'cancelled')) ?></h3>
                        <p>Cancelled</p>
                    </div>
                </div>
            </div>
            
            <div class="filter-section">
                <h4 class="mb-4"><i class="fas fa-filter me-2"></i>Advanced Filters</h4>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="filter-group">
                            <label for="searchBookings" class="form-label">Search</label>
                            <input type="text" id="searchBookings" class="form-control" placeholder="Search bookings...">
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="filter-group">
                            <label for="filterStatus" class="form-label">Status</label>
                            <select id="filterStatus" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="filter-group">
                            <label for="filterDateRange" class="form-label">Date Range</label>
                            <input type="text" id="filterDateRange" class="form-control" placeholder="Select date range">
                        </div>
                    </div>
                </div>
    
                <div class="row">
            
                    <div class="col-md-6 mb-3">
                        <div class="filter-group">
                            <h6>Price Range</h6>
                            <div class="range-slider">
                                <input type="range" id="priceRangeSlider" min="0" max="5000" value="5000" step="50">
                                <div class="range-values">
                                    <span>GHS 0</span>
                                    <span>GHS 10000</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <button class="btn btn-primary me-2" id="applyFilters">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                        <button class="btn btn-outline-secondary" id="resetFilters">
                            <i class="fas fa-undo me-2"></i>Reset
                        </button>
                    </div>
                </div>
            </div>

            <div class="loading-spinner" id="loadingSpinner"></div>
            
            <div class="row" id="bookingsContainer">
                <?php if (empty($bookings)): ?>
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No bookings found.
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($bookings as $booking): ?>
                        <div class="col-lg-6 booking-item" 
                             data-status="<?= $booking['status'] ?>" 
                             data-price="<?= $booking['price'] ?>"
                             data-start-date="<?= date('Y-m-d', strtotime($booking['start_date'])) ?>"
                             data-end-date="<?= date('Y-m-d', strtotime($booking['end_date'])) ?>">
                            <div class="booking-card card">
                          
                                <div class="card-header">
                                    <h5 class="mb-0"><?= htmlspecialchars($booking['property_name']) ?></h5>
                                    <span class="status-badge <?= 'status-' . $booking['status'] ?>">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                    <div class="credit-score" style="--score: <?= $booking['credit_score'] ?>">
                                        <i class="fas fa-credit-card me-1"></i> <?= $booking['credit_score'] ?>
                                    </div>
                                </div>
                                
                                <div class="booking-details">
                                    <div class="booking-detail">
                                        <h6><i class="fas fa-user me-2"></i>Student</h6>
                                        <p><?= htmlspecialchars($booking['student_name']) ?></p>
                                        <p><?= htmlspecialchars($booking['student_email']) ?></p>
                                        <p><?= $booking['review_count'] ?> reviews</p>
                                    </div>
                                    <div class="booking-detail">
                                        <h6><i class="fas fa-map-marker-alt me-2"></i>Location</h6>
                                        <p><?= htmlspecialchars($booking['location']) ?></p>
                                    </div>
                                    <div class="booking-detail">
                                        <h6><i class="fas fa-calendar-alt me-2"></i>Dates</h6>
                                        <p><?= date('M j, Y', strtotime($booking['start_date'])) ?> to <?= date('M j, Y', strtotime($booking['end_date'])) ?></p>
                                        <p><?= $booking['duration_months'] ?> months</p>
                                    </div>
                                    <div class="booking-detail">
                                        <h6><i class="fas fa-info-circle me-2"></i>Details</h6>
                                        <p>Booked on <?= date('M j, Y', strtotime($booking['booking_date'])) ?></p>
                                        <p>Price: GHS <?= number_format($booking['price'], 2) ?></p>
                                        <?php if ($booking['special_requests']): ?>
                                            <p><strong>Requests:</strong> <?= htmlspecialchars($booking['special_requests']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                               
                                
                                <div class="action-buttons">
                                    <?php if ($booking['status'] === 'pending'): ?>
                                        <a href="approve.php?id=<?= $booking['id'] ?>" class="btn btn-success">
                                            <i class="fas fa-check me-1"></i> Approve
                                        </a>
                                        <a href="reject.php?id=<?= $booking['id'] ?>" class="btn btn-danger">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </a>
                                    <?php endif; ?>
                                    <a href="../chat/?booking=<?= $booking['id'] ?>" class="btn btn-info">
                                        <i class="fas fa-comments me-1"></i> Chat
                                    </a>
                                    <a href="../virtual-tours/api/schedule.php?property=<?= $booking['property_id'] ?>" class="btn btn-secondary">
                                        <i class="fas fa-vr-cardboard me-1"></i> Virtual Tour
                                    </a>
                                   
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    // Mobile menu toggle
    document.getElementById('mobileMenuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
        document.getElementById('sidebarOverlay').classList.toggle('sidebar-overlay-open');
    });

    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('sidebar-open');
        this.classList.remove('sidebar-overlay-open');
    });

    // Initialize date range picker
    flatpickr("#filterDateRange", {
        mode: "range",
        dateFormat: "Y-m-d",
        allowInput: true
    });

    // Filter bookings with advanced filters
    document.getElementById('applyFilters').addEventListener('click', function() {
        const searchTerm = document.getElementById('searchBookings').value.toLowerCase();
        const statusFilter = document.getElementById('filterStatus').value;
        const priceThreshold = document.getElementById('priceRangeSlider').value;
        
        const loadingSpinner = document.getElementById('loadingSpinner');
        const bookingsContainer = document.getElementById('bookingsContainer');
        
        loadingSpinner.style.display = 'block';
        bookingsContainer.style.opacity = '0.5';
        
        // Simulate loading delay for better UX
        setTimeout(() => {
            document.querySelectorAll('.booking-item').forEach(item => {
                const text = item.textContent.toLowerCase();
                const status = item.dataset.status;
                const price = parseFloat(item.dataset.price);
                const startDate = item.dataset.startDate;
                const endDate = item.dataset.endDate;
                
                // Date range filter
                let dateInRange = true;
                if (dateRange) {
                    const dates = dateRange.split(' to ');
                    if (dates.length === 2) {
                        const startFilter = new Date(dates[0]);
                        const endFilter = new Date(dates[1]);
                        const bookingStart = new Date(startDate);
                        const bookingEnd = new Date(endDate);
                        
                        dateInRange = (bookingStart >= startFilter && bookingStart <= endFilter) || 
                                      (bookingEnd >= startFilter && bookingEnd <= endFilter) ||
                                      (bookingStart <= startFilter && bookingEnd >= endFilter);
                    }
                }
                
                const matchesSearch = text.includes(searchTerm);
                const matchesStatus = statusFilter === '' || status.includes(statusFilter);
                const matchesPrice = price <= priceThreshold;
                
                if (matchesSearch && matchesStatus && matchesFraudRisk && 
                    matchesCompatibility && matchesCreditScore && matchesPrice && dateInRange) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
            
            loadingSpinner.style.display = 'none';
            bookingsContainer.style.opacity = '1';
            
            // Show message if no results
            const visibleBookings = document.querySelectorAll('.booking-item[style="display: block"]').length;
            if (visibleBookings === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'col-md-12';
                noResults.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>No bookings match your filters.
                    </div>
                `;
                bookingsContainer.appendChild(noResults);
            }
        }, 500);
    });
    
    // Reset filters
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchBookings').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterDateRange').value = '';
        document.getElementById('priceRangeSlider').value = 5000;
        
        document.querySelectorAll('.booking-item').forEach(item => {
            item.style.display = 'block';
        });
        
        // Remove any no results message
        const noResults = document.querySelector('.alert-info');
        if (noResults) {
            noResults.remove();
        }
    });
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Dark mode toggle
    document.getElementById('darkModeToggle').addEventListener('change', function() {
        document.body.classList.toggle('dark-mode');
        // Save preference to localStorage
        localStorage.setItem('darkMode', this.checked);
    });

    // Check for saved dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        document.getElementById('darkModeToggle').checked = true;
        document.body.classList.add('dark-mode');
    }

    // Update range slider display
    function updateSliderDisplay(sliderId, outputId, prefix = '', suffix = '') {
        const slider = document.getElementById(sliderId);
        const output = document.getElementById(outputId);
        if (slider && output) {
            output.textContent = prefix + slider.value + suffix;
            slider.addEventListener('input', function() {
                output.textContent = prefix + this.value + suffix;
            });
        }
    }

    // Initialize slider displays
    updateSliderDisplay('priceRangeSlider', 'priceValue', 'GHS');
    </script>
</body>
</html>