<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Base query for bookings
$query = "
    SELECT 
        b.*, 
        p.property_name, 
        p.location AS property_location,
        pr.room_number,
        pr.gender AS room_gender,
        u_student.username AS student_name, 
        u_student.email AS student_email,
        u_student.phone_number AS student_phone,
        u_owner.username AS owner_name,
        u_owner.email AS owner_email,
        u_owner.phone_number AS owner_phone,
        py.amount AS payment_amount,
        py.status AS payment_status
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u_student ON b.user_id = u_student.id
    JOIN users u_owner ON p.owner_id = u_owner.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    LEFT JOIN payments py ON b.id = py.booking_id
";

// Add status filter if not 'all'
if ($status_filter !== 'all') {
    $query .= " WHERE b.status = :status";
}

$query .= " ORDER BY b.booking_date DESC";

$stmt = $pdo->prepare($query);

if ($status_filter !== 'all') {
    $stmt->bindValue(':status', $status_filter);
}

$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management - Hostel Admin</title>
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
            flex-wrap: wrap;
            gap: 15px;
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
            min-width: 1000px;
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
            position: sticky;
            top: 0;
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
        
        .badge-confirmed {
            background-color: var(--info-color);
            color: white;
        }
        
        .badge-paid {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-cancelled {
            background-color: var(--accent-color);
            color: white;
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
        
        .btn-info {
            background-color: var(--info-color);
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
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-section {
                width: 100%;
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
            flex-wrap: wrap;
        }

        .user-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .filter-section {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-label {
            font-weight: 500;
        }
        
        .filter-select {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }
        
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .bg-pending { background-color: #fff3cd; color: #856404; }
        .bg-confirmed { background-color: #cce5ff; color: #004085; }
        .bg-paid { background-color: #d4edda; color: #155724; }
        .bg-cancelled { background-color: #f8d7da; color: #721c24; }
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
                    <li><a href="index.php" class="active"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="../reports/financial.php"><i class="fas fa-chart-line"></i> Financial Reports</a></li>
                    <li><a href="../reports/occupancy.php"><i class="fas fa-bed"></i> Occupancy Reports</a></li>
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
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile" class="user-avatar">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Booking Management</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li>Bookings</li>
                </ul>
            </div>

            <!-- Bookings Table -->
            <div class="card">
                <div class="card-header">
                    <h2>All Bookings</h2>
                    <div class="filter-section">
                        <div class="filter-group">
                            <span class="filter-label">Status:</span>
                            <select class="filter-select" id="statusFilter" onchange="filterBookings()">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Bookings</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <a href="export_bookings.php?status=<?= $status_filter ?>" class="btn btn-primary export-btn">
                            <i class="fas fa-file-export"></i> Export to Excel
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($bookings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Bookings Found</h3>
                            <p>There are no bookings matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Property</th>
                                        <th>Room</th>
                                        <th>Student</th>
                                        <th>Owner</th>
                                        <th>Dates</th>
                                        <th>Duration</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($bookings as $booking): ?>
                                    <tr>
                                        <td>#<?= $booking['id'] ?></td>
                                        <td>
                                            <?= htmlspecialchars($booking['property_name']) ?>
                                            <div class="small text-muted"><?= htmlspecialchars($booking['property_location']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['room_number'])): ?>
                                                Room <?= htmlspecialchars($booking['room_number']) ?>
                                                <div class="small">
                                                    <?= ucfirst($booking['room_gender']) ?? 'N/A' ?>
                                                </div>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold"><?= htmlspecialchars($booking['student_name']) ?></div>
                                            <div class="small"><?= htmlspecialchars($booking['student_email']) ?></div>
                                            <div class="small"><?= htmlspecialchars($booking['student_phone']) ?></div>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold"><?= htmlspecialchars($booking['owner_name']) ?></div>
                                            <div class="small"><?= htmlspecialchars($booking['owner_email']) ?></div>
                                            <div class="small"><?= htmlspecialchars($booking['owner_phone']) ?></div>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($booking['start_date'])) ?> - 
                                            <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                                        </td>
                                        <td><?= $booking['duration_months'] ?> months</td>
                                        <td>
                                            <?php if ($booking['payment_amount']): ?>
                                                GHS <?= number_format($booking['payment_amount'], 2) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $status_class = '';
                                                switch ($booking['status']) {
                                                    case 'pending': $status_class = 'bg-pending'; break;
                                                    case 'confirmed': $status_class = 'bg-confirmed'; break;
                                                    case 'paid': $status_class = 'bg-paid'; break;
                                                    case 'cancelled': $status_class = 'bg-cancelled'; break;
                                                }
                                            ?>
                                            <span class="status-badge <?= $status_class ?>">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($booking['payment_status']): ?>
                                                <span class="status-badge <?= $booking['payment_status'] === 'completed' ? 'bg-paid' : 'bg-pending' ?>">
                                                    <?= ucfirst($booking['payment_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="action-buttons">
                                            <?php if ($booking['status'] == 'pending'): ?>
                                                <form action="approve.php" method="post" style="display: inline;">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form action="reject.php" method="post" style="display: inline;">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <a href="view.php?id=<?= $booking['id'] ?>" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['status'] !== 'cancelled'): ?>
                                                <form action="cancel.php" method="post" style="display: inline;">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-outline btn-sm">
                                                        <i class="fas fa-ban"></i> Cancel
                                                    </button>
                                                </form>
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

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Filter bookings by status
        function filterBookings() {
            const status = document.getElementById('statusFilter').value;
            window.location.href = `?status=${status}`;
        }
    </script>
</body>
</html>