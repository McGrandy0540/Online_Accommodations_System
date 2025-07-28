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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_announcement'])) {
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $targetGroup = $_POST['target_group'] ?? 'all';
        $isUrgent = isset($_POST['is_urgent']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (sender_id, title, message, target_group, is_urgent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $title, $message, $targetGroup, $isUrgent]);
            
            $announcementId = $pdo->lastInsertId();
            
            // Determine recipients based on target group
            $recipients = [];
            if ($targetGroup === 'all') {
                $stmt = $pdo->query("SELECT id FROM users WHERE deleted = 0");
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($targetGroup === 'students') {
                $stmt = $pdo->query("SELECT id FROM users WHERE status = 'student' AND deleted = 0");
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($targetGroup === 'property_owners') {
                $stmt = $pdo->query("SELECT id FROM users WHERE status = 'property_owner' AND deleted = 0");
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Insert recipients
            if (!empty($recipients)) {
                $values = [];
                $params = [];
                foreach ($recipients as $userId) {
                    $values[] = '(?, ?)';
                    $params[] = $announcementId;
                    $params[] = $userId;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO announcement_recipients (announcement_id, user_id)
                    VALUES " . implode(', ', $values)
                );
                $stmt->execute($params);
            }
            
            $success = "Announcement created successfully!";
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            $error = "Failed to create announcement. Please try again.";
        }
    }
}

// Fetch existing announcements
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username as sender_name
        FROM announcements a
        JOIN users u ON a.sender_id = u.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recipient counts for each announcement
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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - UniHomes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        .announcement-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        @media (min-width: 992px) {
            .announcement-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .announcement-form {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .announcement-list {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4a6bff;
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: #4a6bff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #3a56e8;
        }
        
        .announcement-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .announcement-item:last-child {
            border-bottom: none;
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .announcement-title {
            font-weight: 600;
            font-size: 18px;
            color: #333;
        }
        
        .announcement-meta {
            font-size: 13px;
            color: #666;
        }
        
        .announcement-meta span {
            margin-right: 10px;
        }
        
        .announcement-meta .urgent {
            color: #dc3545;
            font-weight: 500;
        }
        
        .announcement-message {
            margin-top: 10px;
            color: #444;
        }
        
        .announcement-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #4a6bff;
            color: #4a6bff;
        }
        
        .btn-outline:hover {
            background-color: #4a6bff;
            color: white;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #28a745;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #dc3545;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            font-weight: 500;
        }
        
        @media (max-width: 576px) {
            .announcement-header {
                flex-direction: column;
            }
            
            .announcement-meta {
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar (same as in dashboard.php) -->
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
                    <li><a href="../admin/settings/"><i class="fas fa-cog"></i> System Settings</a></li>
                    <li><a href="../admin/announcement.php" class="active"><i class="fa-solid fa-bullhorn"></i> Announcements</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation (same as in dashboard.php) -->
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