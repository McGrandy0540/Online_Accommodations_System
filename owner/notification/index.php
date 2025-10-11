<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get owner data for header
$owner_stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
$owner_stmt->execute([$owner_id]);
$owner = $owner_stmt->fetch();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle mark all as read
        if (isset($_POST['mark_all_read'])) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$owner_id]);
            $_SESSION['success_message'] = "All notifications marked as read!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Handle clear all notifications
        if (isset($_POST['clear_all_notifications'])) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$owner_id]);
            $_SESSION['success_message'] = "All notifications cleared!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Handle mark single notification as read
        if (isset($_POST['mark_single_read']) && isset($_POST['notification_id'])) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['notification_id'], $owner_id]);
            $_SESSION['success_message'] = "Notification marked as read!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Handle delete single notification
        if (isset($_POST['delete_single']) && isset($_POST['notification_id'])) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['notification_id'], $owner_id]);
            $_SESSION['success_message'] = "Notification deleted!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');


// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Get total counts
$total_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?");
$total_stmt->execute([$owner_id]);
$total_notifications = $total_stmt->fetch()['total'];
$total_pages = ceil($total_notifications / $limit);

$unread_stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$owner_id]);
$unread_count = $unread_stmt->fetch()['unread'];

// Fetch notifications with pagination
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $owner_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Categorize notifications
$unread_notifications = array_filter($notifications, function($n) {
    return !$n['is_read'];
});

$read_notifications = array_filter($notifications, function($n) {
    return $n['is_read'];
});

// Get notification statistics for the last 30 days
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_30_days,
        COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread_30_days,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_7_days
    FROM notifications 
    WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stats_stmt->execute([$owner_id]);
$stats = $stats_stmt->fetch();


