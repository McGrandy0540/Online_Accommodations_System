<?php
// Database connection
require_once __DIR__ . '../../../config/database.php';
$db = Database::getInstance();

// Start session and check admin privileges
if (session_status() === PHP_SESSION_NONE) session_start();

// Check if admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user ID is provided and valid
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid user ID";
    header("Location: index.php");
    exit();
}

$userId = intval($_GET['id']);
$confirmation = false;
$error = '';
$success = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Start transaction
        $db->beginTransaction();

        // Get user details for confirmation
        $stmt = $db->prepare("SELECT username, profile_picture, status FROM users WHERE id = ? AND deleted = 0");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("User not found or already deleted");
        }

        // Verify current user is admin - check both users.status and admin table
        $stmt = $db->prepare("SELECT u.status, a.id as admin_id 
                            FROM users u 
                            LEFT JOIN admin a ON a.user_id = u.id 
                            WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $adminData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$adminData) {
            throw new Exception("User record not found");
        }

        // Check admin privileges
        if ($adminData['status'] !== 'admin' && empty($adminData['admin_id'])) {
            throw new Exception("You don't have admin privileges");
        }

        // Get or create admin record
        if (empty($adminData['admin_id'])) {
            // Create admin record if it doesn't exist
            $stmt = $db->prepare("INSERT INTO admin (user_id) VALUES (?)");
            $stmt->execute([$_SESSION['user_id']]);
            $adminId = $db->lastInsertId();
        } else {
            $adminId = $adminData['admin_id'];
        }

        // Soft delete the user (handle case where deleted_at might not exist)
        try {
            $stmt = $db->prepare("UPDATE users SET deleted = 1, deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // Fallback if deleted_at column doesn't exist
            $stmt = $db->prepare("UPDATE users SET deleted = 1 WHERE id = ?");
            $stmt->execute([$userId]);
        }

        // Cancel active bookings
        $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' 
                            WHERE user_id = ? AND status IN ('pending', 'confirmed')");
        $stmt->execute([$userId]);

        // Mark user's properties as deleted if they're an owner
        if ($user['status'] === 'property_owner') {
            $stmt = $db->prepare("UPDATE property SET deleted = 1 WHERE owner_id = ?");
            $stmt->execute([$userId]);
        }

        // Delete profile picture if exists
        if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/../../' . $user['profile_picture'])) {
            unlink(__DIR__ . '/../../' . $user['profile_picture']);
        }

        // Log the admin action
        $action = "Deleted user: " . $user['username'] . " (ID: $userId)";
        $stmt = $db->prepare("INSERT INTO admin_actions (admin_id, action_type, target_id, target_type, details) 
                             VALUES (?, 'delete', ?, 'user', ?)");
        $stmt->execute([$adminId, $userId, $action]);

        // Commit transaction
        $db->commit();

        $success = "User '{$user['username']}' has been successfully deleted.";
        $confirmation = false;

    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error deleting user: " . $e->getMessage();
    }
} elseif ($userId > 0) {
    // Get user details for confirmation
    try {
        $stmt = $db->prepare("SELECT username, status, profile_picture FROM users WHERE id = ? AND deleted = 0");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $username = $user['username'] ?? '';
            $userStatus = $user['status'] ?? '';
            $profilePicture = $user['profile_picture'] ?? '';
            $confirmation = true;
        } else {
            $error = "User not found or already deleted";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "Invalid user ID";
}

// Generate avatar initials
$userInitial = isset($username) ? strtoupper(substr($username, 0, 1)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete User | Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #3f37c9;
            --accent: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #ef233c;
            --white: #ffffff;
            
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 1rem;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1), 0 5px 10px rgba(0,0,0,0.05);
            
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .delete-card {
            background-color: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 2rem;
            margin: 0 auto;
            max-width: 600px;
        }

        h1 {
            font-size: 1.75rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background-color: rgba(239, 35, 60, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background-color: var(--light);
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--danger);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--light-gray);
        }

        .avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            font-size: 2rem;
            font-weight: bold;
            border: 3px solid var(--light-gray);
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--danger);
            margin-bottom: 0.25rem;
        }

        .user-meta {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .warning-card {
            background-color: rgba(248, 150, 30, 0.1);
            border-left: 4px solid var(--warning);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
        }

        .warning-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .warning-icon {
            color: var(--warning);
            font-size: 1.25rem;
        }

        .warning-list {
            margin-top: 0.75rem;
            padding-left: 1.5rem;
        }

        .warning-list li {
            margin-bottom: 0.5rem;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .btn-danger {
            background-color: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background-color: #d11a2a;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background-color: var(--secondary);
            color: var(--white);
        }

        .btn-secondary:hover {
            background-color: #2a2d7a;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary);
            color: var(--secondary);
        }

        .btn-outline:hover {
            background-color: rgba(63, 55, 201, 0.1);
        }

        .hidden {
            display: none;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .delete-card {
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .user-card {
                flex-direction: column;
                text-align: center;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="delete-card">
            <h1><i class="fas fa-trash-alt"></i> Delete User</h1>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            <?php elseif (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            <?php elseif ($confirmation): ?>
                <div class="user-card">
                    <?php if (!empty($profilePicture)): ?>
                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" 
                             alt="Profile Picture" 
                             class="profile-avatar"
                             onerror="this.onerror=null; this.classList.add('hidden'); document.getElementById('avatar-initials').classList.remove('hidden');">
                        <div id="avatar-initials" class="avatar-initials hidden"><?php echo $userInitial; ?></div>
                    <?php else: ?>
                        <div class="avatar-initials"><?php echo $userInitial; ?></div>
                    <?php endif; ?>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                        <div class="user-meta">ID: <?php echo $userId; ?></div>
                        <div class="user-meta">Status: <?php echo ucfirst(str_replace('_', ' ', $userStatus)); ?></div>
                    </div>
                </div>
                
                <div class="warning-card">
                    <div class="warning-title">
                        <i class="fas fa-exclamation-triangle warning-icon"></i>
                        <span>Warning: This action cannot be undone</span>
                    </div>
                    <p>Deleting this user will also:</p>
                    <ul class="warning-list">
                        <li>Cancel all their active bookings</li>
                        <?php if ($userStatus === 'property_owner'): ?>
                            <li>Mark all their properties as deleted</li>
                        <?php endif; ?>
                        <li>Remove their access to the system</li>
                        <li>Delete their profile picture</li>
                    </ul>
                </div>
                
                <form method="POST" action="delete.php?id=<?php echo $userId; ?>">
                    <div class="btn-group">
                        <button type="submit" name="confirm_delete" class="btn btn-danger">
                            <i class="fas fa-trash-alt"></i> Confirm Delete
                        </button>
                        <a href="index.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    No user specified for deletion.
                </div>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Handle avatar image error by showing initials
        document.addEventListener('DOMContentLoaded', function() {
            const avatar = document.querySelector('.profile-avatar');
            if (avatar) {
                avatar.onerror = function() {
                    this.classList.add('hidden');
                    const initials = document.getElementById('avatar-initials');
                    if (initials) initials.classList.remove('hidden');
                };
            }
        });
    </script>
</body>
</html>