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

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_read'])) {
    try {
        if (isset($_POST['notification_id'])) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['notification_id'], $owner_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$owner_id]);
        }
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $error_message = "Error updating notification: " . $e->getMessage();
    }
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$owner_id]);
$notifications = $stmt->fetchAll();

$unread_notifications = array_filter($notifications, function($n) {
    return !$n['is_read'];
});

$read_notifications = array_filter($notifications, function($n) {
    return $n['is_read'];
});

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
            --secondary-color: #2c3e50;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
        }
        body {
            background-color: var(--light-color);
        }
        .notification-card {
            border-left: 5px solid var(--info-color);
        }
        .notification-card.is-read {
            border-left-color: #ccc;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Notifications</h1>
            <form method="POST">
                <button type="submit" name="mark_as_read" class="btn btn-primary">Mark all as read</button>
            </form>
        </div>

        <div class="card">
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php if (empty($notifications)): ?>
                        <li class="list-group-item text-center">You have no notifications.</li>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center <?php echo !$notification['is_read'] ? 'fw-bold' : ''; ?>">
                                <div>
                                    <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></small>
                                </div>
                                <?php if (!$notification['is_read']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <button type="submit" name="mark_as_read" class="btn btn-sm btn-outline-success">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>