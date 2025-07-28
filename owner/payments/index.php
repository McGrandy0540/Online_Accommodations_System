<?php
session_start();
require_once __DIR__ . '../../../config/database.php';


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

// Fetch payment history with gateway details
$stmt = $pdo->prepare("
    SELECT p.*, b.property_id, b.user_id, pr.property_name, 
           u.username as student_name, pm.method_type as payment_method
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN property pr ON b.property_id = pr.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    WHERE pr.owner_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$owner_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$total_received = array_sum(array_column($payments, 'amount'));
$pending_payments = array_filter($payments, fn($p) => $p['status'] === 'pending');
$failed_payments = array_filter($payments, fn($p) => $p['status'] === 'failed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payment Management | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
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
            justify-content: space-between;
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

            .stats-card h3 {
                font-size: 1.5rem;
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
            <a href="../dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="../bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="index.php" class="active">
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
            <a href="../virtual-tours/index.php">
                <i class="fas fa-vr-cardboard"></i>
                <span>Virtual Tours</span>
            </a>
            <a href="../settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
            <a href="room_levy_payment.php">
                <i class="fa-solid fa-money-bill-1"></i>
                <span>Room Levy Payment</span>
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
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-money-bill-wave me-2"></i>Payment Management</h5>
        
        <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($_SESSION['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
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
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
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
                        <h3><?= count($pending_payments) ?></h3>
                        <p>Pending Payments</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-danger">
                        <h3><?= count($failed_payments) ?></h3>
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
                                        </div>
                                        <div class="detail-item">
                                            <h6><i class="fas fa-home me-2"></i>Property</h6>
                                            <p><?= htmlspecialchars($payment['property_name']) ?></p>
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
                    <form id="confirmPaymentForm" method="post" action="process.php">
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
                    <form id="rejectPaymentForm" method="post" action="process.php">
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

    // Initialize date range picker with proper configuration
    document.addEventListener('DOMContentLoaded', function() {
        flatpickr("#filterDateRange", {
            mode: "range",
            dateFormat: "Y-m-d",
            allowInput: true
        });

        // Modal event handlers
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmPaymentModal'));
        document.querySelectorAll('[data-bs-target="#confirmPaymentModal"]').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('confirmPaymentId').value = this.getAttribute('data-payment-id');
            });
        });

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
    </script>
</body>
</html>