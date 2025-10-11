<?php
// student/payment/receipt.php - Payment Receipt Page
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

// Get payment ID from URL
$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($payment_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get payment details - Fixed query to use correct field names
$payment_stmt = $pdo->prepare("
    SELECT 
        py.*,
        b.id as booking_id,
        b.start_date,
        b.end_date,
        b.duration_months,
        p.id as property_id,
        p.property_name,
        p.location as address,
        p.price,
        pr.id as room_id,
        pr.room_number,
        u.id as owner_id,
        u.username as owner_name,
        u.phone_number as owner_phone,
        u.email as owner_email
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    JOIN users u ON p.owner_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE py.id = ? AND b.user_id = ?
");
$payment_stmt->execute([$payment_id, $student_id]);
$payment = $payment_stmt->fetch();

if (!$payment) {
    header('Location: index.php');
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

// Get unread notifications
$notifications_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$notifications_stmt->execute([$student_id]);
$unread_notifications = $notifications_stmt->fetchAll();

// Use transaction_id as reference if available
$reference = !empty($payment['transaction_id']) ? $payment['transaction_id'] : 'PAY-' . $payment['id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt | Landlords&Tenants</title>
    
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
            height: 100px;
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
        
        /* Receipt Specific Styles */
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 1.5rem;
        }
        
        .receipt-body {
            background-color: white;
            padding: 2rem;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }
        
        .receipt-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .receipt-subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .receipt-details {
            margin-bottom: 2rem;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .receipt-row:last-child {
            border-bottom: none;
        }
        
        .receipt-label {
            font-weight: 500;
            color: #6c757d;
        }
        
        .receipt-value {
            font-weight: 500;
            text-align: right;
        }
        
        .receipt-divider {
            height: 2px;
            background-color: #e9ecef;
            margin: 1.5rem 0;
        }
        
        .receipt-total {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .receipt-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e9ecef;
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .receipt-qr {
            max-width: 150px;
            margin: 1rem auto;
        }
        
        .receipt-actions {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
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
            
            .receipt-body {
                padding: 1.5rem;
            }
            
            .receipt-title {
                font-size: 1.5rem;
            }
            
            .receipt-actions {
                flex-direction: column;
            }
            
            .receipt-actions .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1rem;
            }
            
            .receipt-body {
                padding: 1rem;
            }
            
            .receipt-title {
                font-size: 1.3rem;
            }
            
            .receipt-row {
                flex-direction: column;
            }
            
            .receipt-value {
                text-align: left;
                margin-top: 0.25rem;
            }
            
            .quick-link {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
        
        /* Print Styles */
        @media print {
            .main-header, .sidebar, .dashboard-header, .receipt-actions {
                display: none !important;
            }
            
            body {
                background: white;
                padding-top: 0;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            .receipt-container {
                max-width: 100%;
                box-shadow: none;
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
                                <h2>Payment Receipt, <?= htmlspecialchars($student['username']) ?></h2>
                                <p class="mb-0">Your payment confirmation and details</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-flex align-items-center justify-content-end">
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
                
                <!-- Receipt Content -->
                <div class="receipt-container">
                    <div class="card">
                        <div class="receipt-header">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h1 class="receipt-title"><i class="fas fa-receipt me-2"></i>Payment Receipt</h1>
                                    <p class="receipt-subtitle">Landlords&Tenants Accommodation Platform</p>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <h3 class="mb-0">#<?= htmlspecialchars($reference) ?></h3>
                                    <p class="mb-0"><?= date('F j, Y', strtotime($payment['created_at'])) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="receipt-body">
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h5>From:</h5>
                                    <p class="mb-1"><strong><?= htmlspecialchars($student['username']) ?></strong></p>
                                    <p class="mb-1"><?= htmlspecialchars($student['email']) ?></p>
                                    <p class="mb-0">Student ID: <?= htmlspecialchars($student_id) ?></p>
                                </div>
                                <div class="col-md-6">
                                    <h5>To:</h5>
                                    <p class="mb-1"><strong><?= htmlspecialchars($payment['owner_name']) ?></strong></p>
                                    <p class="mb-1"><?= htmlspecialchars($payment['owner_email']) ?></p>
                                    <p class="mb-0"><?= htmlspecialchars($payment['owner_phone']) ?></p>
                                </div>
                            </div>
                            
                            <div class="receipt-divider"></div>
                            
                            <div class="receipt-details">
                                <h5 class="mb-3">Payment Details</h5>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Property</span>
                                    <span class="receipt-value"><?= htmlspecialchars($payment['property_name']) ?></span>
                                </div>
                                
                                <?php if (!empty($payment['room_number'])): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Room Number</span>
                                    <span class="receipt-value"><?= htmlspecialchars($payment['room_number']) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Booking Period</span>
                                    <span class="receipt-value">
                                        <?= date('M j, Y', strtotime($payment['start_date'])) ?> - 
                                        <?= date('M j, Y', strtotime($payment['end_date'])) ?>
                                    </span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Duration</span>
                                    <span class="receipt-value"><?= $payment['duration_months'] ?> month(s)</span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Monthly Rate</span>
                                    <span class="receipt-value">GHS <?= number_format($payment['price'], 2) ?></span>
                                </div>
                                
                                <div class="receipt-divider"></div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Payment Method</span>
                                    <span class="receipt-value"><?= ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? 'paystack')) ?></span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Transaction ID</span>
                                    <span class="receipt-value"><?= htmlspecialchars($reference) ?></span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Payment Date</span>
                                    <span class="receipt-value"><?= date('F j, Y g:i A', strtotime($payment['created_at'])) ?></span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Status</span>
                                    <span class="receipt-value">
                                        <span class="badge bg-success"><?= ucfirst($payment['status']) ?></span>
                                    </span>
                                </div>
                                
                                <div class="receipt-divider"></div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Subtotal</span>
                                    <span class="receipt-value">GHS <?= number_format($payment['amount'], 2) ?></span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Platform Fee</span>
                                    <span class="receipt-value">GHS 0.00</span>
                                </div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label">Tax</span>
                                    <span class="receipt-value">GHS 0.00</span>
                                </div>
                                
                                <div class="receipt-divider"></div>
                                
                                <div class="receipt-row">
                                    <span class="receipt-label receipt-total">Total Amount</span>
                                    <span class="receipt-value receipt-total">GHS <?= number_format($payment['amount'], 2) ?></span>
                                </div>
                            </div>
                            
                            <div class="receipt-footer">
                                <p>Thank you for your payment. This receipt confirms your transaction with Landlords&Tenants.</p>
                                <p>For any questions regarding this payment, please contact support.</p>
                                
                                <div class="receipt-qr">
                                    <!-- QR Code placeholder - you can generate a real QR code with payment details -->
                                    <div class="bg-light p-3 text-center rounded">
                                        <i class="fas fa-qrcode fa-3x text-muted mb-2"></i>
                                        <p class="small mb-0">Transaction #<?= htmlspecialchars($reference) ?></p>
                                    </div>
                                </div>
                                
                                <p class="mt-3">
                                    <small>
                                        This is an electronic receipt. No signature is required.<br>
                                        Generated on <?= date('F j, Y \a\t g:i A') ?>
                                    </small>
                                </p>
                            </div>
                            
                            <div class="receipt-actions">
                                <button onclick="window.print()" class="btn btn-primary">
                                    <i class="fas fa-print me-2"></i> Print Receipt
                                </button>
                                <a href="index.php" class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Payments
                                </a>
                                <a href="../dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar on mobile
            document.getElementById('menuToggle').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
        });
    </script>
</body>
</html>