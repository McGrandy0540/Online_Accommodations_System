<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get approved bookings
$stmt = $pdo->prepare("
    SELECT b.*, p.property_name, u.username, u.email 
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    WHERE b.status = 'approved'
    ORDER BY b.updated_at DESC
");
$stmt->execute();
$approvedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approved Bookings - Hostel Admin</title>
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

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 18px;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 20px;
        }

        .table-responsive {
            overflow-x: auto;
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

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            border: none;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .text-center {
            text-align: center;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 50px;
            margin-bottom: 20px;
            color: #ddd;
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

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .user-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }
    
        
        .badge-approved {
            background-color: var(--success-color);
            color: white;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar Navigation (Same as index.php) -->
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
                    <li><a href="index.php"><i class="fas fa-clock"></i> Pending Approvals</a></li>
                    <li><a href="approved.php" class="active"><i class="fas fa-check-circle"></i> Approved Bookings</a></li>
                    <li><a href="rejected.php"><i class="fas fa-times-circle"></i> Rejected Bookings</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="../reports/financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="../reports/occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
                    <li><a href="../settings/"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Top Navigation (Same as index.php) -->
            <div class="top-nav">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="user-profile">
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile" class="user-avatar">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Approved Bookings</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Approved Bookings</li>
                </ul>
            </div>

            <!-- Approved Bookings Table -->
            <div class="card">
                <div class="card-header">
                    <h2>Approved Bookings</h2>
                    <span class="badge badge-approved"><?php echo count($approvedBookings); ?> Approved</span>
                </div>
                <div class="card-body">
                    <?php if (empty($approvedBookings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No Approved Bookings</h3>
                            <p>No bookings have been approved yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Property</th>
                                        <th>Guest</th>
                                        <th>Dates</th>
                                        <th>Nights</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Approved On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approvedBookings as $booking): ?>
                                    <tr>
                                        <td>#<?php echo $booking['id']; ?></td>
                                        <td><?php echo htmlspecialchars($booking['property_name']); ?></td>
                                        <td>
                                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($booking['username']); ?>&background=random" alt="User" class="user-avatar">
                                            <?php echo htmlspecialchars($booking['username']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($booking['start_date'])); ?> - 
                                            <?php echo date('M j, Y', strtotime($booking['end_date'])); ?>
                                        </td>
                                        <td><?php echo round((strtotime($booking['end_date']) - strtotime($booking['start_date'])) / (60 * 60 * 24)); ?></td>
                                        <td>$<?php echo number_format($booking['total_amount'], 2); ?></td>
                                        <td><span class="badge badge-approved">Approved</span></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($booking['updated_at'])); ?></td>
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

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>