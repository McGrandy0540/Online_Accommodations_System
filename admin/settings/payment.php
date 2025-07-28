<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once __DIR__ . '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Initialize payment settings with defaults
$defaultSettings = [
    'currency' => 'GHS',
    'paystack_enabled' => '0',
    'paystack_test_mode' => '1',
    'paystack_test_secret_key' => '',
    'paystack_test_public_key' => '',
    'paystack_live_secret_key' => '',
    'paystack_live_public_key' => '',
    'deposit_percentage' => '20',
    'cancellation_policy' => 'flexible',
    'paystack_bank_transfer_enabled' => '1',
    'paystack_mobile_money_enabled' => '1'
];

// Load current settings from database
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_type = 'payment'");
    $stmt->execute();
    $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if ($dbSettings) {
        $settings = array_merge($defaultSettings, $dbSettings);
    } else {
        $settings = $defaultSettings;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading payment settings: " . $e->getMessage();
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
                case 'currency':
                    $allowedCurrencies = ['GHS', 'USD'];
                    $value = in_array($value, $allowedCurrencies) ? $value : 'GHS';
                    break;
                    
                case 'deposit_percentage':
                    $value = max(0, min(100, (int)$value));
                    break;
                    
                case 'paystack_enabled':
                case 'paystack_test_mode':
                case 'paystack_bank_transfer_enabled':
                case 'paystack_mobile_money_enabled':
                    $value = isset($_POST[$key]) ? '1' : '0';
                    break;
                    
                case 'paystack_test_secret_key':
                case 'paystack_test_public_key':
                case 'paystack_live_secret_key':
                case 'paystack_live_public_key':
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
                                  VALUES ('payment', :key, :value, :admin_id)
                                  ON DUPLICATE KEY UPDATE setting_value = :value, updated_by = :admin_id");
            $stmt->execute([
                ':key' => $key, 
                ':value' => $value,
                ':admin_id' => $_SESSION['user_id']
            ]);
        }
        
        // Log admin action
        $actionStmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, action_type, target_type, details) 
                                    VALUES (:admin_id, 'update_settings', 'payment', 'Updated payment settings')");
        $actionStmt->execute([':admin_id' => $_SESSION['user_id']]);
        
        $pdo->commit();
        $_SESSION['success'] = "Payment settings updated successfully";
        
        // Refresh settings from DB
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_type = 'payment'");
        $stmt->execute();
        $settings = array_merge($defaultSettings, $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error updating payment settings: " . $e->getMessage();
    }
    
    header("Location: payment.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings - Accommodation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0ab9f0;
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
            --paystack-blue: #0ab9f0;
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
            box-shadow: 0 0 0 2px rgba(10, 185, 240, 0.2);
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

        /* Payment methods */
        .payment-method {
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 20px;
            background-color: white;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .payment-method.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 1px var(--primary-color);
        }

        .payment-method h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .method-icon {
            font-size: 1.5rem;
            color: var(--paystack-blue);
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

        /* Test mode indicator */
        .test-mode {
            display: inline-block;
            padding: 3px 8px;
            background-color: var(--warning-color);
            color: #000;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 10px;
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

        /* Payment channels */
        .payment-channels {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .payment-channel {
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

            .payment-channels {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .card-body {
                padding: 15px;
            }

            .payment-method {
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
                    <li>Payment Settings</li>
                </ul>
            </div>

            <!-- Settings Navigation -->
            <div class="card">
                <div class="card-body">
                    <div class="settings-tabs">
                        <a href="general.php" class="btn btn-outline">General</a>
                        <a href="payment.php" class="btn btn-primary active">Payment</a>
                        <a href="notifications.php" class="btn btn-outline">Notifications</a>
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

            <!-- Payment Settings Form -->
            <form method="post" action="payment.php">
                <div class="card">
                    <div class="card-header">
                        <h2>Payment Settings</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-section">
                            <h3>Currency Settings</h3>
                            <div class="form-group">
                                <label for="currency">Default Currency</label>
                                <select id="currency" name="currency" class="form-control" required>
                                    <option value="GHS" <?= $settings['currency'] === 'GHS' ? 'selected' : '' ?>>Ghanaian Cedi (GHS)</option>
                                    <option value="USD" <?= $settings['currency'] === 'USD' ? 'selected' : '' ?>>US Dollar (USD)</option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Paystack Integration</h3>
                            <div class="payment-method <?= $settings['paystack_enabled'] === '1' ? 'active' : '' ?>">
                                <h4>
                                    <i class="fas fa-credit-card method-icon"></i>
                                    Paystack
                                    <span class="test-mode"><?= $settings['paystack_test_mode'] === '1' ? 'Test Mode' : 'Live Mode' ?></span>
                                </h4>
                                <div class="form-group">
                                    <label class="toggle-label">
                                        <span class="toggle-switch">
                                            <input type="checkbox" name="paystack_enabled" value="1" <?= $settings['paystack_enabled'] === '1' ? 'checked' : '' ?>>
                                            <span class="toggle-slider"></span>
                                        </span>
                                        Enable Paystack Payments
                                    </label>
                                </div>
                                
                                <div class="form-group">
                                    <label class="toggle-label">
                                        <span class="toggle-switch">
                                            <input type="checkbox" name="paystack_test_mode" value="1" <?= $settings['paystack_test_mode'] === '1' ? 'checked' : '' ?>>
                                            <span class="toggle-slider"></span>
                                        </span>
                                        Test Mode
                                    </label>
                                </div>
                                
                                <div class="form-group">
                                    <label for="paystack_test_public_key">Test Public Key</label>
                                    <input type="text" id="paystack_test_public_key" name="paystack_test_public_key" class="form-control" 
                                           value="<?= htmlspecialchars($settings['paystack_test_public_key']) ?>" 
                                           placeholder="pk_test_...">
                                </div>
                                
                                <div class="form-group">
                                    <label for="paystack_test_secret_key">Test Secret Key</label>
                                    <input type="password" id="paystack_test_secret_key" name="paystack_test_secret_key" class="form-control" 
                                           value="<?= htmlspecialchars($settings['paystack_test_secret_key']) ?>" 
                                           placeholder="sk_test_...">
                                    <small class="text-muted">Leave blank to keep current value</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="paystack_live_public_key">Live Public Key</label>
                                    <input type="text" id="paystack_live_public_key" name="paystack_live_public_key" class="form-control" 
                                           value="<?= htmlspecialchars($settings['paystack_live_public_key']) ?>" 
                                           placeholder="pk_live_...">
                                </div>
                                
                                <div class="form-group">
                                    <label for="paystack_live_secret_key">Live Secret Key</label>
                                    <input type="password" id="paystack_live_secret_key" name="paystack_live_secret_key" class="form-control" 
                                           value="<?= htmlspecialchars($settings['paystack_live_secret_key']) ?>" 
                                           placeholder="sk_live_...">
                                    <small class="text-muted">Leave blank to keep current value</small>
                                </div>
                                
                                <div class="payment-channels">
                                    <div class="payment-channel">
                                        <div class="form-group">
                                            <label class="toggle-label">
                                                <span class="toggle-switch">
                                                    <input type="checkbox" name="paystack_bank_transfer_enabled" value="1" <?= $settings['paystack_bank_transfer_enabled'] === '1' ? 'checked' : '' ?>>
                                                    <span class="toggle-slider"></span>
                                                </span>
                                                Enable Bank Transfers
                                            </label>
                                        </div>
                                    </div>
                                    <div class="payment-channel">
                                        <div class="form-group">
                                            <label class="toggle-label">
                                                <span class="toggle-switch">
                                                    <input type="checkbox" name="paystack_mobile_money_enabled" value="1" <?= $settings['paystack_mobile_money_enabled'] === '1' ? 'checked' : '' ?>>
                                                    <span class="toggle-slider"></span>
                                                </span>
                                                Enable Mobile Money
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Payment Policies</h3>
                            <div class="form-group">
                                <label for="deposit_percentage">Deposit Percentage</label>
                                <div class="input-group">
                                    <input type="number" id="deposit_percentage" name="deposit_percentage" class="form-control" 
                                           value="<?= htmlspecialchars($settings['deposit_percentage']) ?>" 
                                           min="0" max="100" step="1" required>
                                    <span class="input-group-text">%</span>
                                </div>
                                <small>The percentage of total amount required as deposit at booking time</small>
                            </div>
                            <div class="form-group">
                                <label for="cancellation_policy">Cancellation Policy</label>
                                <select id="cancellation_policy" name="cancellation_policy" class="form-control" required>
                                    <option value="flexible" <?= $settings['cancellation_policy'] === 'flexible' ? 'selected' : '' ?>>Flexible - Full refund if canceled at least 24 hours before check-in</option>
                                    <option value="moderate" <?= $settings['cancellation_policy'] === 'moderate' ? 'selected' : '' ?>>Moderate - 50% refund if canceled at least 5 days before check-in</option>
                                    <option value="strict" <?= $settings['cancellation_policy'] === 'strict' ? 'selected' : '' ?>>Strict - No refund for cancellations</option>
                                </select>
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

        // Toggle payment method active state when checkbox changes
        document.querySelectorAll('.payment-method input[type="checkbox"]').forEach(checkbox => {
            // Set initial state
            const methodCard = checkbox.closest('.payment-method');
            if (checkbox.checked) {
                methodCard.classList.add('active');
            } else {
                methodCard.classList.remove('active');
            }

            // Add change listener
            checkbox.addEventListener('change', function() {
                this.closest('.payment-method').classList.toggle('active', this.checked);
            });
        });

        // Update test mode indicator
        document.querySelector('input[name="paystack_test_mode"]').addEventListener('change', function() {
            const testModeIndicator = document.querySelector('.payment-method .test-mode');
            testModeIndicator.textContent = this.checked ? 'Test Mode' : 'Live Mode';
        });
    </script>
</body>
</html>