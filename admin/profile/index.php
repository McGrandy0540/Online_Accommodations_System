<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get user data from session and database
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';
$avatar = $_SESSION['avatar'] ?? 'https://randomuser.me/api/portraits/men/32.jpg';
$status = $_SESSION['status'] ?? 'admin';

// Fetch additional user details from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "Failed to load user data. Please try again later.";
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $error = $e->getMessage();
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($user['profile_picture'] ?? '');

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $newUsername = $_POST['username'] ?? $username;
        $newEmail = $_POST['email'] ?? $email;
        $phone = $_POST['phone'] ?? $user['phone_number'];
        $location = $_POST['location'] ?? $user['location'];
        $notifications = isset($_POST['email_notifications']) ? 1 : 0;
        
        // Handle file upload
        $avatarPath = $user['profile_picture'] ?? '';
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../uploads/profile_pictures/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $file = $_FILES['profile_picture'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            
            // Validate file
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Only JPG, JPEG, PNG & GIF files are allowed");
            }
            
            if ($file['size'] > 5000000) { // 5MB max
                throw new Exception("File size must be less than 5MB");
            }
            
            $new_filename = 'admin_' . $user_id . '_' . time() . '.' . $file_ext;
            $destination = $uploadDir . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                // Update database with relative path
                $relative_path = 'uploads/profile_pictures/' . $new_filename;
                $avatarPath = $relative_path;
                
                // Delete old profile picture if it exists and not default
                if (!empty($user['profile_picture']) && strpos($user['profile_picture'], 'randomuser.me') === false) {
                    $oldPath = '../../' . $user['profile_picture'];
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
            } else {
                throw new Exception("Failed to upload file");
            }
        }
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET username = ?, email = ?, phone_number = ?, location = ?, 
                profile_picture = ?, email_notifications = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newUsername, $newEmail, $phone, $location, $avatarPath, $notifications, $user_id]);
        
        // Update session data
        $_SESSION['username'] = $newUsername;
        $_SESSION['email'] = $newEmail;
        $_SESSION['profile_picture'] = $avatarPath;
        
        $success = "Profile updated successfully!";
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile_pic_path = getProfilePicturePath($user['profile_picture'] ?? '');
        
    } catch (Exception $e) {
        error_log("Profile Update Error: " . $e->getMessage());
        $error = "Error updating profile: " . $e->getMessage();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($currentPassword, $user['pwd'])) {
            throw new Exception("Current password is incorrect");
        }
        
        // Validate new password
        if (strlen($newPassword) < 8) {
            throw new Exception("Password must be at least 8 characters long");
        }
        
        if ($newPassword !== $confirmPassword) {
            throw new Exception("New passwords don't match");
        }
        
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET pwd = ? WHERE id = ?")->execute([$hashedPassword, $user_id]);
        
        $success = "Password changed successfully!";
    } catch (Exception $e) {
        $error = "Error changing password: " . $e->getMessage();
    }
}

