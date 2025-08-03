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

// Get current page (financial, occupancy, or export)
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Initialize date filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$year = $_GET['year'] ?? date('Y');

// Get financial report data
if ($currentPage === 'financial') {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(p.created_at, '%Y-%m') AS month,
            COUNT(*) AS payment_count,
            SUM(p.amount) AS total_amount,
            SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) AS completed_amount,
            SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END) AS pending_amount,
            SUM(CASE WHEN p.status = 'failed' THEN p.amount ELSE 0 END) AS failed_amount
        FROM payments p
        WHERE p.created_at BETWEEN :start_date AND :end_date
        GROUP BY DATE_FORMAT(p.created_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
    $financialData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $totals = [
        'payment_count' => 0,
        'total_amount' => 0,
        'completed_amount' => 0,
        'pending_amount' => 0,
        'failed_amount' => 0
    ];
    
    foreach ($financialData as $row) {
        $totals['payment_count'] += $row['payment_count'];
        $totals['total_amount'] += $row['total_amount'];
        $totals['completed_amount'] += $row['completed_amount'];
        $totals['pending_amount'] += $row['pending_amount'];
        $totals['failed_amount'] += $row['failed_amount'];
    }
}

// Get occupancy report data
if ($currentPage === 'occupancy') {
    // Get occupancy by month for the selected year
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(b.start_date) AS month,
            COUNT(*) AS booking_count,
            SUM(DATEDIFF(b.end_date, b.start_date)) AS total_nights,
            AVG(DATEDIFF(b.end_date, b.start_date)) AS avg_stay_length,
            SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
            SUM(CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END) AS paid_bookings,
            SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings
        FROM bookings b
        WHERE YEAR(b.start_date) = :year
        GROUP BY MONTH(b.start_date)
        ORDER BY month
    ");
    $stmt->execute([':year' => $year]);
    $occupancyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get property occupancy rates
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.property_name,
            COUNT(b.id) AS booking_count,
            SUM(DATEDIFF(b.end_date, b.start_date)) AS occupied_nights,
            (SELECT DATEDIFF(MAX(end_date), MIN(start_date)) FROM bookings WHERE YEAR(start_date) = :year) AS total_days,
            ROUND((SUM(DATEDIFF(b.end_date, b.start_date)) / 
                 (SELECT DATEDIFF(MAX(end_date), MIN(start_date)) FROM bookings WHERE YEAR(start_date) = :year) * 100, 2) AS occupancy_rate
        FROM property p
        LEFT JOIN bookings b ON p.id = b.property_id AND YEAR(b.start_date) = :year
        GROUP BY p.id, p.property_name
        ORDER BY occupancy_rate DESC
    ");
    $stmt->execute([':year' => $year]);
    $propertyOccupancy = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle export request
if ($currentPage === 'export' && isset($_GET['export'])) {
    $exportType = $_GET['export_type'] ?? 'financial';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="hostel_export_' . $exportType . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($exportType === 'financial') {
        // Financial data export
        fputcsv($output, ['Month', 'Total Payments', 'Total Amount', 'Completed Amount', 'Pending Amount', 'Failed Amount']);
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(p.created_at, '%Y-%m') AS month,
                COUNT(*) AS payment_count,
                SUM(p.amount) AS total_amount,
                SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) AS completed_amount,
                SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END) AS pending_amount,
                SUM(CASE WHEN p.status = 'failed' THEN p.amount ELSE 0 END) AS failed_amount
            FROM payments p
            WHERE p.created_at BETWEEN :start_date AND :end_date
            GROUP BY DATE_FORMAT(p.created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['month'],
                $row['payment_count'],
                $row['total_amount'],
                $row['completed_amount'],
                $row['pending_amount'],
                $row['failed_amount']
            ]);
        }
    } elseif ($exportType === 'occupancy') {
        // Occupancy data export
        fputcsv($output, ['Month', 'Booking Count', 'Total Nights', 'Avg Stay Length', 'Confirmed', 'Paid', 'Cancelled']);
        
        $stmt = $pdo->prepare("
            SELECT 
                MONTHNAME(STR_TO_DATE(MONTH(b.start_date), '%m')) AS month,
                COUNT(*) AS booking_count,
                SUM(DATEDIFF(b.end_date, b.start_date)) AS total_nights,
                AVG(DATEDIFF(b.end_date, b.start_date)) AS avg_stay_length,
                SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
                SUM(CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END) AS paid_bookings,
                SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings
            FROM bookings b
            WHERE YEAR(b.start_date) = :year
            GROUP BY MONTH(b.start_date)
            ORDER BY MONTH(b.start_date)
        ");
        $stmt->execute([':year' => $year]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['month'],
                $row['booking_count'],
                $row['total_nights'],
                round($row['avg_stay_length'], 1),
                $row['confirmed_bookings'],
                $row['paid_bookings'],
                $row['cancelled_bookings']
            ]);
        }
    } elseif ($exportType === 'property') {
        // Property data export
        fputcsv($output, ['Property ID', 'Property Name', 'Booking Count', 'Occupied Nights', 'Occupancy Rate']);
        
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.property_name,
                COUNT(b.id) AS booking_count,
                SUM(DATEDIFF(b.end_date, b.start_date)) AS occupied_nights,
                ROUND((SUM(DATEDIFF(b.end_date, b.start_date)) / 
                     (SELECT DATEDIFF(MAX(end_date), MIN(start_date)) FROM bookings WHERE YEAR(start_date) = :year) * 100, 2) AS occupancy_rate
            FROM property p
            LEFT JOIN bookings b ON p.id = b.property_id AND YEAR(b.start_date) = :year
            GROUP BY p.id, p.property_name
            ORDER BY occupancy_rate DESC
        ");
        $stmt->execute([':year' => $year]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['property_name'],
                $row['booking_count'],
                $row['occupied_nights'],
                $row['occupancy_rate'] ? $row['occupancy_rate'] . '%' : '0%'
            ]);
        }
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
    <title><?php echo ucfirst($currentPage); ?> Reports - Landlords&Tenant Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            position: fixed;
            height: 100vh;
            transition: all var(--transition-speed) ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            background-color: rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
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
            background-color: rgba(0, 0, 0, 0.2);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Main content area */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

        /* Top navigation */
        .top-nav {
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title h1 {
            font-size: 24px;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }

        .breadcrumb {
            list-style: none;
            display: flex;
            font-size: 14px;
            color: #6c757d;
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin: 0 10px;
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
            margin-bottom: 20px;
        }

        .stat-card {
            background-color: white;
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
            font-size: 30px;
            margin-bottom: 15px;
        }

        .stat-card h3 {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            font-size: 28px;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }

        .stat-card p {
            font-size: 14px;
            color: #6c757d;
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

        /* Report cards */
        .report-card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .report-card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-card-header h2 {
            font-size: 18px;
            color: var(--secondary-color);
        }

        .report-card-body {
            padding: 20px;
        }

        /* Filter controls */
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .filter-group select, 
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background-color: white;
        }

        .filter-group button {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-group button:hover {
            background-color: #2980b9;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        table th {
            background-color: #f8f9fa;
            color: var(--secondary-color);
            font-weight: 600;
        }

        table tr:hover {
            background-color: #f8f9fa;
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-success {
            background-color: #d4edda;
            color: var(--success-color);
        }

        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background-color: #f8d7da;
            color: var(--accent-color);
        }

        .badge-info {
            background-color: #d1ecf1;
            color: var(--info-color);
        }

        /* Buttons */
        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
            border: none;
        }

        .btn-danger {
            background-color: var(--accent-color);
            color: white;
            border: none;
        }

        /* Mobile responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .stats-cards {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }

            .filter-controls {
                flex-direction: column;
                gap: 10px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        /* Toggle button for mobile */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary-color);
        }

        @media (max-width: 992px) {
            .menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Hostel Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../users/"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> Property Management</a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="financial.php" class="<?php echo $currentPage === 'financial' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="occupancy.php" class="<?php echo $currentPage === 'occupancy' ? 'active' : ''; ?>"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li><a href="export.php" class="<?php echo $currentPage === 'export' ? 'active' : ''; ?>"><i class="fas fa-file-export"></i> Data Exports</a></li>
                    <li><a href="../settings/"><i class="fas fa-cog"></i> Settings</a></li>
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
                <h1><?php echo ucfirst($currentPage); ?> Reports</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li><?php echo ucfirst($currentPage); ?> Reports</li>
                </ul>
            </div>

            <?php if ($currentPage === 'financial'): ?>
                <!-- Financial Reports Content -->
                <form method="get" action="financial.php" class="filter-controls">
                    <div class="filter-group">
                        <label for="start-date">Start Date</label>
                        <input type="date" id="start-date" name="start_date" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end-date">End Date</label>
                        <input type="date" id="end-date" name="end_date" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="financial.php" class="btn btn-outline" style="margin-top: 5px;">Reset</a>
                    </div>
                </form>

                <div class="stats-cards">
                    <div class="stat-card primary">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>Total Payments</h3>
                        <h2><?php echo number_format($totals['payment_count']); ?></h2>
                        <p>Between <?php echo date('M j, Y', strtotime($startDate)); ?> and <?php echo date('M j, Y', strtotime($endDate)); ?></p>
                    </div>
                    <div class="stat-card success">
                        <i class="fas fa-check-circle"></i>
                        <h3>Completed</h3>
                        <h2>GHS <?php echo number_format($totals['completed_amount'], 2); ?></h2>
                        <p>Successful payments</p>
                    </div>
                    <div class="stat-card warning">
                        <i class="fas fa-clock"></i>
                        <h3>Pending</h3>
                        <h2>GHS <?php echo number_format($totals['pending_amount'], 2); ?></h2>
                        <p>Awaiting confirmation</p>
                    </div>
                    <div class="stat-card danger">
                        <i class="fas fa-times-circle"></i>
                        <h3>Failed</h3>
                        <h2>GHS <?php echo number_format($totals['failed_amount'], 2); ?></h2>
                        <p>Unsuccessful payments</p>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h2>Monthly Financial Summary</h2>
                        <a href="export.php?export_type=financial&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </div>
                    <div class="report-card-body">
                        <div class="chart-container">
                            <canvas id="financialChart"></canvas>
                        </div>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Total Payments</th>
                                        <th>Total Amount</th>
                                        <th>Completed</th>
                                        <th>Pending</th>
                                        <th>Failed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($financialData as $row): ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($row['month'] . '-01')); ?></td>
                                        <td><?php echo $row['payment_count']; ?></td>
                                        <td>GHS <?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td>GHS <?php echo number_format($row['completed_amount'], 2); ?></td>
                                        <td>GHS <?php echo number_format($row['pending_amount'], 2); ?></td>
                                        <td>GHS <?php echo number_format($row['failed_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($financialData)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center;">No data available for the selected period</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <script>
                    // Financial Chart
                    const financialCtx = document.getElementById('financialChart').getContext('2d');
                    const financialChart = new Chart(financialCtx, {
                        type: 'bar',
                        data: {
                            labels: [<?php echo implode(',', array_map(function($row) { 
                                return "'" . date('M Y', strtotime($row['month'] . '-01')) . "'"; 
                            }, $financialData)); ?>],
                            datasets: [
                                {
                                    label: 'Completed',
                                    data: [<?php echo implode(',', array_column($financialData, 'completed_amount')); ?>],
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Pending',
                                    data: [<?php echo implode(',', array_column($financialData, 'pending_amount')); ?>],
                                    backgroundColor: 'rgba(255, 193, 7, 0.7)',
                                    borderColor: 'rgba(255, 193, 7, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Failed',
                                    data: [<?php echo implode(',', array_column($financialData, 'failed_amount')); ?>],
                                    backgroundColor: 'rgba(231, 76, 60, 0.7)',
                                    borderColor: 'rgba(231, 76, 60, 1)',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Amount (GHS)'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Month'
                                    }
                                }
                            }
                        }
                    });
                </script>

            <?php elseif ($currentPage === 'occupancy'): ?>
                <!-- Occupancy Reports Content -->
                <form method="get" action="occupancy.php" class="filter-controls">
                    <div class="filter-group">
                        <label for="year">Year</label>
                        <select id="year" name="year">
                            <?php 
                            $currentYear = date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 5; $y--): 
                            ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                        <a href="occupancy.php" class="btn btn-outline" style="margin-top: 5px;">Reset</a>
                    </div>
                </form>

                <div class="report-card">
                    <div class="report-card-header">
                        <h2>Monthly Occupancy Summary - <?php echo $year; ?></h2>
                        <a href="export.php?export_type=occupancy&year=<?php echo $year; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </div>
                    <div class="report-card-body">
                        <div class="chart-container">
                            <canvas id="occupancyChart"></canvas>
                        </div>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Booking Count</th>
                                        <th>Total Nights</th>
                                        <th>Avg Stay (Days)</th>
                                        <th>Confirmed</th>
                                        <th>Paid</th>
                                        <th>Cancelled</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($occupancyData as $row): ?>
                                    <tr>
                                        <td><?php echo date('F', mktime(0, 0, 0, $row['month'], 1)); ?></td>
                                        <td><?php echo $row['booking_count']; ?></td>
                                        <td><?php echo $row['total_nights']; ?></td>
                                        <td><?php echo round($row['avg_stay_length'], 1); ?></td>
                                        <td><?php echo $row['confirmed_bookings']; ?></td>
                                        <td><?php echo $row['paid_bookings']; ?></td>
                                        <td><?php echo $row['cancelled_bookings']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($occupancyData)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No data available for the selected year</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h2>Property Occupancy Rates - <?php echo $year; ?></h2>
                        <a href="export.php?export_type=property&year=<?php echo $year; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </div>
                    <div class="report-card-body">
                        <div class="chart-container">
                            <canvas id="propertyChart"></canvas>
                        </div>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Booking Count</th>
                                        <th>Occupied Nights</th>
                                        <th>Occupancy Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($propertyOccupancy as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['property_name']); ?></td>
                                        <td><?php echo $row['booking_count']; ?></td>
                                        <td><?php echo $row['occupied_nights']; ?></td>
                                        <td>
                                            <?php if ($row['occupancy_rate']): ?>
                                                <div class="progress" style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="flex: 1; height: 20px; background-color: #eee; border-radius: var(--border-radius); overflow: hidden;">
                                                        <div style="height: 100%; width: <?php echo $row['occupancy_rate']; ?>%; background-color: var(--primary-color);"></div>
                                                    </div>
                                                    <span><?php echo $row['occupancy_rate']; ?>%</span>
                                                </div>
                                            <?php else: ?>
                                                0%
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <script>
                    // Occupancy Chart
                    const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
                    const occupancyChart = new Chart(occupancyCtx, {
                        type: 'line',
                        data: {
                            labels: [<?php 
                                if (!empty($occupancyData)) {
                                    echo implode(',', array_map(function($row) {
                                        return "'" . date('F', mktime(0, 0, 0, $row['month'], 1)) . "'";
                                    }, $occupancyData));
                                }
                            ?>],
                            datasets: [
                                {
                                    label: 'Booking Count',
                                    data: [<?php echo implode(',', array_column($occupancyData, 'booking_count')); ?>],
                                    backgroundColor: 'rgba(52, 152, 219, 0.2)',
                                    borderColor: 'rgba(52, 152, 219, 1)',
                                    borderWidth: 2,
                                    tension: 0.3
                                },
                                {
                                    label: 'Total Nights',
                                    data: [<?php echo implode(',', array_column($occupancyData, 'total_nights')); ?>],
                                    backgroundColor: 'rgba(155, 89, 182, 0.2)',
                                    borderColor: 'rgba(155, 89, 182, 1)',
                                    borderWidth: 2,
                                    tension: 0.3
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Count/Nights'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Month'
                                    }
                                }
                            }
                        }
                    });

                    // Property Chart
                    const propertyCtx = document.getElementById('propertyChart').getContext('2d');
                    const propertyChart = new Chart(propertyCtx, {
                        type: 'bar',
                        data: {
                            labels: [<?php echo implode(',', array_map(function($row) {
                                return "'" . addslashes(substr($row['property_name'], 0, 15)) . (strlen($row['property_name']) > 15 ? '...' : '') . "'";
                            }, $propertyOccupancy)); ?>],
                            datasets: [{
                                label: 'Occupancy Rate %',
                                data: [<?php echo implode(',', array_column($propertyOccupancy, 'occupancy_rate')); ?>],
                                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                                borderColor: 'rgba(52, 152, 219, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'Occupancy Rate %'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Property'
                                    }
                                }
                            }
                        }
                    });
                </script>

            <?php elseif ($currentPage === 'export'): ?>
                <!-- Data Export Content -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h2>Data Export</h2>
                    </div>
                    <div class="report-card-body">
                        <div class="filter-controls">
                            <div class="filter-group">
                                <h3>Export Options</h3>
                                <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;">
                                    <div>
                                        <h4>Financial Data</h4>
                                        <p>Export payment records with filters</p>
                                        <a href="export.php?export=1&export_type=financial" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Export Financial Data
                                        </a>
                                    </div>
                                    
                                    <div>
                                        <h4>Occupancy Data</h4>
                                        <p>Export booking and occupancy records</p>
                                        <a href="export.php?export=1&export_type=occupancy" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Export Occupancy Data
                                        </a>
                                    </div>
                                    
                                    <div>
                                        <h4>Property Data</h4>
                                        <p>Export property occupancy rates</p>
                                        <a href="export.php?export=1&export_type=property" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Export Property Data
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 992 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // Resize handler
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>