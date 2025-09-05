<?php
session_start();
require_once __DIR__ .'../../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get owner data
$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Get report data
// 1. Property statistics
$properties_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_properties,
        SUM(CASE WHEN deleted = 0 THEN 1 ELSE 0 END) as active_properties,
        SUM(CASE WHEN deleted = 1 THEN 1 ELSE 0 END) as deleted_properties
    FROM property 
    WHERE owner_id = ?
");
$properties_stmt->execute([$owner_id]);
$property_stats = $properties_stmt->fetch();

// 2. Booking statistics
$bookings_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
        SUM(CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END) as paid_bookings
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    WHERE p.owner_id = ?
");
$bookings_stmt->execute([$owner_id]);
$booking_stats = $bookings_stmt->fetch();

// 3. Revenue statistics
$revenue_stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(py.amount), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN py.status = 'completed' THEN py.amount ELSE 0 END), 0) as confirmed_revenue,
        COALESCE(SUM(CASE WHEN py.status = 'pending' THEN py.amount ELSE 0 END), 0) as pending_revenue
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    WHERE p.owner_id = ?
");
$revenue_stmt->execute([$owner_id]);
$revenue_stats = $revenue_stmt->fetch();

// 4. Recent bookings
$recent_bookings_stmt = $pdo->prepare("
    SELECT 
        b.*, 
        p.property_name,
        p.price as property_price,
        u.username as student_name,
        u.email as student_email
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    WHERE p.owner_id = ?
    ORDER BY b.booking_date DESC
    LIMIT 5
");
$recent_bookings_stmt->execute([$owner_id]);
$recent_bookings = $recent_bookings_stmt->fetchAll();

// 5. Property performance
$property_performance_stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.property_name,
        COUNT(b.id) as total_bookings,
        SUM(CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END) as paid_bookings,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        COALESCE(SUM(py.amount), 0) as total_revenue
    FROM property p
    LEFT JOIN bookings b ON p.id = b.property_id
    LEFT JOIN payments py ON b.id = py.booking_id AND py.status = 'completed'
    WHERE p.owner_id = ? AND p.deleted = 0
    GROUP BY p.id
    ORDER BY total_revenue DESC
");
$property_performance_stmt->execute([$owner_id]);
$property_performance = $property_performance_stmt->fetchAll();

// 6. Monthly revenue data for chart
$monthly_revenue_stmt = $pdo->prepare("
    SELECT 
        YEAR(py.created_at) as year,
        MONTH(py.created_at) as month,
        COALESCE(SUM(py.amount), 0) as revenue
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    WHERE p.owner_id = ? AND py.status = 'completed'
    GROUP BY YEAR(py.created_at), MONTH(py.created_at)
    ORDER BY year DESC, month DESC
    LIMIT 6
");
$monthly_revenue_stmt->execute([$owner_id]);
$monthly_revenue_data = $monthly_revenue_stmt->fetchAll();

// 7. Room levy status
$levy_status_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_rooms,
        SUM(CASE WHEN levy_payment_status = 'approved' THEN 1 ELSE 0 END) as approved_rooms,
        SUM(CASE WHEN levy_payment_status = 'pending' THEN 1 ELSE 0 END) as pending_rooms,
        SUM(CASE WHEN levy_expiry_date IS NOT NULL AND levy_expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_rooms
    FROM property_rooms pr
    JOIN property p ON pr.property_id = p.id
    WHERE p.owner_id = ?
");
$levy_status_stmt->execute([$owner_id]);
$levy_stats = $levy_status_stmt->fetch();

// 8. Maintenance requests
$maintenance_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
        SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_requests,
        SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) as completed_requests
    FROM maintenance_requests mr
    JOIN property p ON mr.property_id = p.id
    WHERE p.owner_id = ?
");
$maintenance_stmt->execute([$owner_id]);
$maintenance_stats = $maintenance_stmt->fetch();

// 9. All bookings for bookings tab
$all_bookings_stmt = $pdo->prepare("
    SELECT 
        b.*, 
        p.property_name,
        p.price as property_price,
        u.username as student_name,
        u.email as student_email,
        py.amount as paid_amount,
        py.status as payment_status
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payments py ON b.id = py.booking_id
    WHERE p.owner_id = ?
    ORDER BY b.booking_date DESC
");
$all_bookings_stmt->execute([$owner_id]);
$all_bookings = $all_bookings_stmt->fetchAll();

// 10. Financial data for financial tab
$financial_data_stmt = $pdo->prepare("
    SELECT 
        p.property_name,
        b.id as booking_id,
        b.booking_date,
        b.start_date,
        b.end_date,
        py.amount,
        py.status as payment_status,
        py.created_at as payment_date
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN property p ON b.property_id = p.id
    WHERE p.owner_id = ?
    ORDER BY py.created_at DESC
");
$financial_data_stmt->execute([$owner_id]);
$financial_data = $financial_data_stmt->fetchAll();

// 11. All properties for properties tab
$all_properties_stmt = $pdo->prepare("
    SELECT 
        p.*,
        COUNT(b.id) as total_bookings,
        COALESCE(SUM(py.amount), 0) as total_revenue
    FROM property p
    LEFT JOIN bookings b ON p.id = b.property_id
    LEFT JOIN payments py ON b.id = py.booking_id AND py.status = 'completed'
    WHERE p.owner_id = ? AND p.deleted = 0
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$all_properties_stmt->execute([$owner_id]);
$all_properties = $all_properties_stmt->fetchAll();

// Prepare data for chart
$chart_labels = [];
$chart_data = [];
foreach ($monthly_revenue_data as $revenue) {
    $monthName = date('M', mktime(0, 0, 0, $revenue['month'], 1));
    $chart_labels[] = $monthName . ' ' . $revenue['year'];
    $chart_data[] = $revenue['revenue'];
}

// Reverse to show chronological order
$chart_labels = array_reverse($chart_labels);
$chart_data = array_reverse($chart_data);

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Reports - Owner Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7f9;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: var(--primary-color);
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .back-button {
            color: white;
            text-decoration: none;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            transition: all var(--transition-speed);
            backdrop-filter: blur(10px);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .page-title {
            font-size: 32px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .date-filter {
            display: flex;
            gap: 10px;
            align-items: center;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 15px;
            border-radius: var(--border-radius);
            backdrop-filter: blur(10px);
        }

        .date-filter select {
            background: white;
            border: none;
            padding: 8px 12px;
            border-radius: var(--border-radius);
            color: var(--secondary-color);
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 22px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all var(--transition-speed);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-color);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 15px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stat-icon.properties {
            background: var(--primary-color);
        }

        .stat-icon.bookings {
            background: var(--success-color);
        }

        .stat-icon.revenue {
            background: var(--info-color);
        }

        .stat-icon.pending {
            background: var(--warning-color);
        }

        .stat-icon.levy {
            background: var(--accent-color);
        }

        .stat-icon.maintenance {
            background: var(--secondary-color);
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 5px 0;
            color: var(--secondary-color);
        }

        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            margin-top: 10px;
            color: var(--success-color);
        }

        .trend-down {
            color: var(--accent-color);
        }

        .chart-container {
            height: 350px;
            margin-bottom: 30px;
            position: relative;
        }

        .data-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 992px) {
            .data-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .data-table th, .data-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--secondary-color);
            position: sticky;
            top: 0;
        }

        .data-table tr:hover {
            background-color: #f8f9fa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-confirmed {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }

        .status-pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
        }

        .status-cancelled {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--accent-color);
        }

        .status-paid {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }

        .status-completed {
            background-color: rgba(23, 162, 184, 0.2);
            color: var(--info-color);
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .export-options {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
            justify-content: center;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            transition: all var(--transition-speed);
            background: #f8f9fa;
        }

        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .page-title {
                text-align: left;
                font-size: 24px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-title {
                font-size: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .date-filter {
                width: 100%;
                justify-content: center;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-radius: var(--border-radius);
                margin-bottom: 5px;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }

            .export-options {
                flex-direction: column;
            }
        }

        /* Print styles */
        @media print {
            .header, .btn, .export-options, .back-button, .tabs, .date-filter {
                display: none !important;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }
            
            .stat-card {
                page-break-inside: avoid;
            }
            
            .tab-content {
                display: block !important;
            }
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .report-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e1e8ed;
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        /* PDF-specific styles */
        .pdf-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .pdf-title {
            color: var(--primary-color);
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .pdf-subtitle {
            color: var(--secondary-color);
            font-size: 16px;
        }
        
        .pdf-section {
            margin-bottom: 30px;
        }
        
        .pdf-section-title {
            color: var(--primary-color);
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="../dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Property Performance Reports</h1>
                <div class="date-filter">
                    <span>Period:</span>
                    <select id="reportPeriod">
                        <option value="7">Last 7 Days</option>
                        <option value="30" selected>Last 30 Days</option>
                        <option value="90">Last 90 Days</option>
                        <option value="180">Last 6 Months</option>
                        <option value="365">Last 12 Months</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="report-summary">
            <h3><i class="fas fa-info-circle"></i> Report Summary</h3>
            <div class="summary-item">
                <span>Report Generated:</span>
                <span><?php echo date('F j, Y, g:i a'); ?></span>
            </div>
            <div class="summary-item">
                <span>Properties Owned:</span>
                <span><?php echo $property_stats['active_properties']; ?> Active Properties</span>
            </div>
            <div class="summary-item">
                <span>Total Revenue:</span>
                <span>GHS <?php echo number_format($revenue_stats['total_revenue'], 2); ?></span>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="overview">Overview</div>
            <div class="tab" data-tab="bookings">Bookings</div>
            <div class="tab" data-tab="financial">Financial</div>
            <div class="tab" data-tab="properties">Properties</div>
        </div>

        <div class="tab-content active" id="overview">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon properties">
                        <i class="fas fa-home"></i>
                    </div>
                    <div class="stat-value"><?php echo $property_stats['active_properties']; ?></div>
                    <div class="stat-label">Active Properties</div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i> <?php echo round(($property_stats['active_properties'] / max($property_stats['total_properties'], 1)) * 100); ?>% of total
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bookings">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $booking_stats['total_bookings']; ?></div>
                    <div class="stat-label">Total Bookings</div>
                    <div class="stat-trend">
                        <i class="fas fa-check-circle"></i> <?php echo $booking_stats['paid_bookings']; ?> paid
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon revenue">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">GHS <?php echo number_format($revenue_stats['total_revenue'], 2); ?></div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i> GHS <?php echo number_format($revenue_stats['confirmed_revenue'], 2); ?> confirmed
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $booking_stats['pending_bookings']; ?></div>
                    <div class="stat-label">Pending Bookings</div>
                    <div class="stat-trend">
                        <i class="fas fa-info-circle"></i> <?php echo $booking_stats['cancelled_bookings']; ?> cancelled
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon levy">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-value"><?php echo $levy_stats['approved_rooms']; ?>/<?php echo $levy_stats['total_rooms']; ?></div>
                    <div class="stat-label">Rooms with Active Levy</div>
                    <div class="stat-trend <?php echo $levy_stats['expired_rooms'] > 0 ? 'trend-down' : ''; ?>">
                        <i class="fas fa-<?php echo $levy_stats['expired_rooms'] > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i> 
                        <?php echo $levy_stats['expired_rooms']; ?> expired
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon maintenance">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-value"><?php echo $maintenance_stats['total_requests']; ?></div>
                    <div class="stat-label">Maintenance Requests</div>
                    <div class="stat-trend">
                        <i class="fas fa-check-circle"></i> <?php echo $maintenance_stats['completed_requests']; ?> completed
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-chart-line"></i>
                    Revenue Trends
                </h2>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="data-grid">
                <div class="card">
                    <h2 class="card-title">
                        <i class="fas fa-list"></i>
                        Recent Bookings
                    </h2>
                    
                    <?php if (empty($recent_bookings)): ?>
                        <div class="no-data">
                            <div class="no-data-icon">
                                <i class="fas fa-calendar-times"></i>
                            </div>
                            <h3>No Recent Bookings</h3>
                            <p>You don't have any recent bookings to display.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Student</th>
                                        <th>Check-In</th>
                                        <th>Status</th>
                                        <th>Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_bookings as $booking): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($booking['property_name']); ?></td>
                                            <td><?php echo htmlspecialchars($booking['student_name']); ?></td>
                                            <td><?php echo isset($booking['check_in_date']) && !empty($booking['check_in_date']) ? date('M j, Y', strtotime($booking['check_in_date'])) : 'Not set'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                    <?php echo ucfirst($booking['status']); ?>
                                                </span>
                                            </td>
                                            <td>GHS <?php echo isset($booking['property_price']) ? number_format($booking['property_price'], 2) : '0.00'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        Property Performance
                    </h2>
                    
                    <?php if (empty($property_performance)): ?>
                        <div class="no-data">
                            <div class="no-data-icon">
                                <i class="fas fa-home"></i>
                            </div>
                            <h3>No Properties</h3>
                            <p>You don't have any properties to display.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Bookings</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($property_performance as $property): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($property['property_name']); ?></td>
                                            <td><?php echo $property['total_bookings']; ?></td>
                                            <td>GHS <?php echo number_format($property['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-content" id="bookings">
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-calendar-alt"></i>
                    All Bookings
                </h2>
                
                <?php if (empty($all_bookings)): ?>
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-calendar-times"></i>
                        </div>
                        <h3>No Bookings Found</h3>
                        <p>You don't have any bookings to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Student</th>
                                    <th>Check-In</th>
                                    <th>Check-Out</th>
                                    <th>Status</th>
                                    <th>Price</th>
                                    <th>Payment Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_bookings as $booking): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking['property_name']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['student_name']); ?></td>
                                        <td><?php echo isset($booking['start_date']) && !empty($booking['start_date']) ? date('M j, Y', strtotime($booking['start_date'])) : 'Not set'; ?></td>
                                        <td><?php echo isset($booking['end_date']) && !empty($booking['end_date']) ? date('M j, Y', strtotime($booking['end_date'])) : 'Not set'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                        </td>
                                        <td>GHS <?php echo isset($booking['property_price']) ? number_format($booking['property_price'], 2) : '0.00'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $booking['payment_status'] ?? 'pending'; ?>">
                                                <?php echo ucfirst($booking['payment_status'] ?? 'Pending'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-content" id="financial">
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-chart-pie"></i>
                    Financial Reports
                </h2>
                
                <?php if (empty($financial_data)): ?>
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3>No Financial Data</h3>
                        <p>You don't have any financial transactions to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Booking Date</th>
                                    <th>Check-In</th>
                                    <th>Check-Out</th>
                                    <th>Amount</th>
                                    <th>Payment Status</th>
                                    <th>Payment Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($financial_data as $financial): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($financial['property_name']); ?></td>
                                        <td><?php echo isset($financial['booking_date']) && !empty($financial['booking_date']) ? date('M j, Y', strtotime($financial['booking_date'])) : 'Not set'; ?></td>
                                        <td><?php echo isset($financial['start_date']) && !empty($financial['start_date']) ? date('M j, Y', strtotime($financial['start_date'])) : 'Not set'; ?></td>
                                        <td><?php echo isset($financial['end_date']) && !empty($financial['end_date']) ? date('M j, Y', strtotime($financial['end_date'])) : 'Not set'; ?></td>
                                        <td>GHS <?php echo isset($financial['amount']) ? number_format($financial['amount'], 2) : '0.00'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $financial['payment_status']; ?>">
                                                <?php echo ucfirst($financial['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo isset($financial['payment_date']) && !empty($financial['payment_date']) ? date('M j, Y', strtotime($financial['payment_date'])) : 'Not set'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-content" id="properties">
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-building"></i>
                    Property Management
                </h2>
                
                <?php if (empty($all_properties)): ?>
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <h3>No Properties</h3>
                        <p>You don't have any properties to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Property Name</th>
                                    <th>Location</th>
                                    <th>Price</th>
                                    <th>Bookings</th>
                                    <th>Revenue</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_properties as $property): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($property['property_name']); ?></td>
                                        <td><?php echo htmlspecialchars($property['location']); ?></td>
                                        <td>GHS <?php echo number_format($property['price'], 2); ?></td>
                                        <td><?php echo $property['total_bookings']; ?></td>
                                        <td>GHS <?php echo number_format($property['total_revenue'], 2); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $property['status']; ?>">
                                                <?php echo ucfirst($property['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-export"></i>
                Export Reports
            </h2>
            <p>Generate detailed reports for your records or accounting purposes.</p>
            <div class="export-options">
                <button class="btn btn-primary" onclick="exportPDF()">
                    <i class="fas fa-file-pdf"></i> Export as PDF
                </button>
                <button class="btn btn-success" onclick="exportExcel()">
                    <i class="fas fa-file-excel"></i> Export as Excel
                </button>
                <button class="btn btn-warning" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>
    </div>

    <script>
        // Revenue Chart
        const revenueChart = new Chart(
            document.getElementById('revenueChart'),
            {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Monthly Revenue (GHS)',
                        data: <?php echo json_encode($chart_data); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(52, 152, 219, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Monthly Revenue Trend',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return 'GHS ' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'GHS ' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            }
        );

        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });

        // Enhanced PDF export function
        function exportPDF() {
            // Create a new div for PDF content
            const pdfContent = document.createElement('div');
            pdfContent.id = 'pdf-content';
            pdfContent.style.padding = '20px';
            pdfContent.style.fontFamily = 'Arial, sans-serif';
            
            // Add PDF header
            const header = `
                <div class="pdf-header">
                    <h1 class="pdf-title">Property Performance Report</h1>
                    <p class="pdf-subtitle">Generated on ${new Date().toLocaleDateString()}</p>
                    <p class="pdf-subtitle">Property Owner: <?php echo htmlspecialchars($owner['username']); ?></p>
                </div>
            `;
            
            // Add summary section
            const summary = `
                <div class="pdf-section">
                    <h2 class="pdf-section-title">Summary</h2>
                    <p><strong>Properties Owned:</strong> <?php echo $property_stats['active_properties']; ?> Active Properties</p>
                    <p><strong>Total Bookings:</strong> <?php echo $booking_stats['total_bookings']; ?></p>
                    <p><strong>Total Revenue:</strong> GHS <?php echo number_format($revenue_stats['total_revenue'], 2); ?></p>
                </div>
            `;
            
            // Add property performance section
            let propertiesTable = '';
            if (<?php echo count($property_performance); ?> > 0) {
                propertiesTable = `
                    <div class="pdf-section">
                        <h2 class="pdf-section-title">Property Performance</h2>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                            <thead>
                                <tr style="background-color: #f8f9fa;">
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Property</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Bookings</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($property_performance as $property): ?>
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo htmlspecialchars($property['property_name']); ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo $property['total_bookings']; ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">GHS <?php echo number_format($property['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            // Add recent bookings section
            let bookingsTable = '';
            if (<?php echo count($recent_bookings); ?> > 0) {
                bookingsTable = `
                    <div class="pdf-section">
                        <h2 class="pdf-section-title">Recent Bookings</h2>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                            <thead>
                                <tr style="background-color: #f8f9fa;">
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Property</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Student</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Check-In</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Status</th>
                                    <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_bookings as $booking): ?>
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo htmlspecialchars($booking['property_name']); ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo htmlspecialchars($booking['student_name']); ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo isset($booking['check_in_date']) && !empty($booking['check_in_date']) ? date('M j, Y', strtotime($booking['check_in_date'])) : 'Not set'; ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php echo ucfirst($booking['status']); ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">GHS <?php echo isset($booking['property_price']) ? number_format($booking['property_price'], 2) : '0.00'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            // Combine all sections
            pdfContent.innerHTML = header + summary + propertiesTable + bookingsTable;
            
            // Append to body temporarily
            document.body.appendChild(pdfContent);
            
            // Generate PDF
            const opt = {
                margin: 10,
                filename: 'property-report-<?php echo date('Y-m-d'); ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(pdfContent).save().then(() => {
                // Remove the temporary element
                document.body.removeChild(pdfContent);
            });
        }

        function exportExcel() {
            // Simple Excel export simulation
            alert('Excel export functionality would be implemented here. This would generate a CSV file with all report data.');
        }

        // Period filter functionality
        document.getElementById('reportPeriod').addEventListener('change', function() {
            alert('Filtering data for ' + this.options[this.selectedIndex].text + '. This would reload the report with filtered data.');
            // In a real implementation, this would reload the page with the selected period parameter
        });
    </script>
</body>
</html>