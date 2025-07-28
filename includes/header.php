<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin (assuming role is stored in session)
if ($_SESSION['status'] !== 'admin') {
    // If not admin, show access denied
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once '../config/database.php';

// Get user data from session
$username = $_SESSION['username'] ?? 'Admin';
$email = $_SESSION['email'] ?? 'admin@example.com';
$avatar = $_SESSION['avatar'] ?? 'https://randomuser.me/api/portraits/men/32.jpg';

// Function to get counts from database
function getCount($pdo, $table, $where = "") {
    $sql = "SELECT COUNT(*) as count FROM $table";
    if (!empty($where)) {
        $sql .= " WHERE $where";
    }
    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

// Get statistics
$totalProperties = getCount($pdo, 'property', "deleted = 0");
$totalStudents = getCount($pdo, 'users', "status = 'student' AND deleted = 0");
$pendingApprovals = getCount($pdo, 'bookings', "status = 'pending'");
$systemAlerts = getCount($pdo, 'notifications', "type = 'system_alert' AND is_read = 0");

// Get recent pending approvals
$recentApprovals = [];
$stmt = $pdo->query("
    SELECT b.id, b.start_date, b.end_date, u.username as student_name, p.property_name 
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN property p ON b.property_id = p.id
    WHERE b.status = 'pending'
    ORDER BY b.booking_date DESC
    LIMIT 5
");
$recentApprovals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system alerts
$systemAlertsList = [];
$stmt = $pdo->query("
    SELECT id, message, created_at 
    FROM notifications 
    WHERE type = 'system_alert' 
    ORDER BY created_at DESC 
    LIMIT 3
");
$systemAlertsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent admin actions
$recentActions = [];
$stmt = $pdo->query("
    SELECT a.action_type, a.details, a.created_at, u.username as admin_name
    FROM admin_actions a
    JOIN users u ON a.admin_id = u.id
    ORDER BY a.created_at DESC
    LIMIT 5
");
$recentActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        .admin-badge {
            background-color: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }
        .approval-actions {
            display: flex;
            gap: 5px;
        }
        .approve-btn {
            background-color: var(--success-color) !important;
        }
        .reject-btn {
            background-color: var(--danger-color) !important;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>UniHomes Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="properties/"><i class="fas fa-home"></i> Manage Accommodations</a></li>
                    <li><a href="users/"><i class="fas fa-users"></i> Manage Students</a></li>
                    <li><a href="hostels/"><i class="fas fa-building"></i> Hostel Management</a></li>
                    <li><a href="reports/"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a></li>
                    <li><a href="approvals/"><i class="fas fa-calendar-alt"></i> Booking Approvals</a></li>
                    <li><a href="admins/"><i class="fas fa-user-shield"></i> Admin Users</a></li>
                    <li><a href="settings/"><i class="fas fa-cog"></i> System Settings</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </div>
                <div class="user-profile">
                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="User Profile">
                    <span><?php echo htmlspecialchars($username); ?> <span class="admin-badge">ADMIN</span></span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <div class="page-title">
                        <h1>Admin Dashboard Overview</h1>
                    </div>
                    <ul class="breadcrumb">
                        <li><a href="../">Home</a></li>
                        <li>Admin Dashboard</li>
                    </ul>
                </div>

                <!-- Admin-specific Stats Cards -->
                <div class="stats-cards">
                    <div class="stat-card primary">
                        <i class="fas fa-home"></i>
                        <h3>Total Accommodations</h3>
                        <h2><?php echo number_format($totalProperties); ?></h2>
                        <p><?php echo rand(5, 15); ?>% from last month</p>
                    </div>
                    <div class="stat-card success">
                        <i class="fas fa-users"></i>
                        <h3>Registered Students</h3>
                        <h2><?php echo number_format($totalStudents); ?></h2>
                        <p><?php echo rand(3, 10); ?>% from last month</p>
                    </div>
                    <div class="stat-card warning">
                        <i class="fas fa-calendar-check"></i>
                        <h3>Pending Approvals</h3>
                        <h2><?php echo number_format($pendingApprovals); ?></h2>
                        <p>Need your attention</p>
                    </div>
                    <div class="stat-card danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <h3>System Alerts</h3>
                        <h2><?php echo number_format($systemAlerts); ?></h2>
                        <p>Requires immediate action</p>
                    </div>
                </div>

                <!-- Admin-specific content -->
                <div class="dashboard-row">
                    <div class="card">
                        <div class="card-header">
                            <h2>Pending Approvals</h2>
                            <a href="approvals/" class="btn">Manage All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentApprovals)): ?>
                                <p>No pending approvals at this time.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Student</th>
                                            <th>Property</th>
                                            <th>Dates</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentApprovals as $approval): ?>
                                        <tr>
                                            <td>#BK-<?php echo $approval['id']; ?></td>
                                            <td><?php echo htmlspecialchars($approval['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($approval['property_name']); ?></td>
                                            <td>
                                                <?php 
                                                echo date('M j', strtotime($approval['start_date'])) . ' - ' . 
                                                     date('M j, Y', strtotime($approval['end_date'])); 
                                                ?>
                                            </td>
                                            <td>
                                                <div class="approval-actions">
                                                    <form action="approve_booking.php" method="post" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $approval['id']; ?>">
                                                        <button type="submit" class="approve-btn">Approve</button>
                                                    </form>
                                                    <form action="reject_booking.php" method="post" style="display: inline;">
                                                        <input type="hidden" name="booking_id" value="<?php echo $approval['id']; ?>">
                                                        <button type="submit" class="reject-btn">Reject</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h2>System Alerts</h2>
                            <a href="alerts/" class="btn">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($systemAlertsList)): ?>
                                <p>No system alerts at this time.</p>
                            <?php else: ?>
                                <ul class="recent-activity">
                                    <?php foreach ($systemAlertsList as $alert): ?>
                                    <li>
                                        <div class="activity-icon" style="background-color: #f8d7da; color: var(--danger-color);">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <div class="activity-content">
                                            <h4>System Alert</h4>
                                            <p><?php echo htmlspecialchars($alert['message']); ?></p>
                                            <div class="activity-time">
                                                <?php echo date('M j, Y g:i a', strtotime($alert['created_at'])); ?>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Admin tools -->
                <div class="dashboard-row">
                    <div class="card">
                        <div class="card-header">
                            <h2>Recent Admin Actions</h2>
                            <a href="actions/" class="btn">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentActions)): ?>
                                <p>No recent actions recorded.</p>
                            <?php else: ?>
                                <ul class="recent-activity">
                                    <?php foreach ($recentActions as $action): ?>
                                    <li>
                                        <div class="activity-icon">
                                            <i class="fas fa-user-shield"></i>
                                        </div>
                                        <div class="activity-content">
                                            <h4><?php echo htmlspecialchars(ucfirst($action['action_type'])); ?></h4>
                                            <p><?php echo htmlspecialchars($action['details']); ?></p>
                                            <div class="activity-time">
                                                By <?php echo htmlspecialchars($action['admin_name']); ?> • 
                                                <?php echo date('M j, Y g:i a', strtotime($action['created_at'])); ?>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h2>Admin Tools</h2>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <a href="users/" style="padding: 15px; background-color: var(--primary-color); color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; text-align: center;">
                                    <i class="fas fa-user-cog" style="font-size: 20px; margin-bottom: 5px;"></i>
                                    <div>User Management</div>
                                </a>
                                <a href="backup/" style="padding: 15px; background-color: var(--danger-color); color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; text-align: center;">
                                    <i class="fas fa-database" style="font-size: 20px; margin-bottom: 5px;"></i>
                                    <div>Database Backup</div>
                                </a>
                                <a href="exports/" style="padding: 15px; background-color: var(--success-color); color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; text-align: center;">
                                    <i class="fas fa-file-export" style="font-size: 20px; margin-bottom: 5px;"></i>
                                    <div>Export Data</div>
                                </a>
                                <a href="notifications/" style="padding: 15px; background-color: var(--warning-color); color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; text-align: center;">
                                    <i class="fas fa-bell" style="font-size: 20px; margin-bottom: 5px;"></i>
                                    <div>System Notifications</div>
                                </a>
                            </div>
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