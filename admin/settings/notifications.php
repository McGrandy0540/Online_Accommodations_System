<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Initialize notification settings with defaults
$defaultSettings = [
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'from_email' => 'noreply@example.com',
    'from_name' => 'Accommodation System',
    'email_notifications_enabled' => '1',
    'sms_notifications_enabled' => '0',
    'sms_provider' => 'twilio',
    'twilio_sid' => '',
    'twilio_token' => '',
    'twilio_from_number' => '',
    'push_notifications_enabled' => '0',
    'booking_confirmation_email' => '1',
    'booking_confirmation_sms' => '0',
    'payment_received_email' => '1',
    'payment_received_sms' => '0',
    'booking_reminder_email' => '1',
    'booking_reminder_sms' => '0',
    'admin_notification_email' => 'admin@example.com'
];

// Load current settings from database
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_type = 'notification'");
    $stmt->execute();
    $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if ($dbSettings) {
        $settings = array_merge($defaultSettings, $dbSettings);
    } else {
        $settings = $defaultSettings;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading notification settings: " . $e->getMessage();
    $settings = $defaultSettings;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Process and validate each field
        $validatedData = [];
        foreach ($_POST as $key => $value) {
            // Basic sanitization
            $value = trim($value);
            
            // Special validation for certain fields
            switch ($key) {
                case 'smtp_port':
                    $value = (int)$value;
                    $value = $value > 0 ? $value : 587;
                    break;
                    
                case 'email_notifications_enabled':
                case 'sms_notifications_enabled':
                case 'push_notifications_enabled':
                case 'booking_confirmation_email':
                case 'booking_confirmation_sms':
                case 'payment_received_email':
                case 'payment_received_sms':
                case 'booking_reminder_email':
                case 'booking_reminder_sms':
                    $value = isset($_POST[$key]) ? '1' : '0';
                    break;
                    
                case 'smtp_password':
                case 'twilio_token':
                    // Don't update if empty (to prevent overwriting with blank)
                    if ($value === '' && isset($settings[$key]) && $settings[$key] !== '') {
                        continue 2; // Skip this iteration
                    }
                    break;
            }
            
            $validatedData[$key] = $value;
        }
        
        // Save to database
        foreach ($validatedData as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_type, setting_key, setting_value, updated_by) 
                                  VALUES ('notification', :key, :value, :admin_id)
                                  ON DUPLICATE KEY UPDATE setting_value = :value, updated_by = :admin_id");
            $stmt->execute([
                ':key' => $key, 
                ':value' => $value,
                ':admin_id' => $_SESSION['user_id']
            ]);
        }
        
        // Log admin action
        $actionStmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, action_type, target_type, details) 
                                    VALUES (:admin_id, 'update_settings', 'notification', 'Updated notification settings')");
        $actionStmt->execute([':admin_id' => $_SESSION['user_id']]);
        
        $pdo->commit();
        $_SESSION['success'] = "Notification settings updated successfully";
        
        // Refresh settings from DB
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_type = 'notification'");
        $stmt->execute();
        $settings = array_merge($defaultSettings, $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error updating notification settings: " . $e->getMessage();
    }
    
    header("Location: notifications.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Settings - Accommodation Admin</title>
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

        /* Admin container and sidebar styles */
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

        /* Top navigation */
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

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary-color);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }

        /* Page header */
        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
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

        /* Card styles */
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
        }

        .card-header h2 {
            font-size: 18px;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 20px;
        }

        /* Settings tabs */
        .settings-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .settings-tabs .btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }

        /* Form elements */
        .settings-section {
            margin-bottom: 30px;
        }

        .settings-section h3 {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: var(--secondary-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-right: 10px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: var(--primary-color);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .toggle-label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        /* Notification types */
        .notification-types {
            margin-top: 20px;
        }

        .notification-type {
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 15px;
            background-color: white;
        }

        .notification-type h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .notification-channels {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .channel-option {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }

        /* Form actions */
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* Alerts */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Test email form */
        .test-email {
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-top: 15px;
        }

        .test-email h5 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .test-email-form {
            display: flex;
            gap: 10px;
        }

        .test-email-form input {
            flex: 1;
        }

        /* Mobile responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .settings-tabs .btn {
                min-width: 100px;
                padding: 8px 12px;
                font-size: 14px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .notification-channels {
                flex-direction: column;
                gap: 8px;
            }

            .test-email-form {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .card-body {
                padding: 15px;
            }

            .notification-type {
                padding: 15px;
            }

            .settings-section h3 {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Accommodation Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../users/"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> Property Management</a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-check"></i> Booking Management</a></li>
                    <li><a href="../approvals/"><i class="fas fa-clock"></i> Approvals</a></li>
                    <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="../reports/"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="general.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                <h1>System Settings</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li><a href="#">Settings</a></li>
                    <li>Notification Settings</li>
                </ul>
            </div>

            <!-- Settings Navigation -->
            <div class="card">
                <div class="card-body">
                    <div class="settings-tabs">
                        <a href="general.php" class="btn btn-outline">General</a>
                        <a href="payment.php" class="btn btn-outline">Payment</a>
                        <a href="notifications.php" class="btn btn-primary active">Notifications</a>
                    </div>
                </div>
            </div>

            <!-- Display messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Notification Settings Form -->
            <form method="post" action="notifications.php">
                <div class="card">
                    <div class="card-header">
                        <h2>Notification Settings</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-section">
                            <h3>Global Notification Settings</h3>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="email_notifications_enabled" value="1" <?= $settings['email_notifications_enabled'] === '1' ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </span>
                                    Enable Email Notifications
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="sms_notifications_enabled" value="1" <?= $settings['sms_notifications_enabled'] === '1' ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </span>
                                    Enable SMS Notifications
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="push_notifications_enabled" value="1" <?= $settings['push_notifications_enabled'] === '1' ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </span>
                                    Enable Push Notifications
                                </label>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Email Configuration</h3>
                            <div class="form-group">
                                <label for="smtp_host">SMTP Host</label>
                                <input type="text" id="smtp_host" name="smtp_host" class="form-control" 
                                       value="<?= htmlspecialchars($settings['smtp_host']) ?>" 
                                       placeholder="smtp.example.com">
                            </div>
                            <div class="form-group">
                                <label for="smtp_port">SMTP Port</label>
                                <input type="number" id="smtp_port" name="smtp_port" class="form-control" 
                                       value="<?= htmlspecialchars($settings['smtp_port']) ?>" 
                                       placeholder="587">
                            </div>
                            <div class="form-group">
                                <label for="smtp_username">SMTP Username</label>
                                <input type="text" id="smtp_username" name="smtp_username" class="form-control" 
                                       value="<?= htmlspecialchars($settings['smtp_username']) ?>" 
                                       placeholder="your@email.com">
                            </div>
                            <div class="form-group">
                                <label for="smtp_password">SMTP Password</label>
                                <input type="password" id="smtp_password" name="smtp_password" class="form-control" 
                                       value="<?= htmlspecialchars($settings['smtp_password']) ?>" 
                                       placeholder="Your SMTP password">
                                <small class="text-muted">Leave blank to keep current password</small>
                            </div>
                            <div class="form-group">
                                <label for="smtp_encryption">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                                    <option value="tls" <?= $settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="" <?= empty($settings['smtp_encryption']) ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="from_email">From Email Address</label>
                                <input type="email" id="from_email" name="from_email" class="form-control" 
                                       value="<?= htmlspecialchars($settings['from_email']) ?>" 
                                       placeholder="noreply@example.com">
                            </div>
                            <div class="form-group">
                                <label for="from_name">From Name</label>
                                <input type="text" id="from_name" name="from_name" class="form-control" 
                                       value="<?= htmlspecialchars($settings['from_name']) ?>" 
                                       placeholder="Accommodation System">
                            </div>
                            
                            <div class="test-email">
                                <h5>Test Email Configuration</h5>
                                <div class="test-email-form">
                                    <input type="email" id="test_email" class="form-control" placeholder="Enter email to send test">
                                    <button type="button" id="send_test_email" class="btn btn-outline">
                                        <i class="fas fa-paper-plane"></i> Send Test
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>SMS Configuration</h3>
                            <div class="form-group">
                                <label for="sms_provider">SMS Provider</label>
                                <select id="sms_provider" name="sms_provider" class="form-control">
                                    <option value="twilio" <?= $settings['sms_provider'] === 'twilio' ? 'selected' : '' ?>>Twilio</option>
                                    <option value="nexmo" <?= $settings['sms_provider'] === 'nexmo' ? 'selected' : '' ?>>Nexmo (Vonage)</option>
                                    <option value="other" <?= $settings['sms_provider'] === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="twilio_sid">Twilio Account SID</label>
                                <input type="text" id="twilio_sid" name="twilio_sid" class="form-control" 
                                       value="<?= htmlspecialchars($settings['twilio_sid']) ?>" 
                                       placeholder="ACxxxxxxxxxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label for="twilio_token">Twilio Auth Token</label>
                                <input type="password" id="twilio_token" name="twilio_token" class="form-control" 
                                       value="<?= htmlspecialchars($settings['twilio_token']) ?>" 
                                       placeholder="Your Twilio token">
                                <small class="text-muted">Leave blank to keep current token</small>
                            </div>
                            <div class="form-group">
                                <label for="twilio_from_number">Twilio From Number</label>
                                <input type="text" id="twilio_from_number" name="twilio_from_number" class="form-control" 
                                       value="<?= htmlspecialchars($settings['twilio_from_number']) ?>" 
                                       placeholder="+1234567890">
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Notification Types</h3>
                            <div class="notification-types">
                                <div class="notification-type">
                                    <h4>Booking Confirmation</h4>
                                    <div class="notification-channels">
                                        <div class="channel-option">
                                            <input type="checkbox" id="booking_confirmation_email" name="booking_confirmation_email" value="1" <?= $settings['booking_confirmation_email'] === '1' ? 'checked' : '' ?>>
                                            <label for="booking_confirmation_email">Email</label>
                                        </div>
                                        <div class="channel-option">
                                            <input type="checkbox" id="booking_confirmation_sms" name="booking_confirmation_sms" value="1" <?= $settings['booking_confirmation_sms'] === '1' ? 'checked' : '' ?>>
                                            <label for="booking_confirmation_sms">SMS</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="notification-type">
                                    <h4>Payment Received</h4>
                                    <div class="notification-channels">
                                        <div class="channel-option">
                                            <input type="checkbox" id="payment_received_email" name="payment_received_email" value="1" <?= $settings['payment_received_email'] === '1' ? 'checked' : '' ?>>
                                            <label for="payment_received_email">Email</label>
                                        </div>
                                        <div class="channel-option">
                                            <input type="checkbox" id="payment_received_sms" name="payment_received_sms" value="1" <?= $settings['payment_received_sms'] === '1' ? 'checked' : '' ?>>
                                            <label for="payment_received_sms">SMS</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="notification-type">
                                    <h4>Booking Reminder</h4>
                                    <div class="notification-channels">
                                        <div class="channel-option">
                                            <input type="checkbox" id="booking_reminder_email" name="booking_reminder_email" value="1" <?= $settings['booking_reminder_email'] === '1' ? 'checked' : '' ?>>
                                            <label for="booking_reminder_email">Email</label>
                                        </div>
                                        <div class="channel-option">
                                            <input type="checkbox" id="booking_reminder_sms" name="booking_reminder_sms" value="1" <?= $settings['booking_reminder_sms'] === '1' ? 'checked' : '' ?>>
                                            <label for="booking_reminder_sms">SMS</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Admin Notifications</h3>
                            <div class="form-group">
                                <label for="admin_notification_email">Admin Notification Email</label>
                                <input type="email" id="admin_notification_email" name="admin_notification_email" class="form-control" 
                                       value="<?= htmlspecialchars($settings['admin_notification_email']) ?>" 
                                       placeholder="admin@example.com">
                                <small>Comma-separate multiple emails</small>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                            <button type="reset" class="btn btn-outline">
                                <i class="fas fa-undo"></i> Reset Changes
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Send test email
        document.getElementById('send_test_email').addEventListener('click', function() {
            const email = document.getElementById('test_email').value;
            if (!email) {
                alert('Please enter an email address');
                return;
            }
            
            // Simple email validation
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address');
                return;
            }
            
            // Disable button during request
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            // Send AJAX request to test email
            fetch('test_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `email=${encodeURIComponent(email)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Test email sent successfully!');
                } else {
                    alert('Error sending test email: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error sending test email: ' + error.message);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Test';
            });
        });

        // Toggle SMS fields based on provider selection
        function toggleSmsFields() {
            const provider = document.getElementById('sms_provider').value;
            const twilioFields = document.querySelectorAll('[id^="twilio_"]');
            
            twilioFields.forEach(field => {
                field.closest('.form-group').style.display = provider === 'twilio' ? 'block' : 'none';
            });
        }

        // Initialize
        toggleSmsFields();
        document.getElementById('sms_provider').addEventListener('change', toggleSmsFields);
    </script>
</body>
</html>