// Handle notification preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    try {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $sms_booking_updates = isset($_POST['sms_booking_updates']) ? 1 : 0;
        $sms_payment_alerts = isset($_POST['sms_payment_alerts']) ? 1 : 0;
        $sms_maintenance_updates = isset($_POST['sms_maintenance_updates']) ? 1 : 0;
        $sms_announcements = isset($_POST['sms_announcements']) ? 1 : 0;
        
        $pdo->prepare("UPDATE users SET 
                      email_notifications = ?, 
                      sms_notifications = ?,
                      sms_booking_updates = ?,
                      sms_payment_alerts = ?,
                      sms_maintenance_updates = ?,
                      sms_announcements = ?
                      WHERE id = ?")->execute([
                          $email_notifications, 
                          $sms_notifications,
                          $sms_booking_updates,
                          $sms_payment_alerts,
                          $sms_maintenance_updates,
                          $sms_announcements,
                          $user_id
                      ]);
        
        // Update session
        $_SESSION['email_notifications'] = $email_notifications;
        $_SESSION['sms_notifications'] = $sms_notifications;
        
        $success = "Notification preferences updated successfully!";
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = "Error updating notification preferences: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4a6bff;
            --secondary-color: #3a4b8a;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --white: #ffffff;
            --gray-light: #e9ecef;
            --gray: #6c757d;
            --gray-dark: #495057;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: var(--secondary-color);
            font-size: 28px;
        }
        
        .profile-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }
        
        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
        
        .profile-sidebar {
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
            height: fit-content;
        }
        
        .profile-content {
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color);
            margin-bottom: 15px;
        }
        
        .profile-name {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .profile-email {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .profile-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background-color: var(--primary-color);
            color: white;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .profile-details {
            margin-top: 20px;
        }
        
        .detail-item {
            display: flex;
            margin-bottom: 15px;
        }
        
        .detail-icon {
            width: 30px;
            color: var(--primary-color);
            font-size: 16px;
        }
        
        .detail-content h4 {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 3px;
        }
        
        .detail-content p {
            font-size: 15px;
            font-weight: 500;
        }
        
        .nav-tabs {
            display: flex;
            border-bottom: 1px solid var(--gray-light);
            margin-bottom: 20px;
        }
        
        .nav-tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .nav-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-dark);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 5px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
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
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #3a56e8;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .profile-picture-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }
        
        .profile-picture-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            border: 5px solid white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid var(--danger-color);
            color: var(--danger-color);
        }
        
        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.15rem;
        }
        
        .form-check-label {
            margin-left: 0.5rem;
        }
        
        .notification-section {
            background: var(--light-color);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .notification-section h4 {
            margin-bottom: 15px;
            color: var(--secondary-color);
        }
        
        @media (max-width: 576px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .nav-tab {
                padding: 8px 12px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Admin Profile</h1>
            <a href="../dashboard.php" class="btn btn-outline">Back to Dashboard</a>
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
        
        <div class="profile-container">
            <!-- Profile Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-header">
                    <?php if (!empty($profile_pic_path)): ?>
                        <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile Picture" class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-picture-placeholder">
                            <?= substr($user['username'], 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <h2 class="profile-name"><?php echo htmlspecialchars($username); ?></h2>
                    <p class="profile-email"><?php echo htmlspecialchars($email); ?></p>
                    <span class="profile-status"><?php echo htmlspecialchars($status); ?></span>
                </div>
                
                <div class="profile-details">
                    <div class="detail-item">
                        <div class="detail-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="detail-content">
                            <h4>Phone</h4>
                            <p><?php echo htmlspecialchars($user['phone_number'] ?? 'Not set'); ?></p>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="detail-content">
                            <h4>Location</h4>
                            <p><?php echo htmlspecialchars($user['location'] ?? 'Not set'); ?></p>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon">
                            <i class="fas fa-user-tag"></i>
                        </div>
                        <div class="detail-content">
                            <h4>Account Type</h4>
                            <p><?php echo ucfirst(htmlspecialchars($status)); ?></p>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="detail-content">
                            <h4>Member Since</h4>
                            <p><?php echo date('M Y', strtotime($user['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Profile Content -->
            <div class="profile-content">
                <div class="nav-tabs">
                    <div class="nav-tab active" data-tab="profile">Profile</div>
                    <div class="nav-tab" data-tab="security">Password</div>
                    <div class="nav-tab" data-tab="notifications">Notifications</div>
                </div>
                
                <!-- Profile Tab -->
                <div class="tab-content active" id="profileTab">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="profile-picture-container">
                            <?php if (!empty($profile_pic_path)): ?>
                                <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile Picture" class="profile-picture" id="profilePicturePreview">
                            <?php else: ?>
                                <div class="profile-picture-placeholder" id="profilePicturePreview">
                                    <?= substr($user['username'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-3 text-center">
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*" style="display: none;">
                                <button type="button" class="btn btn-outline" onclick="document.getElementById('profile_picture').click()">
                                    <i class="fas fa-camera me-2"></i>Change Photo
                                </button>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="location">Location</label>
                                    <input type="text" id="location" name="location" class="form-control" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Security Tab -->
                <div class="tab-content" id="securityTab">
                    <form method="POST">
                        <input type="hidden" name="change_password" value="1">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                            <small class="text-muted">Password must be at least 8 characters long</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Notifications Tab -->
                <div class="tab-content" id="notificationsTab">
                    <form method="POST">
                        <input type="hidden" name="update_notifications" value="1">
                        
                        <div class="notification-section">
                            <h4>Email Notifications</h4>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" <?= $user['email_notifications'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="email_notifications">Enable email notifications</label>
                            </div>
                        </div>
                        
                        <div class="notification-section">
                            <h4>SMS Notifications</h4>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_notifications" name="sms_notifications" <?= $user['sms_notifications'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_notifications">Enable SMS notifications</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_booking_updates" name="sms_booking_updates" <?= $user['sms_booking_updates'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_booking_updates">Booking updates</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_payment_alerts" name="sms_payment_alerts" <?= $user['sms_payment_alerts'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_payment_alerts">Payment alerts</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_maintenance_updates" name="sms_maintenance_updates" <?= $user['sms_maintenance_updates'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_maintenance_updates">Maintenance updates</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sms_announcements" name="sms_announcements" <?= $user['sms_announcements'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sms_announcements">Announcements</label>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-bell me-2"></i>Save Preferences
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and content
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(tabId + 'Tab').classList.add('active');
            });
        });
        
        // Profile picture preview
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('profilePicturePreview');
                    if (preview.tagName === 'IMG') {
                        preview.src = e.target.result;
                    } else {
                        // Replace placeholder with image
                        const newImg = document.createElement('img');
                        newImg.src = e.target.result;
                        newImg.className = 'profile-picture';
                        newImg.id = 'profilePicturePreview';
                        newImg.alt = 'Profile Picture';
                        preview.parentNode.replaceChild(newImg, preview);
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>