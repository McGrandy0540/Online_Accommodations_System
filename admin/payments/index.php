<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user is admin
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Initialize filter variables
$statusFilter = $_GET['status'] ?? 'all';
$methodFilter = $_GET['method'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Base query
$query = "
    SELECT p.*, b.property_id, b.user_id, b.status as booking_status, 
           u.username as user_name, u.email as user_email,
           pr.property_name, pr.price as property_price
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users u ON b.user_id = u.id
    JOIN property pr ON b.property_id = pr.id
";

// Room Levy Payments Query
$levyQuery = "
    SELECT rlp.*, u.username as owner_name, u.email as owner_email,
           COUNT(pr.id) as room_count,
           GROUP_CONCAT(pr.room_number SEPARATOR ', ') as room_numbers,
           GROUP_CONCAT(p.property_name SEPARATOR ', ') as property_names,
           approver.username as approver_name
    FROM room_levy_payments rlp
    JOIN users u ON rlp.owner_id = u.id
    LEFT JOIN property_rooms pr ON rlp.id = pr.levy_payment_id
    LEFT JOIN property p ON pr.property_id = p.id
    LEFT JOIN users approver ON rlp.admin_approver_id = approver.id
";

// Build WHERE conditions for filters
$conditions = [];
$levyConditions = [];
$params = [];
$levyParams = [];

if ($statusFilter !== 'all') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $statusFilter;
    
    $levyConditions[] = "rlp.status = :levy_status";
    $levyParams[':levy_status'] = $statusFilter;
}

if ($methodFilter !== 'all') {
    $conditions[] = "p.payment_method = :method";
    $params[':method'] = $methodFilter;
    
    $levyConditions[] = "rlp.payment_method = :levy_method";
    $levyParams[':levy_method'] = $methodFilter;
}

if (!empty($dateFrom)) {
    $conditions[] = "p.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
    
    $levyConditions[] = "rlp.payment_date >= :levy_date_from";
    $levyParams[':levy_date_from'] = $dateFrom . ' 00:00:00';
}

if (!empty($dateTo)) {
    $conditions[] = "p.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
    
    $levyConditions[] = "rlp.payment_date <= :levy_date_to";
    $levyParams[':levy_date_to'] = $dateTo . ' 23:59:59';
}

// Add conditions to query
if (!empty($conditions)) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}

if (!empty($levyConditions)) {
    $levyQuery .= " WHERE " . implode(" AND ", $levyConditions);
}

$query .= " ORDER BY p.created_at DESC";
$levyQuery .= " GROUP BY rlp.id ORDER BY rlp.payment_date DESC";

// Prepare and execute the queries
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$levyStmt = $pdo->prepare($levyQuery);
foreach ($levyParams as $key => $value) {
    $levyStmt->bindValue($key, $value);
}
$levyStmt->execute();
$levyPayments = $levyStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle payment approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_payment'])) {
    $paymentId = $_POST['payment_id'];
    $paymentType = $_POST['payment_type'];
    $notes = $_POST['approval_notes'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($paymentType === 'levy') {
            // Call the stored procedure to approve the levy payment
            $stmt = $pdo->prepare("CALL approve_levy_payment(?, ?, ?)");
            $stmt->execute([$paymentId, $_SESSION['user_id'], $notes]);
        } else {
            // Approve booking payment
            $stmt = $pdo->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
            $stmt->execute([$paymentId]);
        }

        $pdo->commit();

        $_SESSION['success'] = "Payment #$paymentId has been successfully approved.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error approving payment: " . $e->getMessage();
    }

    header("Location: index.php");
    exit();
}

// Get payment statistics with the same filters
$statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue
    FROM payments p";

if (!empty($conditions)) {
    $statsQuery .= " WHERE " . implode(" AND ", str_replace('p.', '', $conditions));
}

$statsStmt = $pdo->prepare($statsQuery);
foreach ($params as $key => $value) {
    $statsStmt->bindValue(str_replace('p.', '', $key), $value);
}
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get levy payment statistics
$levyStatsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(amount) as revenue
    FROM room_levy_payments rlp";

if (!empty($levyConditions)) {
    $levyStatsQuery .= " WHERE " . implode(" AND ", str_replace('rlp.', '', $levyConditions));
}

$levyStatsStmt = $pdo->prepare($levyStatsQuery);
foreach ($levyParams as $key => $value) {
    $levyStatsStmt->bindValue(str_replace('rlp.', '', $key), $value);
}
$levyStatsStmt->execute();
$levyStats = $levyStatsStmt->fetch(PDO::FETCH_ASSOC);

