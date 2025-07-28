<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get available tables for export
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// Handle export request
if (isset($_GET['export'])) {
    $export_type = $_GET['export_type'] ?? 'csv';
    $table_name = $_GET['table_name'] ?? '';
    $year = $_GET['year'] ?? date('Y');
    
    // Validate table name
    if (!in_array($table_name, $tables)) {
        $_SESSION['error'] = "Invalid table selected for export";
        header("Location: export.php");
        exit();
    }
    
    // Generate filename
    $filename = $table_name . '_export_' . date('Y-m-d') . '.' . $export_type;
    
    // Get data from the selected table
    $stmt = $pdo->query("SELECT * FROM $table_name");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($export_type === 'csv') {
        // CSV Export
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        
        // Header row
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    } elseif ($export_type === 'excel') {
        // Excel Export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        
        // Header row
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]), "\t");
        }
        
        // Data rows
        foreach ($data as $row) {
            fputcsv($output, $row, "\t");
        }
        
        fclose($output);
        exit();
    } elseif ($export_type === 'json') {
        // JSON Export
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Exports - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        @media (max-width: 768px) {
            .filter-controls {
                flex-direction: column;
                gap: 10px;
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

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
                    <li><a href="occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li><a href="export.php" class="active"><i class="fas fa-file-export"></i> Data Exports</a></li>
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
                <h1>Data Exports</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Data Exports</li>
                </ul>
            </div>

            <!-- Display messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Export Form -->
            <div class="report-card">
                <div class="report-card-header">
                    <h2>Export Data</h2>
                </div>
                <div class="report-card-body">
                    <form method="get" action="export.php" class="filter-controls">
                        <div class="filter-group">
                            <label for="table_name">Select Table</label>
                            <select id="table_name" name="table_name" required>
                                <option value="">-- Select Table --</option>
                                <?php foreach ($tables as $table): ?>
                                    <option value="<?php echo htmlspecialchars($table); ?>"><?php echo htmlspecialchars($table); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="export_type">Export Format</label>
                            <select id="export_type" name="export_type" required>
                                <option value="csv">CSV</option>
                                <option value="excel">Excel</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="export" value="1" class="btn btn-primary">
                                <i class="fas fa-download"></i> Export Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Export Options -->
            <div class="report-card">
                <div class="report-card-header">
                    <h2>Quick Exports</h2>
                </div>
                <div class="report-card-body">
                    <div class="filter-controls">
                        <div class="filter-group">
                            <a href="export.php?export=1&table_name=bookings&export_type=csv" class="btn btn-outline">
                                <i class="fas fa-file-csv"></i> Export Bookings (CSV)
                            </a>
                        </div>
                        <div class="filter-group">
                            <a href="export.php?export=1&table_name=payments&export_type=excel" class="btn btn-outline">
                                <i class="fas fa-file-excel"></i> Export Payments (Excel)
                            </a>
                        </div>
                        <div class="filter-group">
                            <a href="export.php?export=1&table_name=users&export_type=json" class="btn btn-outline">
                                <i class="fas fa-file-code"></i> Export Users (JSON)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>