// Get profile picture path
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Property Owner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --border-radius: 12px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        body {
            background: linear-gradient(135deg, #f8f9fa 100%, #764ba2 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-top: 2rem;
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 1.5rem 2rem;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--success-color), var(--warning-color), var(--danger-color));
        }

        .notification-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
            transition: var(--transition);
            border-left: 4px solid var(--info-color);
            background: white;
        }

        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .notification-card.unread {
            border-left-color: var(--primary-color);
            background: linear-gradient(135deg, #ffffff, #f0f8ff);
        }

        .notification-card.read {
            border-left-color: #dee2e6;
            background: #f8f9fa;
            opacity: 0.8;
        }

        .notification-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
        }

        .notification-actions {
            opacity: 0;
            transition: var(--transition);
        }

        .notification-card:hover .notification-actions {
            opacity: 1;
        }

        .stats-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid #e9ecef;
        }

        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            border-radius: 25px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.4);
        }

        .btn-outline {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            border-radius: 25px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            background: transparent;
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 1rem 1.5rem;
            transition: var(--transition);
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background: transparent;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }

        .notification-time {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-right: 1rem;
        }

        .notification-icon.info {
            background: linear-gradient(135deg, var(--info-color), #138496);
            color: white;
        }

        .notification-icon.success {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            color: white;
        }

        .notification-icon.warning {
            background: linear-gradient(135deg, var(--warning-color), #d39e00);
            color: white;
        }

        .notification-icon.danger {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
            color: white;
        }

        .pagination .page-link {
            border: none;
            color: var(--primary-color);
            padding: 0.75rem 1rem;
            margin: 0 0.25rem;
            border-radius: 8px;
            transition: var(--transition);
        }

        .pagination .page-item.active .page-link {
            background: var(--primary-color);
            color: white;
        }

        .pagination .page-link:hover {
            background: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }
            
            .stats-card {
                margin-bottom: 1rem;
            }
            
            .notification-actions {
                opacity: 1;
                margin-top: 1rem;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .d-flex.gap-2 {
                flex-direction: column;
            }
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            border-radius: 25px;
            padding: 0.5rem 1rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateX(-5px);
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .mobile-actions {
            display: none;
        }
        
        @media (max-width: 576px) {
            .desktop-actions {
                display: none;
            }
            
            .mobile-actions {
                display: flex;
                margin-top: 1rem;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container main-container">
        <!-- Header -->
        <div class="header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-3">
                        <a href="../dashboard.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i>
                            Back to Dashboard
                        </a>
                        <div>
                            <h1 class="h3 mb-1"><i class="fas fa-bell me-2"></i>Notifications</h1>
                            <p class="mb-0 opacity-75">Stay updated with your property activities</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="d-flex align-items-center justify-content-md-end gap-3">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile" class="profile-img">
                        <?php else: ?>
                            <div class="profile-img bg-light d-flex align-items-center justify-content-center">
                                <i class="fas fa-user text-muted"></i>
                            </div>
                        <?php endif; ?>
                        <div class="text-white">
                            <div class="fw-bold"><?= htmlspecialchars($owner['username'] ?? 'Owner') ?></div>
                            <small>Property Owner</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container-fluid py-4">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <?php unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <?php unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-primary"><?= $total_notifications ?></div>
                        <div class="text-muted">Total Notifications</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-warning"><?= $unread_count ?></div>
                        <div class="text-muted">Unread</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-info"><?= $stats['total_30_days'] ?? 0 ?></div>
                        <div class="text-muted">Last 30 Days</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-number text-success"><?= $stats['recent_7_days'] ?? 0 ?></div>
                        <div class="text-muted">Last 7 Days</div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex gap-2 flex-wrap">
                        <form method="POST" class="d-inline">
                            <button type="submit" name="mark_all_read" class="btn btn-primary">
                                <i class="fas fa-check-double me-2"></i>Mark All as Read
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to clear all notifications? This action cannot be undone.')">
                            <button type="submit" name="clear_all_notifications" class="btn btn-outline-danger">
                                <i class="fas fa-trash me-2"></i>Clear All
                            </button>
                        </form>
                        <button class="btn btn-outline" onclick="refreshNotifications()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>
            </div>

            <!-- Notifications Tabs -->
            <div class="row">
                <div class="col-12">
                    <ul class="nav nav-tabs mb-4" id="notificationTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                                All Notifications <span class="badge bg-primary ms-1"><?= $total_notifications ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="unread-tab" data-bs-toggle="tab" data-bs-target="#unread" type="button" role="tab">
                                Unread <span class="badge bg-warning ms-1"><?= $unread_count ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="read-tab" data-bs-toggle="tab" data-bs-target="#read" type="button" role="tab">
                                Read <span class="badge bg-success ms-1"><?= $total_notifications - $unread_count ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="notificationTabsContent">
                        <!-- All Notifications Tab -->
                        <div class="tab-pane fade show active" id="all" role="tabpanel">
                            <?php if (empty($notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell-slash"></i>
                                    <h3>No Notifications</h3>
                                    <p>You're all caught up! Check back later for new updates about your properties.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <?php echo renderNotificationCard($notification); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Unread Notifications Tab -->
                        <div class="tab-pane fade" id="unread" role="tabpanel">
                            <?php if (empty($unread_notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h3>No Unread Notifications</h3>
                                    <p>Great! You've read all your notifications.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($unread_notifications as $notification): ?>
                                    <?php echo renderNotificationCard($notification); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Read Notifications Tab -->
                        <div class="tab-pane fade" id="read" role="tabpanel">
                            <?php if (empty($read_notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <h3>No Read Notifications</h3>
                                    <p>You haven't marked any notifications as read yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($read_notifications as $notification): ?>
                                    <?php echo renderNotificationCard($notification); ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Notification pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="Previous">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="Next">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function refreshNotifications() {
            window.location.reload();
        }

        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            const unreadCount = <?= $unread_count ?>;
            if (unreadCount > 0) {
                refreshNotifications();
            }
        }, 30000);

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
    </script>
</body>
</html>

<?php
function renderNotificationCard($notification) {
    $icon_class = getNotificationIcon($notification['type'] ?? 'info');
    $time_ago = getTimeAgo($notification['created_at']);
    $is_read = $notification['is_read'];
    
    ob_start();
    ?>
    <div class="notification-card <?= $is_read ? 'read' : 'unread' ?>">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="notification-icon <?= $icon_class['class'] ?>">
                        <i class="<?= $icon_class['icon'] ?>"></i>
                    </div>
                </div>
                <div class="col">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <p class="mb-1 <?= !$is_read ? 'fw-bold' : '' ?>">
                                <?= htmlspecialchars($notification['message']) ?>
                            </p>
                            <div class="d-flex gap-3 flex-wrap">
                                <small class="notification-time">
                                    <i class="fas fa-clock me-1"></i><?= $time_ago ?>
                                </small>
                                <?php if ($notification['type']): ?>
                                    <span class="notification-type-badge bg-<?= $icon_class['class'] ?> text-white">
                                        <?= ucfirst($notification['type']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="notification-actions desktop-actions">
                            <div class="btn-group btn-group-sm">
                                <?php if (!$is_read): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                        <button type="submit" name="mark_single_read" class="btn btn-success" 
                                                data-bs-toggle="tooltip" title="Mark as read">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this notification?')">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <button type="submit" name="delete_single" class="btn btn-danger" 
                                            data-bs-toggle="tooltip" title="Delete notification">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- Mobile Actions -->
                    <div class="mobile-actions">
                        <div class="d-flex gap-2">
                            <?php if (!$is_read): ?>
                                <form method="POST" class="d-inline flex-fill">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <button type="submit" name="mark_single_read" class="btn btn-success btn-sm w-100">
                                        <i class="fas fa-check me-1"></i>Read
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" class="d-inline flex-fill" onsubmit="return confirm('Are you sure you want to delete this notification?')">
                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                <button type="submit" name="delete_single" class="btn btn-danger btn-sm w-100">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function getNotificationIcon($type) {
    $icons = [
        'info' => ['icon' => 'fas fa-info', 'class' => 'info'],
        'success' => ['icon' => 'fas fa-check-circle', 'class' => 'success'],
        'warning' => ['icon' => 'fas fa-exclamation-triangle', 'class' => 'warning'],
        'danger' => ['icon' => 'fas fa-exclamation-circle', 'class' => 'danger'],
        'booking' => ['icon' => 'fas fa-calendar-check', 'class' => 'info'],
        'payment' => ['icon' => 'fas fa-credit-card', 'class' => 'success'],
        'maintenance' => ['icon' => 'fas fa-tools', 'class' => 'warning'],
        'system' => ['icon' => 'fas fa-cog', 'class' => 'info']
    ];
    
    return $icons[$type] ?? $icons['info'];
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $time_difference = time() - $time;
    
    if ($time_difference < 1) {
        return 'just now';
    }
    
    $condition = [
        12 * 30 * 24 * 60 * 60  => 'year',
        30 * 24 * 60 * 60       => 'month',
        24 * 60 * 60            => 'day',
        60 * 60                 => 'hour',
        60                      => 'minute',
        1                       => 'second'
    ];
    
    foreach ($condition as $secs => $str) {
        $d = $time_difference / $secs;
        
        if ($d >= 1) {
            $t = round($d);
            return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
        }
    }
}
?>