$totalPayments = ($stats['total'] ?? 0) + ($levyStats['total'] ?? 0);
$completedPayments = ($stats['completed'] ?? 0) + ($levyStats['completed'] ?? 0);
$pendingPayments = ($stats['pending'] ?? 0) + ($levyStats['pending'] ?? 0);
$failedPayments = ($stats['failed'] ?? 0) + ($levyStats['failed'] ?? 0);
$totalRevenue = ($stats['revenue'] ?? 0) + ($levyStats['revenue'] ?? 0);

// Handle export request
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV header
    fputcsv($output, [
        'Payment ID', 'Transaction ID', 'User', 'Email', 'Property', 
        'Amount', 'Method', 'Status', 'Date'
    ]);
    
    // CSV data
    foreach ($payments as $payment) {
        fputcsv($output, [
            $payment['id'],
            $payment['transaction_id'],
            $payment['user_name'],
            $payment['user_email'],
            $payment['property_name'],
            $payment['amount'],
            ucfirst(str_replace('_', ' ', $payment['payment_method'])),
            ucfirst($payment['status']),
            date('M j, Y', strtotime($payment['created_at']))
        ]);
    }
    
    // Add levy payments to export
    foreach ($levyPayments as $payment) {
        fputcsv($output, [
            'LEVY-' . $payment['id'],
            $payment['payment_reference'],
            $payment['owner_name'],
            $payment['owner_email'],
            $payment['property_names'],
            $payment['amount'],
            ucfirst(str_replace('_', ' ', $payment['payment_method'])),
            ucfirst($payment['status']),
            date('M j, Y', strtotime($payment['payment_date']))
        ]);
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Management - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --sidebar-width: 250px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar styles */
        #sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            transition: var(--transition-speed);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        #sidebar.active {
            margin-left: calc(-1 * var(--sidebar-width));
        }

        .sidebar-header {
            padding: 20px;
            background-color: var(--dark-color);
            text-align: center;
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 1.2rem;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li a {
            display: block;
            padding: 12px 20px;
            color: #b8c7ce;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 20px;
        }

        /* Top navigation */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary-color);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Page header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .breadcrumb {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 10px;
            font-size: 0.9rem;
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin-left: 10px;
            color: #999;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        /* Stats cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-card h3 {
            font-size: 1rem;
            margin: 0;
            color: #666;
        }

        .stat-card h2 {
            font-size: 2rem;
            margin: 10px 0;
            font-weight: bold;
        }

        .stat-card p {
            margin: 0;
            font-size: 0.9rem;
            color: #999;
        }

        .stat-card.primary {
            border-top: 4px solid var(--primary-color);
        }

        .stat-card.success {
            border-top: 4px solid var(--success-color);
        }

        .stat-card.warning {
            border-top: 4px solid var(--warning-color);
        }

        .stat-card.danger {
            border-top: 4px solid var(--accent-color);
        }

        /* Filter controls */
        .filter-controls {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }

        .filter-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .filter-btn:hover {
            background-color: #2980b9;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .card-body {
            padding: 20px;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .table td {
            padding: 12px 15px;
            border-top: 1px solid #eee;
            vertical-align: middle;
        }

        .table tr:hover {
            background-color: #f9f9f9;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            background-color: var(--success-color);
            color: white;
        }

        .badge-warning {
            background-color: var(--warning-color);
            color: #212529;
        }

        .badge-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .badge-primary {
            background-color: var(--primary-color);
            color: white;
        }

        /* Action buttons */
        .action-btn {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.85rem;
            margin-right: 5px;
            transition: all 0.3s;
        }

        .action-btn i {
            margin-right: 5px;
        }

        .action-btn.view {
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .action-btn.view:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .action-btn.refund {
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }

        .action-btn.refund:hover {
            background-color: var(--warning-color);
            color: white;
        }

        .action-btn.approve {
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .action-btn.approve:hover {
            background-color: var(--success-color);
            color: white;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #495057;
            padding: 10px 20px;
            font-weight: 500;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background-color: transparent;
            border-bottom: 3px solid var(--primary-color);
        }

        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: var(--primary-color);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            #sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            
            #sidebar.active {
                margin-left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .stats-cards {
                grid-template-columns: 1fr 1fr;
            }
            
            .filter-controls {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }

        /* Additional styles for the levy payment section */
        .levy-payment-card {
            border-left: 4px solid var(--info-color);
        }
        
        .levy-payment-header {
            background-color: var(--info-color);
            color: white;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div id="sidebar">
            <div class="sidebar-header">
                <h3>Hostel Admin</h3>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../properties/index.php"><i class="fas fa-home"></i> Properties</a></li>
                    <li><a href="../bookings/index.php"><i class="fas fa-calendar-check"></i> Bookings</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="levy_payment.php" class="active"><i class="fas fa-money-bill-wave"></i>levy Payments</a></li>
                    <li><a href="../users/index.php"><i class="fas fa-users"></i> Users</a></li>
                    <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="../settings/index.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../../../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="user-profile">
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile" style="width: 30px; height: 30px; border-radius: 50%;">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Payments Management</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Payments</li>
                </ul>
            </div>

            <!-- Stats Cards (updated to include levy payments) -->
            <div class="stats-cards">
                <div class="stat-card primary">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Total Payments</h3>
                    <h2><?php echo number_format($totalPayments); ?></h2>
                    <p>All payment transactions</p>
                </div>
                <div class="stat-card success">
                    <i class="fas fa-check-circle"></i>
                    <h3>Completed</h3>
                    <h2><?php echo number_format($completedPayments); ?></h2>
                    <p>Successful payments</p>
                </div>
                <div class="stat-card warning">
                    <i class="fas fa-clock"></i>
                    <h3>Pending</h3>
                    <h2><?php echo number_format($pendingPayments); ?></h2>
                    <p>Awaiting confirmation</p>
                </div>
                <div class="stat-card danger">
                    <i class="fas fa-times-circle"></i>
                    <h3>Failed</h3>
                    <h2><?php echo number_format($failedPayments); ?></h2>
                    <p>Unsuccessful payments</p>
                </div>
            </div>

            <!-- Filter Controls -->
            <form method="get" action="" class="filter-controls">
                <div class="filter-group">
                    <label for="status-filter">Payment Status</label>
                    <select id="status-filter" name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="method-filter">Payment Method</label>
                    <select id="method-filter" name="method">
                        <option value="all" <?= $methodFilter === 'all' ? 'selected' : '' ?>>All Methods</option>
                        <option value="mobile_money" <?= $methodFilter === 'mobile_money' ? 'selected' : '' ?>>Mobile Money</option>
                        <option value="credit_card" <?= $methodFilter === 'credit_card' ? 'selected' : '' ?>>Credit Card</option>
                        <option value="bank_transfer" <?= $methodFilter === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="date-range">Date Range</label>
                    <input type="text" id="date-range" name="date_range" placeholder="Select date range" 
                           value="<?= !empty($dateFrom) ? date('m/d/Y', strtotime($dateFrom)) . ' - ' . date('m/d/Y', strtotime($dateTo)) : '' ?>">
                    <input type="hidden" name="date_from" id="date-from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="date_to" id="date-to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="filter-btn">Apply Filters</button>
                    <a href="index.php" class="action-btn view" style="margin-top: 5px; display: inline-block;">
                        <i class="fas fa-sync-alt"></i> Reset
                    </a>
                </div>
            </form>

            <!-- Payments Table with Tabs -->
            <div class="card">
                <div class="card-header">
                    <h2>All Payments</h2>
                    <div>
                        <a href="index.php?export=1<?= !empty($_GET) ? '&' . http_build_query($_GET) : '' ?>" class="action-btn view">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-3" id="paymentTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="booking-tab" data-bs-toggle="tab" data-bs-target="#booking-payments" type="button" role="tab">
                                Booking Payments
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="levy-tab" data-bs-toggle="tab" data-bs-target="#levy-payments" type="button" role="tab">
                                Room Levy Payments
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="paymentTabsContent">
                        <!-- Booking Payments Tab -->
                        <div class="tab-pane fade show active" id="booking-payments" role="tabpanel">
                            <?php if (empty($payments)): ?>
                                <p>No booking payments found matching your criteria.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Transaction</th>
                                                <th>User</th>
                                                <th>Property</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td>#<?php echo $payment['id']; ?></td>
                                                <td><?php echo htmlspecialchars($payment['transaction_id']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($payment['user_name']); ?><br>
                                                    <small><?php echo htmlspecialchars($payment['user_email']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($payment['property_name']); ?></td>
                                                <td>GHS <?php echo number_format($payment['amount'], 2); ?></td>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                                <td>
                                                    <?php 
                                                    $statusClass = '';
                                                    if ($payment['status'] === 'completed') {
                                                        $statusClass = 'badge-success';
                                                    } elseif ($payment['status'] === 'pending') {
                                                        $statusClass = 'badge-warning';
                                                    } else {
                                                        $statusClass = 'badge-danger';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo ucfirst($payment['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></td>
                                                <td>
                                                    <a href="view.php?id=<?php echo $payment['id']; ?>" class="action-btn view">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <?php if ($payment['status'] === 'completed' && $payment['booking_status'] !== 'cancelled'): ?>
                                                        <a href="index.php?id=<?php echo $payment['id']; ?>&action=refund" class="action-btn refund">
                                                            <i class="fas fa-undo"></i> Refund
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Levy Payments Tab -->
                        <div class="tab-pane fade" id="levy-payments" role="tabpanel">
                            <?php if (empty($levyPayments)): ?>
                                <p>No room levy payments found matching your criteria.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Reference</th>
                                                <th>Property Owner</th>
                                                <th>Rooms</th>
                                                <th>Properties</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th>Payment Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levyPayments as $payment): ?>
                                            <tr>
                                                <td>#<?= $payment['id'] ?></td>
                                                <td><?= htmlspecialchars($payment['payment_reference']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($payment['owner_name']) ?><br>
                                                    <small><?= htmlspecialchars($payment['owner_email']) ?></small>
                                                </td>
                                                <td><?= $payment['room_count'] ?> (<?= $payment['room_numbers'] ?>)</td>
                                                <td><?= $payment['property_names'] ?></td>
                                                <td>GHS <?= number_format($payment['amount'], 2) ?></td>
                                                <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></td>
                                                <td>
                                                    <?php 
                                                    $statusClass = '';
                                                    if ($payment['status'] === 'completed') {
                                                        $statusClass = 'badge-success';
                                                    } elseif ($payment['status'] === 'pending') {
                                                        $statusClass = 'badge-warning';
                                                    } else {
                                                        $statusClass = 'badge-danger';
                                                    }
                                                    ?>
                                                    <span class="badge <?= $statusClass ?>">
                                                        <?= ucfirst($payment['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                <td>
                                                    <a href="levy_view.php?id=<?= $payment['id'] ?>" class="action-btn view">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <?php if (is_null($payment['admin_approver_id'])): ?>
                                                        <?php if ($payment['status'] === 'completed' || $payment['status'] === 'pending'): ?>
                                                            <button class="action-btn approve" data-bs-toggle="modal" data-bs-target="#approveModal"
                                                                    data-payment-id="<?= $payment['id'] ?>"
                                                                    data-payment-type="levy">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">
                                                            Approved by <?= htmlspecialchars($payment['approver_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Summary -->
            <div class="card">
                <div class="card-header">
                    <h2>Revenue Summary</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-around; text-align: center;">
                        <div>
                            <h3>Total Revenue</h3>
                            <p style="font-size: 24px; font-weight: bold; color: var(--success-color);">
                                GHS <?php echo number_format($totalRevenue, 2); ?>
                            </p>
                        </div>
                        <div>
                            <h3>Last 30 Days</h3>
                            <p style="font-size: 24px; font-weight: bold; color: var(--primary-color);">
                                GHS <?php echo number_format($totalRevenue * 0.3, 2); ?>
                            </p>
                        </div>
                        <div>
                            <h3>Avg. Payment</h3>
                            <p style="font-size: 24px; font-weight: bold; color: var(--secondary-color);">
                                GHS <?php echo $totalPayments > 0 ? number_format($totalRevenue / $totalPayments, 2) : '0.00'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Payment Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="">
                    <input type="hidden" name="approve_payment" value="1">
                    <input type="hidden" name="payment_id" id="modalPaymentId" value="">
                    <input type="hidden" name="payment_type" id="modalPaymentType" value="">

                    <div class="modal-header">
                        <h5 class="modal-title" id="approveModalLabel">Approve Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to approve this payment? This will activate the rooms for student listings.</p>
                        
                        <div class="mb-3">
                            <label for="approvalNotes" class="form-label">Approval Notes</label>
                            <textarea class="form-control" id="approvalNotes" name="approval_notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Confirm Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date range picker
        flatpickr("#date-range", {
            mode: "range",
            dateFormat: "m/d/Y",
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2) {
                    document.getElementById('date-from').value = 
                        flatpickr.formatDate(selectedDates[0], "Y-m-d");
                    document.getElementById('date-to').value = 
                        flatpickr.formatDate(selectedDates[1], "Y-m-d");
                }
            }
        });

        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Initialize the approval modal
        var approveModal = document.getElementById('approveModal');
        if (approveModal) {
            approveModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var paymentId = button.getAttribute('data-payment-id');
                var paymentType = button.getAttribute('data-payment-type');
                var modalPaymentId = document.getElementById('modalPaymentId');
                var modalPaymentType = document.getElementById('modalPaymentType');
                var modalTitle = document.getElementById('approveModalLabel');

                modalPaymentId.value = paymentId;
                modalPaymentType.value = paymentType;

                if (paymentType === 'levy') {
                    modalTitle.textContent = 'Approve Room Levy Payment';
                } else {
                    modalTitle.textContent = 'Approve Booking Payment';
                }
            });
        }
        
        // Initialize Bootstrap tabs
        var paymentTabs = new bootstrap.Tab(document.getElementById('booking-tab'));
        paymentTabs.show();
    </script>
</body>
</html>