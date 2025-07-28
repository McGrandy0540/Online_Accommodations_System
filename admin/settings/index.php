<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Initialize settings with defaults
$settings = [
    'site_name' => 'Online Accommodation System',
    'site_email' => 'admin@accommodation.example.com',
    'timezone' => 'UTC',
    'min_booking_days' => '1',
    'max_booking_days' => '365',
    'maintenance_mode' => '0',
    'maintenance_message' => 'We are currently performing maintenance. Please check back later.',
    'default_credit_score' => '100.00'
];

// Try to load settings from database
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_type = 'general'");
    $stmt->execute();
    $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if ($dbSettings) {
        $settings = array_merge($settings, $dbSettings);
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading settings: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST as $key => $value) {
            $value = is_array($value) ? implode(',', $value) : $value;
            $stmt = $pdo->prepare("INSERT INTO settings (setting_type, setting_key, setting_value, updated_by) 
                                  VALUES ('general', :key, :value, :admin_id)
                                  ON DUPLICATE KEY UPDATE setting_value = :value, updated_by = :admin_id");
            $stmt->execute([
                ':key' => $key, 
                ':value' => $value,
                ':admin_id' => $_SESSION['user_id']
            ]);
        }
        
        // Log this admin action
        $actionStmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, action_type, target_type, details) 
                                    VALUES (:admin_id, 'update_settings', 'system', 'Updated general settings')");
        $actionStmt->execute([':admin_id' => $_SESSION['user_id']]);
        
        $pdo->commit();
        $_SESSION['success'] = "General settings updated successfully";
        header("Location: general.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error updating settings: " . $e->getMessage();
        header("Location: general.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Settings - Accommodation Admin</title>
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

        .page-title h1 {
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

        .form-group {
            margin-bottom: 20px;
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

        .btn {
            padding: 10px 20px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

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

        .settings-section {
            margin-bottom: 30px;
        }

        .settings-section h3 {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: var(--secondary-color);
        }

        .settings-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--secondary-color);
        }

        @media (max-width: 992px) {
            .menu-toggle {
                display: block;
            }
        }

        .user-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }

        .input-group {
            display: flex;
            align-items: center;
        }

        .input-group .form-control {
            flex: 1;
        }

        .input-group-text {
            padding: 10px;
            background-color: #eee;
            border: 1px solid #ddd;
            border-left: none;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
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
                    <li><a href="general.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
                                       <li>
                        <form action="../logout.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                         <button type="submit" class="dropdown-item">
                           <i class="fas fa-sign-out-alt "></i> Logout
                         </button>
                       </form>
                    </li>
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
                    <li>General Settings</li>
                </ul>
            </div>

            <!-- Settings Navigation -->
            <div class="card">
                <div class="card-body">
                    <div class="settings-tabs">
                        <a href="general.php" class="btn btn-primary active">General</a>
                        <a href="payment.php" class="btn btn-outline">Payment</a>
                        <a href="notifications.php" class="btn btn-outline">Notifications</a>
                    </div>
                </div>
            </div>

            <!-- Display messages -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- General Settings Form -->
            <form method="post" action="general.php">
                <div class="card">
                    <div class="card-header">
                        <h2>General Settings</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-section">
                            <h3>System Information</h3>
                            <div class="form-group">
                                <label for="site_name">Site Name</label>
                                <input type="text" id="site_name" name="site_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="site_email">Site Email</label>
                                <input type="email" id="site_email" name="site_email" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['site_email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="timezone">Timezone</label>
                                <select id="timezone" name="timezone" class="form-control" required>
                                    <?php
                                    $timezones = DateTimeZone::listIdentifiers();
                                    foreach ($timezones as $tz) {
                                        $selected = $settings['timezone'] === $tz ? 'selected' : '';
                                        echo "<option value=\"$tz\" $selected>$tz</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Booking Settings</h3>
                            <div class="form-group">
                                <label for="min_booking_days">Minimum Booking Days</label>
                                <input type="number" id="min_booking_days" name="min_booking_days" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['min_booking_days']); ?>" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="max_booking_days">Maximum Booking Days</label>
                                <input type="number" id="max_booking_days" name="max_booking_days" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['max_booking_days']); ?>" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="default_credit_score">Default Credit Score</label>
                                <div class="input-group">
                                    <input type="number" id="default_credit_score" name="default_credit_score" class="form-control" 
                                           value="<?php echo htmlspecialchars($settings['default_credit_score']); ?>" min="0" max="100" step="0.01" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Maintenance Mode</h3>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="maintenance_mode" value="1" 
                                        <?php echo $settings['maintenance_mode'] === '1' ? 'checked' : ''; ?>>
                                    Enable Maintenance Mode
                                </label>
                            </div>
                            <div class="form-group">
                                <label for="maintenance_message">Maintenance Message</label>
                                <textarea id="maintenance_message" name="maintenance_message" class="form-control" 
                                          rows="3" required><?php echo htmlspecialchars($settings['maintenance_message']); ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save Settings</button>
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
    </script>
</body>
</html>