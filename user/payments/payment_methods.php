<?php
// student/payment/payment_methods.php - Student Payment Methods Page
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_payment_method'])) {
        // Add new payment method
        $method_type = $_POST['method_type'] ?? '';
        $provider = $_POST['provider'] ?? '';
        $account_details = $_POST['account_details'] ?? '';
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        // Validate input
        $errors = [];
        
        if (empty($method_type)) {
            $errors[] = "Please select a payment method type.";
        }
        
        if (empty($provider)) {
            $errors[] = "Please provide a provider name.";
        }
        
        if (empty($account_details)) {
            $errors[] = "Please provide account details.";
        }
        
        if (empty($errors)) {
            // If setting as default, first remove default from other methods
            if ($is_default) {
                $update_stmt = $pdo->prepare("UPDATE payment_methods SET is_default = 0 WHERE user_id = ?");
                $update_stmt->execute([$student_id]);
            }
            
            // Insert new payment method
            $insert_stmt = $pdo->prepare("
                INSERT INTO payment_methods (user_id, method_type, provider, account_details, is_default, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            if ($insert_stmt->execute([$student_id, $method_type, $provider, $account_details, $is_default])) {
                $_SESSION['success_message'] = "Payment method added successfully!";
                header('Location: payment_methods.php');
                exit();
            } else {
                $errors[] = "Failed to add payment method. Please try again.";
            }
        }
    }
    
    if (isset($_POST['set_default'])) {
        // Set payment method as default
        $method_id = intval($_POST['method_id']);
        
        // Remove default from all methods
        $update_stmt = $pdo->prepare("UPDATE payment_methods SET is_default = 0 WHERE user_id = ?");
        $update_stmt->execute([$student_id]);
        
        // Set new default
        $update_stmt = $pdo->prepare("UPDATE payment_methods SET is_default = 1 WHERE id = ? AND user_id = ?");
        if ($update_stmt->execute([$method_id, $student_id])) {
            $_SESSION['success_message'] = "Default payment method updated successfully!";
            header('Location: payment_methods.php');
            exit();
        }
    }
    
    if (isset($_POST['delete_method'])) {
        // Delete payment method
        $method_id = intval($_POST['method_id']);
        
        $delete_stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND user_id = ?");
        if ($delete_stmt->execute([$method_id, $student_id])) {
            $_SESSION['success_message'] = "Payment method deleted successfully!";
            header('Location: payment_methods.php');
            exit();
        }
    }
}

