<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Get the PDO instance from the Database class
$database = new Database();
$pdo = $database->connect();

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
try {
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
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    // Set default values if query fails
    $totalProperties = 0;
    $totalStudents = 0;
    $pendingApprovals = 0;
    $systemAlerts = 0;
    $recentApprovals = [];
    $systemAlertsList = [];
    $recentActions = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        /* Additional styles for header and footer */
        .main-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: white;
            font-weight: 600;
            font-size: 1.5rem;
        }
        
        .logo img {
            height: 40px;
            margin-right: 10px;
        }
        
        .main-nav {
            display: flex;
            gap: 20px;
        }
        
        .main-nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .main-nav a:hover {
            color: var(--primary-color);
        }
        
        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .auth-buttons .btn {
            padding: 8px 15px;
            border-radius: 4px;
            font-weight: 500;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid white;
        }
        
        .main-footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 2rem 0;
            margin-top: 2rem;
        }

                .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
        }
        
        .logout-btn {
            display: flex;
            align-items: center;
            color: var(--white);
            text-decoration: none;
            transition: all 0.3s;
            width: 100%;
        }
        
        .logout-btn:hover {
            opacity: 0.8;
        }
        
        .logout-btn i {
            font-size: 1.2rem;
        }
        
        .logout-btn span {
            margin-left: 10px;
            transition: opacity var(--transition-speed);
        }
        
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
        }
        
        .footer-column h3 {
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .footer-column ul {
            list-style: none;
        }
        
        .footer-column li {
            margin-bottom: 0.5rem;
        }
        
        .footer-column a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .footer-column a:hover {
            color: white;
        }
        
        .copyright {
            text-align: center;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- Main Header -->
    <header class="main-header">
        <div class="header-content">
            <a href="../" class="logo">
                <img src="../assets/images/logo-removebg-preview.png" alt="UniHomes Logo">
                <span>Landlords&Tenant</span>
            </a>
            
            <nav class="main-nav">
                <a href="../">Home</a>
                <a href="../properties/">Properties</a>
                <a href="../about/">About</a>
                <a href="../contact/">Contact</a>
            </nav>
            
            <div class="user-controls">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="user-dropdown">
                        <a href="profile/" class="user-profile">
                            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="User Profile" style="width: 30px; height: 30px; border-radius: 50%;">
                            <span><?php echo htmlspecialchars($username); ?></span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="auth-buttons">
                        <a href="../auth/login.php" class="btn btn-outline">Login</a>
                        <a href="../auth/register.php" class="btn btn-primary">Register</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Landlords&Tenant Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="properties/"><i class="fas fa-home"></i> Manage Accommodations</a></li>
                    <li><a href="users/"><i class="fas fa-users"></i> Manage Students</a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> Payment Management</a></li>
                    <li><a href="reports/"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a></li>
                    <li><a href="approvals/"><i class="fas fa-calendar-alt"></i> Booking Approvals</a></li>
                    <li><a href="announcement.php"><i class="fa-solid fa-bullhorn"></i></i> Announcement </a></li>
<li>
   <form action="logout.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
       <button type="submit" class="dropdown-item">
         <i class="fas fa-sign-out-alt "></i> Logout
      </button>
   </form>
</li>
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
                                        <div class="activity-icon bg-danger text-white">
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
                                        <div class="activity-icon bg-primary text-white">
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
                            <div class="admin-tools-grid">
                                <a href="users/" class="tool-btn bg-primary">
                                    <i class="fas fa-user-cog"></i>
                                    <div>User Management</div>
                                </a>
                        
                                <a href="exports/" class="tool-btn bg-success">
                                    <i class="fas fa-file-export"></i>
                                    <div>Export Data</div>
                                </a>
                                <a href="notifications/" class="tool-btn bg-warning text-dark">
                                    <i class="fas fa-bell"></i>
                                    <div>System Notifications</div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Footer -->
    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-column">
                <h3>About Landlords&Tenants</h3>
                <p>Providing quality accommodation solutions for Tenants since 2010. Our mission is to make Tenant living comfortable and affordable.</p>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="../">Home</a></li>
                    <li><a href="../properties/">Properties</a></li>
                    <li><a href="../about/">About Us</a></li>
                    <li><a href="../contact/">Contact</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support</h3>
                <ul>
                    <li><a href="../faq/">FAQ</a></li>
                    <li><a href="../help/">Help Center</a></li>
                    <li><a href="../privacy/">Privacy Policy</a></li>
                    <li><a href="../terms/">Terms of Service</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Contact Us</h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt"></i> 123 University Ave, Campus Town</li>
                    <li><i class="fas fa-phone"></i> +233 240 687 599</li>
                    <li><i class="fas fa-envelope"></i> info@landlords&tenant.com</li>
                </ul>
                <div class="social-links" style="margin-top: 10px;">
                    <a href="#" style="color: white; margin-right: 10px;"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" style="color: white; margin-right: 10px;"><i class="fab fa-twitter"></i></a>
                    <a href="#" style="color: white; margin-right: 10px;"><i class="fab fa-instagram"></i></a>
                    <a href="#" style="color: white;"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
        </div>
        
        <div class="copyright">
            &copy; <?php echo date('Y'); ?> UniHomes. All rights reserved.
        </div>
    </footer>

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