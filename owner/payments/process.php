<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
require_once 'payment_processing.php';
require_once  'audit_log.php';

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = "Invalid CSRF token";
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? '';
$payment_id = (int)($_POST['payment_id'] ?? 0);

// Verify payment belongs to owner
$stmt = $pdo->prepare("
    SELECT p.*, pr.owner_id, u.username as student_name, pr.property_name
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN property pr ON b.property_id = pr.id
    JOIN users u ON b.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment || $payment['owner_id'] != $owner_id) {
    $_SESSION['error'] = "Payment not found or access denied";
    header("Location: index.php");
    exit();
}

$processing = false;
$success = false;
$error = null;

try {
    $processing = true;
    $pdo->beginTransaction();
    
    switch ($action) {
        case 'confirm':
            $notes = $_POST['notes'] ?? '';
            
            // Update payment status
            $stmt = $pdo->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
            $stmt->execute([$payment_id]);
            
            // Log payment confirmation
            logPaymentAction($payment_id, 'confirmed', 'Payment marked as completed', $notes);
            
            // Log activity
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$owner_id, 'confirm_payment', 'payment', $payment_id, $_SERVER['REMOTE_ADDR']]);
            
            // Send notification to student
            sendPaymentNotification(
                $payment['user_id'],
                "Payment Confirmed",
                "Your payment of $" . number_format($payment['amount'], 2) . " for " . $payment['property_name'] . " has been confirmed",
                "/student/payments/" . $payment_id
            );
            
            $success = true;
            $_SESSION['success'] = "Payment confirmed successfully";
            break;
            
        case 'reject':
            $reason = $_POST['reason'] ?? 'No reason provided';
            $other_reason = $_POST['reason_other'] ?? '';
            
            if ($reason === 'Other' && !empty($other_reason)) {
                $reason = $other_reason;
            }
            
            // Update payment status
            $stmt = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
            $stmt->execute([$payment_id]);
            
            // Log payment rejection
            logPaymentAction($payment_id, 'rejected', 'Payment rejected by owner', $reason);
            
            // Log activity
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$owner_id, 'reject_payment', 'payment', $payment_id, $_SERVER['REMOTE_ADDR']]);
            
            // Send notification to student
            sendPaymentNotification(
                $payment['user_id'],
                "Payment Rejected",
                "Your payment of $" . number_format($payment['amount'], 2) . " for " . $payment['property_name'] . " was rejected. Reason: " . $reason,
                "/student/payments/" . $payment_id
            );
            
            $success = true;
            $_SESSION['success'] = "Payment rejected successfully";
            break;
            
        default:
            throw new Exception("Invalid action specified");
    }
    
    $pdo->commit();
    $processing = false;
} catch (Exception $e) {
    $pdo->rollBack();
    $processing = false;
    $error = $e->getMessage();
    $_SESSION['error'] = "Error processing payment: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Processing Payment | UniHomes</title>
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

        /* Processing Container */
        .processing-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - var(--header-height) - 4rem);
            text-align: center;
            padding: 2rem;
        }

        .processing-spinner {
            width: 4rem;
            height: 4rem;
            border: 5px solid rgba(52, 152, 219, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s linear infinite;
            margin-bottom: 2rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .processing-message {
            font-size: 1.5rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .processing-details {
            color: var(--dark-color);
            opacity: 0.8;
            max-width: 500px;
            margin: 0 auto 2rem;
        }

        .payment-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: left;
        }

        .payment-card h4 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.5rem;
        }

        .payment-detail {
            display: flex;
            margin-bottom: 0.75rem;
        }

        .payment-detail-label {
            flex: 0 0 150px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .payment-detail-value {
            flex: 1;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .status-completed {
            background-color: var(--success-color);
            color: white;
        }

        .status-failed {
            background-color: var(--accent-color);
            color: white;
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
            
            .processing-container {
                padding: 1rem;
            }
            
            .payment-detail {
                flex-direction: column;
            }
            
            .payment-detail-label {
                margin-bottom: 0.25rem;
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
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>UniHomes</span></h4>
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
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-cog me-2"></i>Processing Payment</h5>
        
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
        <div class="processing-container">
            <?php if ($processing): ?>
                <div class="processing-spinner"></div>
                <h3 class="processing-message">Processing Payment...</h3>
                <p class="processing-details">
                    Please wait while we process your payment request. This may take a few moments.
                    Do not refresh or close this page.
                </p>
                
                <div class="payment-card">
                    <h4><i class="fas fa-info-circle me-2"></i>Payment Details</h4>
                    <div class="payment-detail">
                        <div class="payment-detail-label">Transaction ID:</div>
                        <div class="payment-detail-value"><?= htmlspecialchars($payment['transaction_id']) ?></div>
                    </div>
                    <div class="payment-detail">
                        <div class="payment-detail-label">Amount:</div>
                        <div class="payment-detail-value">$<?= number_format($payment['amount'], 2) ?></div>
                    </div>
                    <div class="payment-detail">
                        <div class="payment-detail-label">Student:</div>
                        <div class="payment-detail-value"><?= htmlspecialchars($payment['student_name']) ?></div>
                    </div>
                    <div class="payment-detail">
                        <div class="payment-detail-label">Property:</div>
                        <div class="payment-detail-value"><?= htmlspecialchars($payment['property_name']) ?></div>
                    </div>
                    <div class="payment-detail">
                        <div class="payment-detail-label">Action:</div>
                        <div class="payment-detail-value">
                            <span class="status-badge <?= $action === 'confirm' ? 'status-completed' : 'status-failed' ?>">
                                <?= ucfirst($action) ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php elseif ($success): ?>
                <div class="text-success mb-4" style="font-size: 4rem;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3 class="processing-message text-success">Payment Processed Successfully!</h3>
                <p class="processing-details">
                    The payment has been successfully <?= $action === 'confirm' ? 'confirmed' : 'rejected' ?>.
                    The student has been notified about this action.
                </p>
                <a href="index.php" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Payments
                </a>
            <?php else: ?>
                <div class="text-danger mb-4" style="font-size: 4rem;">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3 class="processing-message text-danger">Payment Processing Failed</h3>
                <p class="processing-details">
                    <?= htmlspecialchars($error) ?>
                </p>
                <div class="d-flex gap-2 mt-3">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Payments
                    </a>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-sync-alt me-2"></i>Try Again
                    </a>
                </div>
            <?php endif; ?>
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

    // Auto-redirect if still processing
    <?php if ($processing): ?>
    setTimeout(function() {
        window.location.reload();
    }, 2000);
    <?php endif; ?>
    </script>
</body>
</html>