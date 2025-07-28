<?php
require_once __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../config/functions.php';

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['status'];

// Check if user is logged in and is a property owner or admin
if (!isset($_SESSION['user_id']) || ($user_type !== 'property_owner' && $user_type !== 'admin')) {
    header("Location: /login.php");
    exit();
}

// Process payment actions if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $payment_id = $_POST['payment_id'] ?? 0;
    
    try {
        $pdo->beginTransaction();
        
        // Get payment details
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            throw new Exception("Payment not found");
        }
        
        // Verify the property belongs to this owner (unless admin)
        if ($user_type !== 'admin') {
            $stmt = $pdo->prepare("
                SELECT p.id FROM property p
                JOIN bookings b ON p.id = b.property_id
                WHERE p.owner_id = ? AND b.id = ?
            ");
            $stmt->execute([$user_id, $payment['booking_id']]);
            if (!$stmt->fetch()) {
                throw new Exception("Unauthorized access to this payment");
            }
        }
        
        switch ($action) {
            case 'confirm':
                // Update payment status
                $stmt = $pdo->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
                $stmt->execute([$payment_id]);
                
                // Update booking status
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'paid' WHERE id = ?");
                $stmt->execute([$payment['booking_id']]);
                
                // Update property status
                $stmt = $pdo->prepare("UPDATE property SET status = 'paid' WHERE id IN (
                    SELECT property_id FROM bookings WHERE id = ?
                )");
                $stmt->execute([$payment['booking_id']]);
                
                // Create notification
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, property_id, message, type, notification_type)
                    SELECT b.user_id, b.property_id, 
                           CONCAT('Payment confirmed for booking #', b.id, ' - $', p.amount), 
                           'payment_received', 'email'
                    FROM payments p
                    JOIN bookings b ON p.booking_id = b.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$payment_id]);
                
                // Update credit score for student (positive for on-time payment)
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET credit_score = LEAST(100, credit_score + 5),
                        last_score_update = CURRENT_TIMESTAMP
                    WHERE id IN (
                        SELECT user_id FROM bookings WHERE id = ?
                    )
                ");
                $stmt->execute([$payment['booking_id']]);
                
                // Record credit score change
                $stmt = $pdo->prepare("
                    INSERT INTO credit_score_history (user_id, score_change, new_score, reason, changed_by)
                    SELECT user_id, 5, 
                           (SELECT credit_score FROM users WHERE id = b.user_id),
                           'On-time payment for booking #' || b.id, 'system'
                    FROM bookings b
                    WHERE b.id = ?
                ");
                $stmt->execute([$payment['booking_id']]);
                
                $_SESSION['success_message'] = "Payment confirmed successfully";
                break;
                
            case 'reject':
                $reason = $_POST['reason'] ?? 'Unknown reason';
                if ($reason === 'Other') {
                    $reason = $_POST['reason_other'] ?? 'Unknown reason';
                }
                
                // Update payment status
                $stmt = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
                $stmt->execute([$payment_id]);
                
                // Update booking status (if it was pending payment)
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'confirmed', 
                        admin_notes = CONCAT(COALESCE(admin_notes, ''), 'Payment rejected: ', ?)
                    WHERE id = ? AND status = 'pending'
                ");
                $stmt->execute([$reason, $payment['booking_id']]);
                
                // Create notification
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, property_id, message, type, notification_type)
                    SELECT b.user_id, b.property_id, 
                           CONCAT('Payment rejected for booking #', b.id, '. Reason: ', ?), 
                           'payment_received', 'email'
                    FROM payments p
                    JOIN bookings b ON p.booking_id = b.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$reason, $payment_id]);
                
                $_SESSION['success_message'] = "Payment rejected successfully";
                break;
                
            default:
                throw new Exception("Invalid action");
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error processing payment: " . $e->getMessage();
    }
    
    header("Location: payment_processing.php");
    exit();
}

