<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
require_once __DIR__ . 'audit_log.php';

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verify payment belongs to owner
$stmt = $pdo->prepare("
    SELECT p.*, b.property_id, pr.property_name, u.username as student_name, 
           pm.method_type as payment_method, pm.account_details
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN property pr ON b.property_id = pr.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    WHERE p.id = ? AND pr.owner_id = ?
");
$stmt->execute([$payment_id, $owner_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    $_SESSION['error'] = "Payment not found or access denied";
    header("Location: index.php");
    exit();
}

// Get payment history logs
$stmt = $pdo->prepare("
    SELECT * FROM payment_logs 
    WHERE payment_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$payment_id]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get related activities
$stmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE entity_type = 'payment' AND entity_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$payment_id]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payment History | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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

        /* Payment Status Badges */
        .payment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
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

        /* Payment Method Icons */
        .payment-method {
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .mobile-money { color: #5C2D91; }
        .bank-transfer { color: #0066CC; }
        .credit-card { color: #FF9900; }
        .cash { color: #666666; }

        /* Timeline styles */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
            margin: 2rem 0;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
            padding-left: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid white;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: var(--secondary-color);
            margin-bottom: 0.25rem;
        }
        
        .timeline-content {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        /* Payment method details */
        .method-details {
            background: rgba(52, 152, 219, 0.1);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        /* Detail Items */
        .detail-item {
            margin-bottom: 1rem;
        }

        .detail-item h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
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
            .main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
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
            <a href="../../property_owner/dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../../property_owner/properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="../../property_owner/bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="index.php" class="active">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="../../property_owner/chat/">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../../property_owner/maintenance/">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="../../property_owner/virtual-tours/">
                <i class="fas fa-vr-cardboard"></i>
                <span>Virtual Tours</span>
            </a>
            <a href="../../property_owner/settings.php">
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
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-history me-2"></i>Payment History</h5>
        
        <div class="top-nav-right">
            <div class="dropdown">
                <div class="user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <i class="fas fa-chevron-down ms-2 d-none d-md-inline"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../../property_owner/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="../../property_owner/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="mb-0"><i class="fas fa-history me-2"></i>Payment History</h2>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Payments
                        </a>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Payment Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-receipt me-2"></i>Transaction ID</h6>
                                        <p><?= htmlspecialchars($payment['transaction_id']) ?></p>
                                    </div>
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-dollar-sign me-2"></i>Amount</h6>
                                        <p>$<?= number_format($payment['amount'], 2) ?></p>
                                    </div>
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-calendar-alt me-2"></i>Date</h6>
                                        <p><?= date('M j, Y H:i', strtotime($payment['created_at'])) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-user me-2"></i>Student</h6>
                                        <p><?= htmlspecialchars($payment['student_name']) ?></p>
                                    </div>
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-home me-2"></i>Property</h6>
                                        <p><?= htmlspecialchars($payment['property_name']) ?></p>
                                    </div>
                                    <div class="detail-item mb-3">
                                        <h6><i class="fas fa-info-circle me-2"></i>Status</h6>
                                        <span class="payment-status <?= 'status-' . $payment['status'] ?>">
                                            <?= ucfirst($payment['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($payment['payment_method']): ?>
                            <div class="method-details">
                                <h5><i class="fas fa-credit-card me-2"></i>Payment Method Details</h5>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="detail-item mb-3">
                                            <h6>Method</h6>
                                            <p class="payment-method">
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
                                                <i class="fas fa-<?= $method_icon ?> <?= $method_class ?> me-2"></i>
                                                <?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="detail-item mb-3">
                                            <h6>Account Details</h6>
                                            <p><?= htmlspecialchars($payment['account_details']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0"><i class="fas fa-list-alt me-2"></i>Payment Timeline</h4>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
                                    </div>
                                    <div class="timeline-content">
                                        <h6><?= htmlspecialchars($log['action']) ?></h6>
                                        <p class="mb-1"><?= htmlspecialchars($log['description']) ?></p>
                                        <?php if ($log['details']): ?>
                                        <p class="text-muted small mb-0">Details: <?= htmlspecialchars($log['details']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php foreach ($activities as $activity): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?= date('M j, Y H:i', strtotime($activity['created_at'])) ?>
                                    </div>
                                    <div class="timeline-content">
                                        <h6>System Activity</h6>
                                        <p class="mb-1"><?= htmlspecialchars($activity['action']) ?></p>
                                        <?php if ($activity['ip_address']): ?>
                                        <p class="text-muted small mb-0">IP: <?= htmlspecialchars($activity['ip_address']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?= date('M j, Y H:i', strtotime($payment['created_at'])) ?>
                                    </div>
                                    <div class="timeline-content">
                                        <h6>Payment Created</h6>
                                        <p>Initial payment record created</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    </script>
</body>
</html>