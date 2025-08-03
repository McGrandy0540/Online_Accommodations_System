<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Default to current month if no dates set
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

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
    GROUP BY month
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
    foreach ($totals as $key => $value) {
        $totals[$key] += $row[$key];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - Hostel Admin</title>
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

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

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

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

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
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="financial.php" class="active"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li><a href="export.php"><i class="fas fa-file-export"></i> Data Exports</a></li>
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
                <h1>Financial Reports</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Financial Reports</li>
                </ul>
            </div>

            <!-- Filter Controls -->
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

            <!-- Stats Cards -->
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

            <!-- Financial Report -->
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

        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Initialize date pickers
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#start-date", {
                dateFormat: "Y-m-d",
                defaultDate: "<?php echo $startDate; ?>"
            });
            
            flatpickr("#end-date", {
                dateFormat: "Y-m-d",
                defaultDate: "<?php echo $endDate; ?>"
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>