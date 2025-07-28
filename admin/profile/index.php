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
$username = $_SESSION['username'] ?? 'User';
$email = $_SESSION['email'] ?? '';
$avatar = $_SESSION['avatar'] ?? 'https://randomuser.me/api/portraits/men/32.jpg';
$status = $_SESSION['status'] ?? 'student';

// Fetch additional user details from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
    // Get user bookings
    $bookings = [];
    $stmt = $pdo->prepare("
        SELECT b.*, p.property_name, p.location as property_location, 
               pr.room_number, b.status as booking_status
        FROM bookings b
        JOIN property p ON b.property_id = p.id
        LEFT JOIN property_rooms pr ON b.room_id = pr.id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment history
    $payments = [];
    $stmt = $pdo->prepare("
        SELECT p.*, b.property_id, prop.property_name
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN property prop ON b.property_id = prop.id
        WHERE b.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get reviews
    $reviews = [];
    $stmt = $pdo->prepare("
        SELECT r.*, p.property_name
        FROM reviews r
        JOIN property p ON r.property_id = p.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get credit score history
    $creditHistory = [];
    $stmt = $pdo->prepare("
        SELECT * FROM credit_score_history
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $creditHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "Failed to load user data. Please try again later.";
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $error = $e->getMessage();
}

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $newUsername = $_POST['username'] ?? $username;
        $newEmail = $_POST['email'] ?? $email;
        $phone = $_POST['phone'] ?? $user['phone_number'];
        $location = $_POST['location'] ?? $user['location'];
        $notifications = isset($_POST['email_notifications']) ? 1 : 0;
        
        // Handle file upload
        $avatarPath = $avatar;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/avatars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileExt = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $fileName = 'user_' . $user_id . '_' . time() . '.' . $fileExt;
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $avatarPath = str_replace('../', '', $targetPath);
                
                // Delete old avatar if it's not the default
                if ($avatar && strpos($avatar, 'randomuser.me') === false) {
                    $oldPath = '../' . $avatar;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
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
        $_SESSION['avatar'] = $avatarPath;
        
        $success = "Profile updated successfully!";
        header("Refresh:1"); // Refresh to show updated data
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $error = "Failed to update profile. Please try again.";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Verify current password
        $stmt = $pdo->prepare("SELECT pwd FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $dbPassword = $stmt->fetchColumn();
        
        if (!password_verify($currentPassword, $dbPassword)) {
            $error = "Current password is incorrect.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "New passwords do not match.";
        } elseif (strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET pwd = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $user_id]);
            
            $success = "Password changed successfully!";
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $error = "Failed to change password. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/profile.css">
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
        
        .credit-score {
            margin-top: 20px;
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            border-radius: 8px;
        }
        
        .credit-score h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: var(--gray-dark);
        }
        
        .score-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .score-label {
            font-size: 12px;
            color: var(--gray);
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
        
        .avatar-upload {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 10px;
            border: 2px solid var(--gray-light);
        }
        
        .avatar-upload label {
            cursor: pointer;
            color: var(--primary-color);
            font-weight: 500;
            font-size: 14px;
        }
        
        .avatar-upload input[type="file"] {
            display: none;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray-light);
        }
        
        th {
            background-color: var(--light-color);
            font-weight: 600;
            color: var(--gray-dark);
        }
        
        tr:hover {
            background-color: rgba(74, 107, 255, 0.05);
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-warning {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }
        
        .badge-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .badge-info {
            background-color: var(--info-color);
            color: white;
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
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            color: var(--gray-light);
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            font-weight: 500;
        }
        
        .empty-state p {
            margin-bottom: 20px;
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
            
            th, td {
                padding: 8px 10px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>My Profile</h1>
            <a href="../dashboard.php" class="btn btn-outline">Back to Home</a>
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
                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Profile Avatar" class="profile-avatar" id="avatarPreview">
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
                
                <div class="credit-score">
                    <h3>Credit Score</h3>
                    <div class="score-value"><?php echo number_format($user['credit_score'] ?? 100, 1); ?></div>
                    <div class="score-label">Good Standing</div>
                </div>
            </div>
            
            <!-- Profile Content -->
            <div class="profile-content">
                <div class="nav-tabs">
                    <div class="nav-tab active" data-tab="profile">Profile</div>
                    <div class="nav-tab" data-tab="bookings">My Bookings</div>
                    <div class="nav-tab" data-tab="payments">Payments</div>
                    <div class="nav-tab" data-tab="reviews">My Reviews</div>
                    <div class="nav-tab" data-tab="security">Security</div>
                </div>
                
                <!-- Profile Tab -->
                <div class="tab-content active" id="profileTab">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="avatar-upload">
                            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar Preview" class="avatar-preview" id="avatarPreview">
                            <label for="avatarUpload">
                                <i class="fas fa-camera"></i> Change Photo
                            </label>
                            <input type="file" id="avatarUpload" name="avatar" accept="image/*">
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Location</label>
                            <input type="text" id="location" name="location" class="form-control" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="email_notifications" <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>> 
                                Receive email notifications
                            </label>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
                
                <!-- Bookings Tab -->
                <div class="tab-content" id="bookingsTab">
                    <?php if (!empty($bookings)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Room</th>
                                        <th>Dates</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($booking['property_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($booking['property_location']); ?></small>
                                        </td>
                                        <td><?php echo $booking['room_number'] ? htmlspecialchars($booking['room_number']) : 'N/A'; ?></td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($booking['start_date'])); ?> - 
                                            <?php echo date('M j, Y', strtotime($booking['end_date'])); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $badgeClass = '';
                                            if ($booking['booking_status'] === 'confirmed') $badgeClass = 'badge-success';
                                            elseif ($booking['booking_status'] === 'pending') $badgeClass = 'badge-warning';
                                            elseif ($booking['booking_status'] === 'cancelled') $badgeClass = 'badge-danger';
                                            elseif ($booking['booking_status'] === 'paid') $badgeClass = 'badge-info';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo ucfirst($booking['booking_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../property.php?id=<?php echo $booking['property_id']; ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 14px;">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="../bookings/" class="btn btn-primary">View All Bookings</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Bookings Yet</h3>
                            <p>You haven't made any bookings yet. Start by exploring our properties.</p>
                            <a href="../properties/" class="btn btn-primary">Browse Properties</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Payments Tab -->
                <div class="tab-content" id="paymentsTab">
                    <?php if (!empty($payments)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['property_name']); ?></td>
                                        <td>GH₵<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $payment['status'] === 'completed' ? 'badge-success' : ($payment['status'] === 'pending' ? 'badge-warning' : 'badge-danger'); ?>">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="../payments/" class="btn btn-primary">View All Payments</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-wallet"></i>
                            <h3>No Payment History</h3>
                            <p>You haven't made any payments yet. Your payment history will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Reviews Tab -->
                <div class="tab-content" id="reviewsTab">
                    <?php if (!empty($reviews)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Rating</th>
                                        <th>Comment</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($review['property_name']); ?></td>
                                        <td>
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star" style="color: <?php echo $i <= $review['rating'] ? '#ffc107' : '#e4e5e9'; ?>"></i>
                                            <?php endfor; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($review['comment'], 0, 50) ). (strlen($review['comment']) > 50 ? '...' : ''); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($review['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="../reviews/" class="btn btn-primary">View All Reviews</a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-alt"></i>
                            <h3>No Reviews Yet</h3>
                            <p>You haven't reviewed any properties yet. Your reviews will appear here.</p>
                            <a href="../properties/" class="btn btn-primary">Browse Properties</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Security Tab -->
                <div class="tab-content" id="securityTab">
                    <form method="POST">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>
                        
                        <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                    </form>
                    
                    <div style="margin-top: 30px;">
                        <h3>Credit Score History</h3>
                        <?php if (!empty($creditHistory)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Change</th>
                                            <th>New Score</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($creditHistory as $history): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($history['created_at'])); ?></td>
                                            <td style="color: <?php echo $history['score_change'] >= 0 ? 'green' : 'red'; ?>">
                                                <?php echo ($history['score_change'] >= 0 ? '+' : '') . $history['score_change']; ?>
                                            </td>
                                            <td><?php echo $history['new_score']; ?></td>
                                            <td><?php echo htmlspecialchars($history['reason']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>No credit score changes recorded yet.</p>
                        <?php endif; ?>
                    </div>
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
        
        // Avatar preview
        document.getElementById('avatarUpload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>