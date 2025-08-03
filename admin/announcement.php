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

// Check if user is admin
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once '../config/database.php';
require_once '../includes/EmailService.php'; // Include email service
require_once  '../config/email.php';

// Get the PDO instance
$database = new Database();
$pdo = $database->connect();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_announcement'])) {
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $targetGroup = $_POST['target_group'] ?? 'all';
        $isUrgent = isset($_POST['is_urgent']) ? 1 : 0;
        
        try {
            // Insert announcement
            $stmt = $pdo->prepare("
                INSERT INTO announcements (sender_id, title, message, target_group, is_urgent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $title, $message, $targetGroup, $isUrgent]);
            $announcementId = $pdo->lastInsertId();
            
            // Determine recipients
            $recipients = [];
            $recipientQuery = "SELECT id, email FROM users WHERE deleted = 0";
            
            if ($targetGroup === 'students') {
                $recipientQuery .= " AND status = 'student'";
            } elseif ($targetGroup === 'property_owners') {
                $recipientQuery .= " AND status = 'property_owner'";
            }
            
            $stmt = $pdo->query($recipientQuery);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Insert recipients and send emails
            if (!empty($recipients)) {
                $values = [];
                $params = [];
                $emailService = new EmailService();
                
                foreach ($recipients as $user) {
                    // Insert recipient
                    $values[] = '(?, ?)';
                    $params[] = $announcementId;
                    $params[] = $user['id'];
                    
                    // Send announcement email
                    $emailSubject = $isUrgent ? "[URGENT] $title" : $title;
                    $emailService->sendAnnouncement(
                        $user['email'],
                        $emailSubject,
                        $message
                    );
                }
                
                // Batch insert recipients
                $stmt = $pdo->prepare("
                    INSERT INTO announcement_recipients (announcement_id, user_id)
                    VALUES " . implode(', ', $values)
                );
                $stmt->execute($params);
            }
        
            $success = "Announcement created and notifications sent successfully!";
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            $error = "Failed to create announcement. Please try again.";
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            $error = "Announcement created but some emails failed to send.";
        }
    }
}

// Fetch existing announcements
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username as sender_name
        FROM announcements a
        JOIN users u ON a.sender_id = u.id
        ORDER by a.created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recipient counts
    foreach ($announcements as &$announcement) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM announcement_recipients 
            WHERE announcement_id = ?
        ");
        $stmt->execute([$announcement['id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $announcement['recipient_count'] = $count;
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $announcements = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - UniHomes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #2c3e50;
            --danger: #f72585;
            --success: #4cc9f0;
            --warning: #f8961e;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --white: #ffffff;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: var(--secondary);
            color: white;
            transition: all 0.3s;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-menu {
            padding: 15px 0;
        }
        
        .sidebar-menu ul {
            list-style: none;
        }
        
        .sidebar-menu li a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background 0.3s;
        }
        
        .sidebar-menu li a:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-menu li a.active {
            background: var(--primary);
        }
        
        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .top-nav {
            background: var(--white);
            padding: 15px 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .admin-badge {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 8px;
        }
        
        .content-area {
            padding: 25px;
            flex: 1;
        }
        
        .page-header {
            margin-bottom: 25px;
        }
        
        .page-title h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .breadcrumb {
            list-style: none;
            display: flex;
            padding: 0;
        }
        
        .breadcrumb li {
            margin-right: 10px;
        }
        
        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin-left: 10px;
            color: var(--gray);
        }
        
        .breadcrumb li a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .announcement-container {
            display: flex;
            gap: 25px;
        }
        
        .announcement-form {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
        }
        
        .announcement-form h2 {
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .announcement-form h2 i {
            color: var(--primary);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }
        
        .announcement-list {
            flex: 1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
        }
        
        .announcement-list h2 {
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--secondary);
        }
        
        .announcement-item {
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .announcement-item:last-child {
            border-bottom: none;
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .announcement-title {
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--secondary);
        }
        
        .announcement-meta {
            display: flex;
            gap: 15px;
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .urgent {
            background: var(--warning);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .announcement-message {
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .announcement-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--light-gray);
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        @media (max-width: 992px) {
            .announcement-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 70px;
            }
            
            .sidebar .menu-text {
                display: none;
            }
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
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../admin/properties/"><i class="fas fa-home"></i> Manage Accommodations</a></li>
                    <li><a href="../admin/users/"><i class="fas fa-users"></i> Manage Students</a></li>
                    <li><a href="../admin/payments/"><i class="fas fa-wallet"></i> Payment Management</a></li>
                    <li><a href="../admin/reports/"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a></li>
                    <li><a href="../admin/approvals/"><i class="fas fa-calendar-alt"></i> Booking Approvals</a></li>
                    <li><a href="../admin/admins/"><i class="fas fa-user-shield"></i> Admin Users</a></li>
                    <li><a href="../admin/announcement.php" class="active"><i class="fa-solid fa-bullhorn"></i> Announcements</a></li>
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
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?> <span class="admin-badge">ADMIN</span></span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <div class="page-title">
                        <h1>Announcements Management</h1>
                    </div>
                    <ul class="breadcrumb">
                        <li><a href="../admin/">Home</a></li>
                        <li>Announcements</li>
                    </ul>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="announcement-container">
                    <div class="announcement-form">
                        <h2>Create New Announcement</h2>
                        <form method="POST">
                            <div class="form-group">
                                <label for="title">Title</label>
                                <input type="text" id="title" name="title" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="message">Message</label>
                                <textarea id="message" name="message" class="form-control" required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="target_group">Target Audience</label>
                                <select id="target_group" name="target_group" class="form-control" required>
                                    <option value="all">All Users</option>
                                    <option value="students">Students Only</option>
                                    <option value="property_owners">Property Owners Only</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_urgent"> 
                                    Mark as Urgent
                                </label>
                            </div>
                            
                            <button type="submit" name="create_announcement" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Announcement
                            </button>
                        </form>
                    </div>
                    
                    <div class="announcement-list">
                        <h2>Recent Announcements</h2>
                        
                        <?php if (!empty($announcements)): ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="announcement-item">
                                    <div class="announcement-header">
                                        <div class="announcement-title">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </div>
                                        <div class="announcement-meta">
                                            <?php if ($announcement['is_urgent']): ?>
                                                <span class="urgent">URGENT</span>
                                            <?php endif; ?>
                                            <span><?php echo date('M j, Y g:i a', strtotime($announcement['created_at'])); ?></span>
                                            <span>Sent to <?php echo $announcement['recipient_count']; ?> users</span>
                                        </div>
                                    </div>
                                    <div class="announcement-message">
                                        <?php echo nl2br(htmlspecialchars($announcement['message'])); ?>
                                    </div>
                                    <div class="announcement-actions">
                                        <button class="btn btn-outline btn-sm">
                                            <i class="fas fa-eye"></i> View Recipients
                                        </button>
                                        <button class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <h3>No Announcements Yet</h3>
                                <p>Create your first announcement using the form on the left.</p>
                            </div>
                        <?php endif; ?>
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