// Get user's payment methods
$methods_stmt = $pdo->prepare("
    SELECT * FROM payment_methods 
    WHERE user_id = ? 
    ORDER BY is_default DESC, created_at DESC
");
$methods_stmt->execute([$student_id]);
$payment_methods = $methods_stmt->fetchAll();

// Get default payment method
$default_method = null;
foreach ($payment_methods as $method) {
    if ($method['is_default']) {
        $default_method = $method;
        break;
    }
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

// Get payment statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_methods,
        SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_methods
    FROM payment_methods 
    WHERE user_id = ?
");
$stats_stmt->execute([$student_id]);
$method_stats = $stats_stmt->fetch();

// Get method type distribution
$type_stmt = $pdo->prepare("
    SELECT 
        method_type,
        COUNT(*) as count
    FROM payment_methods 
    WHERE user_id = ?
    GROUP BY method_type
");
$type_stmt->execute([$student_id]);
$type_distribution = $type_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods | Landlords&Tenants</title>
    
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
        
        /* Payment Methods Specific Styles */
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
        
        .method-card {
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .method-card.default {
            border-left-color: var(--success-color);
            background: linear-gradient(135deg, #f8fff8, #e8f5e8);
        }
        
        .method-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .method-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .default-badge {
            background: var(--success-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .method-type-badge {
            background: var(--info-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .form-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--card-shadow);
        }
        
        .method-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            
            .stats-card {
                padding: 1rem;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .form-card {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1rem;
            }
            
            .form-card {
                padding: 1rem;
            }
            
            .quick-link {
                padding: 10px;
                font-size: 0.9rem;
            }
            
            .method-actions {
                flex-direction: column;
            }
            
            .method-actions .btn {
                width: 100%;
            }
        }
        
        /* Payment Method Icons */
        .method-mobile_money {
            color: #ff6b35;
        }
        
        .method-credit_card {
            color: #2e86ab;
        }
        
        .method-bank_transfer {
            color: #a23b72;
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
                                <h2>Payment Methods, <?= htmlspecialchars($student['username']) ?></h2>
                                <p class="mb-0">Manage your payment methods for seamless transactions</p>
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

                <!-- Success Message -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= $_SESSION['success_message'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Payment Method Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="stats-number"><?= $method_stats['total_methods'] ?? 0 ?></div>
                            <div class="stats-label">Total Methods</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="stats-number"><?= $method_stats['default_methods'] ?? 0 ?></div>
                            <div class="stats-label">Default Method</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="stats-number">
                                <?= $default_method ? ucfirst(str_replace('_', ' ', $default_method['method_type'])) : 'Not Set' ?>
                            </div>
                            <div class="stats-label">Current Default</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column - Add Payment Method -->
                    <div class="col-lg-5">
                        <div class="form-card">
                            <h4 class="mb-4"><i class="fas fa-plus-circle me-2 text-primary"></i>Add New Payment Method</h4>
                            
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="method_type" class="form-label">Payment Method Type</label>
                                    <select class="form-select" id="method_type" name="method_type" required>
                                        <option value="">Select a method type</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="credit_card">Credit Card</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="provider" class="form-label">Provider</label>
                                    <input type="text" class="form-control" id="provider" name="provider" 
                                           placeholder="e.g., MTN Mobile Money, Visa, Ghana Commercial Bank" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="account_details" class="form-label">Account Details</label>
                                    <input type="text" class="form-control" id="account_details" name="account_details" 
                                           placeholder="e.g., 0241234567, 4111-1111-1111-1111, Account Number" required>
                                    <div class="form-text">
                                        For Mobile Money: Phone Number<br>
                                        For Credit Card: Card Number<br>
                                        For Bank Transfer: Account Number
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1">
                                        <label class="form-check-label" for="is_default">
                                            Set as default payment method
                                        </label>
                                    </div>
                                </div>
                                
                                <button type="submit" name="add_payment_method" class="btn btn-primary w-100 py-2">
                                    <i class="fas fa-save me-2"></i> Add Payment Method
                                </button>
                            </form>
                        </div>

                        <!-- Quick Links -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body p-2">
                                <a href="index.php" class="quick-link">
                                    <i class="fas fa-credit-card me-2"></i> Make Payment
                                </a>
                                <a href="payment_history.php" class="quick-link">
                                    <i class="fas fa-history me-2"></i> Payment History
                                </a>
                                <a href="../bookings/index.php" class="quick-link">
                                    <i class="fas fa-calendar-check me-2"></i> My Bookings
                                </a>
                                <a href="../search/index.php" class="quick-link">
                                    <i class="fas fa-search me-2"></i> Find Accommodation
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column - Payment Methods List -->
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Your Payment Methods</h5>
                                    <span class="badge bg-primary"><?= count($payment_methods) ?> methods</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(count($payment_methods) > 0): ?>
                                    <div class="row g-3">
                                        <?php foreach($payment_methods as $method): ?>
                                        <div class="col-12">
                                            <div class="card method-card <?= $method['is_default'] ? 'default' : '' ?>">
                                                <div class="card-body">
                                                    <div class="row align-items-center">
                                                        <div class="col-md-2 text-center">
                                                            <?php 
                                                            $icon_class = "method-{$method['method_type']}";
                                                            $icon = match($method['method_type']) {
                                                                'mobile_money' => 'fa-mobile-alt',
                                                                'credit_card' => 'fa-credit-card',
                                                                'bank_transfer' => 'fa-university',
                                                                default => 'fa-wallet'
                                                            };
                                                            ?>
                                                            <i class="fas <?= $icon ?> method-icon <?= $icon_class ?>"></i>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <h6 class="card-title mb-1">
                                                                <?= htmlspecialchars($method['provider']) ?>
                                                                <?php if($method['is_default']): ?>
                                                                    <span class="default-badge ms-2">Default</span>
                                                                <?php endif; ?>
                                                            </h6>
                                                            <p class="card-text mb-1">
                                                                <span class="method-type-badge">
                                                                    <?= ucfirst(str_replace('_', ' ', $method['method_type'])) ?>
                                                                </span>
                                                            </p>
                                                            <p class="card-text text-muted small mb-0">
                                                                <i class="fas fa-info-circle me-1"></i>
                                                                <?= htmlspecialchars($method['account_details']) ?>
                                                            </p>
                                                            <p class="card-text text-muted small mb-0">
                                                                <i class="fas fa-calendar me-1"></i>
                                                                Added <?= date('M j, Y', strtotime($method['created_at'])) ?>
                                                            </p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="method-actions">
                                                                <?php if(!$method['is_default']): ?>
                                                                    <form method="POST" action="" class="d-inline">
                                                                        <input type="hidden" name="method_id" value="<?= $method['id'] ?>">
                                                                        <button type="submit" name="set_default" class="btn btn-outline-success btn-sm">
                                                                            <i class="fas fa-star me-1"></i> Set Default
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment method?');">
                                                                    <input type="hidden" name="method_id" value="<?= $method['id'] ?>">
                                                                    <button type="submit" name="delete_method" class="btn btn-outline-danger btn-sm">
                                                                        <i class="fas fa-trash me-1"></i> Delete
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-credit-card fa-4x text-muted mb-3"></i>
                                        <h4>No Payment Methods</h4>
                                        <p class="text-muted mb-4">You haven't added any payment methods yet. Add your first payment method to get started.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Method Type Distribution -->
                        <?php if(count($type_distribution) > 0): ?>
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Payment Method Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach($type_distribution as $type): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="d-flex justify-content-between align-items-center p-3 border rounded">
                                            <div>
                                                <h6 class="mb-1 text-capitalize"><?= str_replace('_', ' ', $type['method_type']) ?></h6>
                                                <p class="mb-0 text-muted"><?= $type['count'] ?> method(s)</p>
                                            </div>
                                            <div>
                                                <?php 
                                                $icon = match($type['method_type']) {
                                                    'mobile_money' => 'fa-mobile-alt',
                                                    'credit_card' => 'fa-credit-card',
                                                    'bank_transfer' => 'fa-university',
                                                    default => 'fa-wallet'
                                                };
                                                $color_class = "method-{$type['method_type']}";
                                                ?>
                                                <i class="fas <?= $icon ?> fa-2x <?= $color_class ?>"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Security Notice -->
                        <div class="alert alert-info mt-4">
                            <h6><i class="fas fa-shield-alt me-2"></i>Security Notice</h6>
                            <p class="mb-0 small">
                                Your payment information is securely encrypted and stored. We never share your payment details with third parties. 
                                For added security, we recommend regularly reviewing your payment methods and removing any that are no longer in use.
                            </p>
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

            // Dynamic form help text based on method type
            const methodTypeSelect = document.getElementById('method_type');
            const accountDetailsInput = document.getElementById('account_details');
            const providerInput = document.getElementById('provider');
            
            function updateFormPlaceholders() {
                const methodType = methodTypeSelect.value;
                
                switch(methodType) {
                    case 'mobile_money':
                        providerInput.placeholder = 'e.g., MTN Mobile Money, Vodafone Cash, AirtelTigo Money';
                        accountDetailsInput.placeholder = 'e.g., 0241234567';
                        break;
                    case 'credit_card':
                        providerInput.placeholder = 'e.g., Visa, MasterCard, American Express';
                        accountDetailsInput.placeholder = 'e.g., 4111-1111-1111-1111';
                        break;
                    case 'bank_transfer':
                        providerInput.placeholder = 'e.g., Ghana Commercial Bank, ABSA Bank, Standard Chartered';
                        accountDetailsInput.placeholder = 'e.g., 1234567890';
                        break;
                    default:
                        providerInput.placeholder = 'e.g., MTN Mobile Money, Visa, Ghana Commercial Bank';
                        accountDetailsInput.placeholder = 'e.g., 0241234567, 4111-1111-1111-1111, Account Number';
                }
            }
            
            if (methodTypeSelect) {
                methodTypeSelect.addEventListener('change', updateFormPlaceholders);
                // Initialize on page load
                updateFormPlaceholders();
            }
        });
    </script>
</body>
</html>