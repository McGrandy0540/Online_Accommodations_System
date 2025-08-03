<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Default to current year if not set
$year = $_GET['year'] ?? date('Y');

// Get monthly occupancy data
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
        COUNT(DISTINCT pr.id) * :days_in_year AS total_possible_nights,
        ROUND(
            (SUM(DATEDIFF(b.end_date, b.start_date)) /
            (COUNT(DISTINCT pr.id) * :days_in_year)) * 100,
            2
        ) AS occupancy_rate
    FROM property p
    LEFT JOIN property_rooms pr ON p.id = pr.property_id
    LEFT JOIN bookings b ON p.id = b.property_id AND YEAR(b.start_date) = :year
    GROUP BY p.id, p.property_name
    ORDER BY occupancy_rate DESC
");
// Calculate days in year (accounting for leap years)
$isLeapYear = (($year % 4 == 0) && ($year % 100 != 0)) || ($year % 400 == 0);
$days_in_year = $isLeapYear ? 366 : 365;

$stmt->execute([
    ':year' => $year,
    ':days_in_year' => $days_in_year
]);
$propertyOccupancy = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle division by zero cases
foreach ($propertyOccupancy as &$row) {
    if ($row['total_possible_nights'] == 0) {
        $row['occupancy_rate'] = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Occupancy Reports - LandlordsTenant Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        .progress {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .progress-bar {
            flex: 1;
            height: 20px;
            background-color: #eee;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: var(--primary-color);
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
        }

        @media (max-width: 768px) {
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
                    <li><a href="../bookings/"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="occupancy.php" class="active"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li><a href="export.php"><i class="fas fa-file-export"></i> Data Exports</a></li>
                    <li><a href="../settings/"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                <h1>Occupancy Reports</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Occupancy Reports</li>
                </ul>
            </div>

            <!-- Filter Controls -->
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

            <!-- Monthly Occupancy Report -->
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

            <!-- Property Occupancy Report -->
            <div class="report-card">
                <div class="report-card-header">
                    <h2>Property Occupancy Rates - <?php echo $year; ?></