// Fetch payment history with gateway details
$query = "
    SELECT p.*, b.property_id, b.user_id, pr.property_name, 
           u.username as student_name, u.email as student_email,
           pm.method_type as payment_method, pm.provider as payment_provider,
           b.start_date, b.end_date, b.duration_months
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN property pr ON b.property_id = pr.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
";

// For property owners, only show their properties' payments
if ($user_type === 'property_owner') {
    $query .= " WHERE pr.owner_id = ?";
    $params = [$user_id];
} else {
    $params = [];
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$total_received = 0;
$pending_payments = 0;
$failed_payments = 0;

foreach ($payments as $payment) {
    if ($payment['status'] === 'completed') {
        $total_received += $payment['amount'];
    } elseif ($payment['status'] === 'pending') {
        $pending_payments++;
    } elseif ($payment['status'] === 'failed') {
        $failed_payments++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payment Processing | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vanilla-datatables@1.6.16/dist/vanilla-dataTables.min.css" rel="stylesheet">
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

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.75rem;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
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

        /* Payment Cards */
        .payment-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            transition: all var(--transition-speed);
        }

        .payment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .payment-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: none;
        }

        .payment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-completed {
            background-color: var(--success-color);
            color: white;
        }

        .status-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .status-failed {
            background-color: var(--accent-color);
            color: white;
        }

        .payment-method {
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .payment-method i {
            margin-right: 0.5rem;
            font-size: 1.25rem;
        }

        .mobile-money { color: #5C2D91; }
        .bank-transfer { color: #0066CC; }
        .credit-card { color: #FF9900; }
        .cash { color: #666666; }

        .payment-details {
            padding: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .detail-item h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .detail-item p {
            margin-bottom: 0;
        }

        .payment-actions {
            padding: 0 1.5rem 1.5rem;
            display: flex;
            gap: 0.5rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
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
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .stats-card {
                margin-bottom: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .payment-card .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .payment-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
            }

            .filter-section .row > div {
                margin-bottom: 1rem;
            }

            .filter-section .d-flex {
                flex-direction: column;
                gap: 0.5rem;
            }

            .filter-section .d-flex .btn {
                width: 100%;
            }
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
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>Landlords&Tenant</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="/property_owner/dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="/property_owner/properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="/property_owner/bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="index.php" class="active">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="/property_owner/chat/">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="/property_owner/maintenance/">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="/property_owner/virtual-tours/">
                <i class="fas fa-vr-cardboard"></i>
                <span>Virtual Tours</span>
            </a>
            <a href="/property_owner/settings.php">
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
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-money-bill-wave me-2"></i>Payment Processing</h5>
        
        <div class="top-nav-right">
            <div class="dropdown">
                <div class="user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <i class="fas fa-chevron-down ms-2 d-none d-md-inline"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="/property_owner/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="/property_owner/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= $_SESSION['error_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <div class="row mb-4">
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-primary">
                        <h3>$<?= number_format($total_received, 2) ?></h3>
                        <p>Total Received</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-success">
                        <h3><?= count($payments) ?></h3>
                        <p>Total Transactions</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-warning">
                        <h3><?= $pending_payments ?></h3>
                        <p>Pending Payments</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-danger">
                        <h3><?= $failed_payments ?></h3>
                        <p>Failed Payments</p>
                    </div>
                </div>
            </div>
            
            <div class="filter-section">
                <h4 class="mb-4"><i class="fas fa-filter me-2"></i>Payment Filters</h4>
                <div class="row">
                    <div class="col-md-4 col-12 mb-3">
                        <label for="searchPayments" class="form-label">Search</label>
                        <input type="text" id="searchPayments" class="form-control" placeholder="Search payments...">
                    </div>
                    <div class="col-md-4 col-12 mb-3">
                        <label for="filterStatus" class="form-label">Status</label>
                        <select id="filterStatus" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-12 mb-3">
                        <label for="filterMethod" class="form-label">Payment Method</label>
                        <select id="filterMethod" class="form-select">
                            <option value="">All Methods</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 col-12 mb-3">
                        <label for="filterDateRange" class="form-label">Date Range</label>
                        <input type="text" id="filterDateRange" class="form-control" placeholder="Select date range">
                    </div>
                    <div class="col-md-6 col-12 mb-3 d-flex align-items-end">
                        <button class="btn btn-primary me-2" id="applyFilters">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                        <button class="btn btn-outline-secondary" id="resetFilters">
                            <i class="fas fa-undo me-2"></i>Reset
                        </button>
                    </div>
                </div>
            </div>

            <?php if (empty($payments)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No payment records found.
                </div>
            <?php else: ?>
                <div class="row" id="paymentsContainer">
                    <?php foreach ($payments as $payment): ?>
                        <div class="col-lg-6 col-12 payment-item" 
                             data-status="<?= $payment['status'] ?>" 
                             data-method="<?= strtolower(str_replace(' ', '_', $payment['payment_method'] ?? 'cash')) ?>"
                             data-amount="<?= $payment['amount'] ?>"
                             data-date="<?= date('Y-m-d', strtotime($payment['created_at'])) ?>">
                            <div class="payment-card card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0">$<?= number_format($payment['amount'], 2) ?></h5>
                                        <small class="text-muted"><?= date('M j, Y', strtotime($payment['created_at'])) ?></small>
                                    </div>
                                    <span class="payment-status <?= 'status-' . $payment['status'] ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </div>
                                
                                <div class="payment-details">
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <h6><i class="fas fa-user me-2"></i>Student</h6>
                                            <p><?= htmlspecialchars($payment['student_name']) ?></p>
                                            <small class="text-muted"><?= htmlspecialchars($payment['student_email']) ?></small>
                                        </div>
                                        <div class="detail-item">
                                            <h6><i class="fas fa-home me-2"></i>Property</h6>
                                            <p><?= htmlspecialchars($payment['property_name']) ?></p>
                                            <small class="text-muted">
                                                <?= date('M j, Y', strtotime($payment['start_date'])) ?> - 
                                                <?= date('M j, Y', strtotime($payment['end_date'])) ?>
                                                (<?= $payment['duration_months'] ?> months)
                                            </small>
                                        </div>
                                        <div class="detail-item">
                                            <h6><i class="fas fa-credit-card me-2"></i>Payment Method</h6>
                                            <p class="payment-method">
                                                <?php if ($payment['payment_method']): ?>
                                                    <?php 
                                                    $method_icon = match(strtolower($payment['payment_method'])) {
                                                        'mobile_money' => 'mobile-alt',
                                                        'bank_transfer' => 'university',
                                                        'credit_card' => 'credit-card',
                                                        default => 'money-bill-wave'
                                                    };
                                                    $method_class = match(strtolower($payment['payment_method'])) {
                                                        'mobile_money' => 'mobile-money',
                                                        'bank_transfer' => 'bank-transfer',
                                                        'credit_card' => 'credit-card',
                                                        default => 'cash'
                                                    };
                                                    ?>
                                                    <i class="fas fa-<?= $method_icon ?> <?= $method_class ?>"></i>
                                                    <?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>
                                                    <?php if ($payment['payment_provider']): ?>
                                                        <small class="text-muted d-block">(<?= $payment['payment_provider'] ?>)</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <i class="fas fa-money-bill-wave cash"></i> Cash
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="detail-item">
                                            <h6><i class="fas fa-id-badge me-2"></i>Transaction ID</h6>
                                            <p><?= htmlspecialchars($payment['transaction_id']) ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="payment-actions">
                                    <?php if ($payment['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#confirmPaymentModal" data-payment-id="<?= $payment['id'] ?>">
                                            <i class="fas fa-check me-1"></i> Confirm
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectPaymentModal" data-payment-id="<?= $payment['id'] ?>">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </button>
                                    <?php endif; ?>
                                    <a href="history.php?id=<?= $payment['id'] ?>" class="btn btn-sm btn-primary ms-auto">
                                        <i class="fas fa-info-circle me-1"></i> Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Confirm Payment Modal -->
    <div class="modal fade" id="confirmPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Confirm Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to mark this payment as completed?</p>
                    <form id="confirmPaymentForm" method="post" action="payment_processing.php">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="payment_id" id="confirmPaymentId">
                        <div class="mb-3">
                            <label for="confirmNotes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="confirmNotes" name="notes" rows="3" placeholder="Add any notes about this payment..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="confirmPaymentForm" class="btn btn-success">Confirm Payment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Payment Modal -->
    <div class="modal fade" id="rejectPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this payment?</p>
                    <form id="rejectPaymentForm" method="post" action="payment_processing.php">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="payment_id" id="rejectPaymentId">
                        <div class="mb-3">
                            <label for="rejectReason" class="form-label">Reason</label>
                            <select class="form-select" id="rejectReason" name="reason" required>
                                <option value="">Select a reason</option>
                                <option value="Insufficient funds">Insufficient funds</option>
                                <option value="Payment method invalid">Payment method invalid</option>
                                <option value="Payment timeout">Payment timeout</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3" id="rejectReasonOtherContainer" style="display: none;">
                            <label for="rejectReasonOther" class="form-label">Specify Reason</label>
                            <textarea class="form-control" id="rejectReasonOther" name="reason_other" rows="3" placeholder="Please specify the reason..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="rejectPaymentForm" class="btn btn-danger">Reject Payment</button>
                </div>
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

    // Filter payments
    document.getElementById('applyFilters').addEventListener('click', function() {
        const searchTerm = document.getElementById('searchPayments').value.toLowerCase();
        const statusFilter = document.getElementById('filterStatus').value;
        const methodFilter = document.getElementById('filterMethod').value;
        const dateRange = document.getElementById('filterDateRange').value;
        
        document.querySelectorAll('.payment-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            const status = item.dataset.status;
            const method = item.dataset.method;
            const date = item.dataset.date;
            const amount = parseFloat(item.dataset.amount);
            
            // Date range filter
            let dateInRange = true;
            if (dateRange) {
                const dates = dateRange.split(' to ');
                if (dates.length === 2) {
                    const startDate = new Date(dates[0]);
                    const endDate = new Date(dates[1]);
                    const paymentDate = new Date(date);
                    
                    dateInRange = paymentDate >= startDate && paymentDate <= endDate;
                }
            }
            
            const matchesSearch = text.includes(searchTerm);
            const matchesStatus = statusFilter === '' || status.includes(statusFilter);
            const matchesMethod = methodFilter === '' || method.includes(methodFilter);
            
            if (matchesSearch && matchesStatus && matchesMethod && dateInRange) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // Reset filters
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchPayments').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterMethod').value = '';
        document.getElementById('filterDateRange').value = '';
        
        document.querySelectorAll('.payment-item').forEach(item => {
            item.style.display = 'block';
        });
    });

    // Initialize modals using Bootstrap's built-in functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Confirm Payment Modal
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmPaymentModal'));
        document.querySelectorAll('[data-bs-target="#confirmPaymentModal"]').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('confirmPaymentId').value = this.getAttribute('data-payment-id');
            });
        });

        // Reject Payment Modal
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectPaymentModal'));
        document.querySelectorAll('[data-bs-target="#rejectPaymentModal"]').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('rejectPaymentId').value = this.getAttribute('data-payment-id');
            });
        });

        // Reject reason toggle
        document.getElementById('rejectReason').addEventListener('change', function() {
            const otherContainer = document.getElementById('rejectReasonOtherContainer');
            otherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
            if (this.value !== 'Other') {
                document.getElementById('rejectReasonOther').value = '';
            }
        });

        // Form validation for reject payment
        document.getElementById('rejectPaymentForm').addEventListener('submit', function(e) {
            const reason = document.getElementById('rejectReason').value;
            if (reason === 'Other' && document.getElementById('rejectReasonOther').value.trim() === '') {
                e.preventDefault();
                alert('Please specify the reason for rejecting this payment');
            }
        });
    });
    </script>
</body>
</html>