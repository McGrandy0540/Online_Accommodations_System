<?php
ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include required files
require_once __DIR__ .  '../../../config/database.php';
require_once __DIR__ .'../../../includes/SMSService.php';

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $dashboard = match($_SESSION['status']) {
        'student' => '../user/dashboard.php',
        'property_owner' => '../owner/dashboard.php',
        'admin' => '../admin/dashboard.php',
        default => '../index.php'
    };
    header("Location: $dashboard");
    exit();
}

// Initialize variables
$email = '';
$error = '';
$success = '';
$step = $_POST['step'] ?? $_GET['step'] ?? 'request';

// Handle AJAX requests
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'send_otp') {
        $phoneNumber = $_POST['phone_number'] ?? '';
        
        if (empty($phoneNumber)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }
        
        try {
            // Generate 6-digit OTP locally
            $otpCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            
            // Store OTP in database
            $db = Database::getInstance();
            $otpQuery = "INSERT INTO otp_verifications 
                        (phone_number, otp_code, purpose, user_id, expires_at) 
                        VALUES (:phone_number, :otp_code, 'password_reset', :user_id, DATE_ADD(NOW(), INTERVAL 10 MINUTE))";
            $otpStmt = $db->prepare($otpQuery);
            $otpStmt->bindParam(':phone_number', $phoneNumber);
            $otpStmt->bindParam(':otp_code', $otpCode);
            $otpStmt->bindParam(':user_id', $_SESSION['password_reset_user']['id']);
            $otpStmt->execute();
            
            $otpId = $db->lastInsertId();
            
            // Update session with new OTP ID
            $_SESSION['password_reset_user']['otp_id'] = $otpId;
            
            // Store OTP in session for verification
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            // Send OTP via Arkesel SMS
            $smsService = new SMSService();
            $message = "Your Landlords&Tenants password reset OTP is: {$otpCode}. Valid for 10 minutes. Do not share this code.";

            $smsResult = $smsService->sendSMS($phoneNumber, $message);
            
            if ($smsResult) {
                echo json_encode([
                    'success' => true,
                    'message' => 'OTP sent successfully to your phone',
                    'expires_in' => 600 // 10 minutes
                ]);
            } else {
                // Still allow OTP verification even if SMS fails
                echo json_encode([
                    'success' => true,
                    'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                    'fallback' => true,
                    'otp_code' => $otpCode,
                    'expires_in' => 600
                ]);
            }
            
        } catch (Exception $e) {
            error_log("OTP Send Error: " . $e->getMessage());
            
            // Generate OTP locally as fallback
            $otpCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            echo json_encode([
                'success' => true,
                'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                'fallback' => true,
                'otp_code' => $otpCode,
                'expires_in' => 600
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'verify_otp') {
        $phoneNumber = $_POST['phone_number'] ?? '';
        $otpCode = $_POST['otp_code'] ?? '';
        
        if (empty($phoneNumber) || empty($otpCode)) {
            echo json_encode(['success' => false, 'message' => 'Phone number and OTP code are required']);
            exit;
        }
        
        // Check if OTP session exists
        if (!isset($_SESSION['otp_code']) || !isset($_SESSION['otp_sent_at'])) {
            echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
            exit;
        }
        
        // Check if OTP has expired (10 minutes)
        $currentTime = time();
        $otpSentTime = $_SESSION['otp_sent_at'];
        $timeElapsed = $currentTime - $otpSentTime;
        
        if ($timeElapsed > 600) {
            // Clear expired OTP
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_sent_at']);
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.', 'expired' => true]);
            exit;
        }
        
        // Check attempts limit
        if (!isset($_SESSION['otp_attempts'])) {
            $_SESSION['otp_attempts'] = 0;
        }
        
        if ($_SESSION['otp_attempts'] >= 3) {
            // Clear OTP after max attempts
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_sent_at']);
            unset($_SESSION['otp_attempts']);
            echo json_encode(['success' => false, 'message' => 'Maximum attempts exceeded. Please request a new OTP.']);
            exit;
        }
        
        // Increment attempts
        $_SESSION['otp_attempts']++;
        
        // Verify OTP
        if ($_SESSION['otp_code'] === $otpCode && $_SESSION['password_reset_user']['phone_number'] === $phoneNumber) {
            $_SESSION['otp_verified'] = true;
            $_SESSION['otp_verified_at'] = time();
            
            // Update OTP as verified in database
            try {
                $db = Database::getInstance();
                $updateQuery = "UPDATE otp_verifications SET is_verified = 1, verified_at = NOW() WHERE id = :otp_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':otp_id', $_SESSION['password_reset_user']['otp_id']);
                $updateStmt->execute();
            } catch (Exception $e) {
                error_log("OTP verification update error: " . $e->getMessage());
            }
            
            // Clear OTP session data
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_sent_at']);
            unset($_SESSION['otp_attempts']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'OTP verified successfully!',
                'remaining_time' => max(0, 600 - $timeElapsed)
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid OTP code. Attempts remaining: ' . (3 - $_SESSION['otp_attempts']),
                'remaining_time' => max(0, 600 - $timeElapsed)
            ]);
        }
        exit;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed.');
    }
    
    // Step 1: Request reset (email submission)
    if ($step === 'request') {
        $email = trim($_POST['email']);

        // Validate email
        if (empty($email)) {
            $error = 'Please enter your email address';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            try {
                $db = Database::getInstance();
                
                // Check if user exists
                $query = "SELECT id, username, email, phone_number, status FROM users WHERE email = :email AND deleted = 0";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->execute();
                
                if ($stmt->rowCount() == 1) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Store user data in session for next steps
                    $_SESSION['password_reset_user'] = [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'phone_number' => $user['phone_number'],
                        'username' => $user['username'],
                        'status' => $user['status']
                    ];
                    
                    $step = 'verify';
                    $success = 'Please check your phone for the verification code';
                    
                } else {
                    $error = 'No account found with this email address';
                }
            } catch(PDOException $e) {
                error_log("Password Reset Request Error: " . $e->getMessage());
                $error = 'A system error occurred. Please try again later.';
            }
        }
    }
    
    // Step 3: Reset password
    elseif ($step === 'reset') {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate passwords
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in all fields';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            try {
                $db = Database::getInstance();
                
                // Verify OTP is still valid
                $otpQuery = "SELECT id FROM otp_verifications 
                            WHERE id = :otp_id AND user_id = :user_id AND purpose = 'password_reset' 
                            AND is_verified = 1 AND expires_at > NOW()";
                $otpStmt = $db->prepare($otpQuery);
                $otpStmt->bindParam(':otp_id', $_SESSION['password_reset_user']['otp_id']);
                $otpStmt->bindParam(':user_id', $_SESSION['password_reset_user']['id']);
                $otpStmt->execute();
                
                if ($otpStmt->rowCount() == 1) {
                    // Hash new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update user password
                    $updateQuery = "UPDATE users SET pwd = :password, updated_at = NOW() WHERE id = :user_id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':password', $hashed_password);
                    $updateStmt->bindParam(':user_id', $_SESSION['password_reset_user']['id']);
                    
                    if ($updateStmt->execute()) {
                        // Mark OTP as used
                        $markUsedQuery = "UPDATE otp_verifications SET expires_at = NOW() WHERE id = :otp_id";
                        $markUsedStmt = $db->prepare($markUsedQuery);
                        $markUsedStmt->bindParam(':otp_id', $_SESSION['password_reset_user']['otp_id']);
                        $markUsedStmt->execute();
                        
                        // Clear session and show success
                        $step = 'success';
                        unset($_SESSION['password_reset_user']);
                        unset($_SESSION['otp_verified']);
                        
                    } else {
                        $error = 'Failed to update password. Please try again.';
                    }
                } else {
                    $error = 'Verification session expired. Please start over.';
                    $step = 'request';
                    unset($_SESSION['password_reset_user']);
                    unset($_SESSION['otp_verified']);
                }
            } catch(PDOException $e) {
                error_log("Password Reset Error: " . $e->getMessage());
                $error = 'A system error occurred. Please try again later.';
            }
        }
    }
}

// Handle step transitions
if (isset($_SESSION['password_reset_user']) && !isset($_POST['step'])) {
    if (!isset($_SESSION['otp_verified'])) {
        $step = 'verify';
    } else {
        $step = 'reset';
    }
}

// Get phone number for display
$phone_number = '';
if (isset($_SESSION['password_reset_user']['phone_number'])) {
    $phone_number = $_SESSION['password_reset_user']['phone_number'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Landlords&Tenants</title>
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--light-color);
            color: var(--dark-color);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }

        .logo span {
            color: var(--primary-color);
        }

        /* Main Content */
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .reset-container {
            width: 100%;
            max-width: 500px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .reset-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .reset-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .reset-header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .reset-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
        }

        .input-with-icon .left-icon {
            left: 1rem;
        }

        .input-with-icon input {
            padding-left: 3rem;
            padding-right: 3rem;
        }

        .input-with-icon .right-icon {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            color: #777;
            cursor: pointer;
            transition: color 0.3s;
        }

        .input-with-icon .right-icon:hover {
            color: var(--primary-color);
        }

        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
            text-align: center;
            width: 100%;
        }

        .btn:hover {
            background-color: #2980b9;
        }

        .btn:disabled {
            background-color: #bdc3c7;
            cursor: not-allowed;
        }

        .btn-success {
            background-color: var(--success-color);
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .btn-warning:hover {
            background-color: #e0a800;
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reset-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
            text-align: center;
        }

        .reset-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .reset-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .info-box {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-box i {
            color: var(--primary-color);
            margin-right: 0.5rem;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 10px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            background-color: #e9ecef;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .step.active {
            background-color: var(--primary-color);
            color: white;
        }

        .step.completed {
            background-color: var(--success-color);
            color: white;
        }

        /* OTP Specific Styles */
        .otp-input {
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            max-width: 200px;
            margin: 1rem auto;
        }

        .timer-display {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--accent-color);
            margin: 1rem 0;
            text-align: center;
        }

        .phone-mask {
            color: var(--primary-color);
            font-weight: bold;
        }

        .fallback-otp {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        /* Password Strength */
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #28a745; }
        
        .password-requirements {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        
        .requirement {
            margin-bottom: 0.2rem;
        }
        
        .requirement.met {
            color: #28a745;
        }
        
        .requirement.met::before {
            content: '✓ ';
            font-weight: bold;
        }

        /* Success Page */
        .success-icon {
            font-size: 4rem;
            color: #28a745;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .success-actions {
            display: flex;
            gap: 10px;
            margin-top: 2rem;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }

        @media (max-width: 576px) {
            .reset-body {
                padding: 1.5rem;
            }
            
            .reset-header h1 {
                font-size: 1.5rem;
            }
            
            .step-indicator {
                flex-wrap: wrap;
            }
            
            .step {
                margin: 0.25rem;
                font-size: 0.8rem;
            }
            
            .success-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="../index.php" class="logo">
                <img src="../../assets/images/landlords-logo.png" alt="Logo" width="100" height="80" class="me-2">
                Landlords<span>&Tenants</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="reset-container">
                <div class="reset-header">
                    <h1>
                        <?php 
                        echo match($step) {
                            'verify' => 'Verify Your Identity',
                            'reset' => 'Set New Password',
                            'success' => 'Password Reset Successful!',
                            default => 'Reset Your Password'
                        };
                        ?>
                    </h1>
                    <p>
                        <?php 
                        echo match($step) {
                            'verify' => 'Enter the code sent to your phone',
                            'reset' => 'Create a strong password for your account',
                            'success' => 'Your password has been updated successfully',
                            default => 'Enter your email to receive a verification code'
                        };
                        ?>
                    </p>
                </div>
                
                <div class="reset-body">
                    <!-- Step Indicator -->
                    <?php if ($step !== 'success'): ?>
                    <div class="step-indicator">
                        <div class="step <?php echo $step === 'request' ? 'active' : ($step === 'verify' || $step === 'reset' ? 'completed' : ''); ?>">
                            <i class="fas fa-envelope"></i>
                            <span>Enter Email</span>
                        </div>
                        <div class="step <?php echo $step === 'verify' ? 'active' : ($step === 'reset' ? 'completed' : ''); ?>">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Verify OTP</span>
                        </div>
                        <div class="step <?php echo $step === 'reset' ? 'active' : ''; ?>">
                            <i class="fas fa-lock"></i>
                            <span>New Password</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Step 1: Request Reset -->
                    <?php if ($step === 'request'): ?>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>How it works:</strong> Enter your email address. We'll send a verification code to your registered phone number to verify your identity.
                    </div>

                    <form action="request-reset.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="step" value="request">
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($email); ?>" required
                                       placeholder="Enter your registered email address">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn">Send Verification Code</button>
                        </div>
                    </form>
                    
                    <div class="reset-footer">
                        <p>Remember your password? <a href="../login.php">Back to Login</a></p>
                        <p>Don't have an account? <a href="../register.php">Register here</a></p>
                    </div>
                    <?php endif; ?>

                    <!-- Step 2: Verify OTP -->
                    <?php if ($step === 'verify'): ?>
                    <div class="info-box">
                        <i class="fas fa-mobile-alt"></i>
                        We'll send a 6-digit verification code to your registered phone number: 
                        <span class="phone-mask"><?php echo substr($phone_number, 0, 4) . '****' . substr($phone_number, -3); ?></span>
                    </div>
                    
                    <div id="timerDisplay" class="timer-display" style="display: none;">
                        Code expires in: <span id="countdown">10:00</span>
                    </div>
                    
                    <div id="fallbackOtpDisplay" class="fallback-otp" style="display: none;">
                        <div id="fallbackOtpCode"></div>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" id="sendOtpBtn" class="btn">Send OTP</button>
                    </div>
                    
                    <div class="form-group" id="otpInputGroup" style="display: none;">
                        <input type="text" id="otp_code" class="form-control otp-input" 
                               placeholder="Enter OTP" maxlength="6" required
                               oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <span id="otpError" class="alert alert-danger" style="display: none; margin-top: 10px;"></span>
                        <span id="otpSuccess" class="alert alert-success" style="display: none; margin-top: 10px;"></span>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" id="verifyOtpBtn" class="btn" style="display: none;">Verify OTP</button>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" id="resendOtpBtn" class="btn btn-warning" style="display: none;">Resend OTP</button>
                    </div>
                    
                    <form id="proceedForm" method="POST" style="display: none;">
                        <input type="hidden" name="step" value="reset">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" class="btn btn-success">Proceed to Reset Password</button>
                    </form>
                    
                    <div class="reset-footer">
                        <p><a href="request-reset.php">Use different email</a></p>
                        <p><a href="../login.php">Back to Login</a></p>
                    </div>
                    <?php endif; ?>

                    <!-- Step 3: Reset Password -->
                    <?php if ($step === 'reset'): ?>
                    <form action="request-reset.php" method="POST" id="passwordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="step" value="reset">
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <div class="input-with-icon left-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       required minlength="8"
                                       placeholder="Enter new password">
                                <i class="fas fa-eye right-icon toggle-password" data-target="new_password"></i>
                            </div>
                            <div class="password-strength" id="passwordStrength"></div>
                            <div class="password-requirements">
                                <div class="requirement" id="reqLength">At least 8 characters</div>
                                <div class="requirement" id="reqUppercase">One uppercase letter</div>
                                <div class="requirement" id="reqLowercase">One lowercase letter</div>
                                <div class="requirement" id="reqNumber">One number</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-with-icon left-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       required minlength="8"
                                       placeholder="Confirm new password">
                                <i class="fas fa-eye right-icon toggle-password" data-target="confirm_password"></i>
                            </div>
                            <div id="passwordMatch" class="password-strength"></div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn" id="submitBtn">Reset Password</button>
                        </div>
                    </form>
                    
                    <div class="reset-footer">
                        <p><a href="../login.php">Back to Login</a></p>
                    </div>
                    <?php endif; ?>

                    <!-- Step 4: Success -->
                    <?php if ($step === 'success'): ?>
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    
                    <h3 style="text-align: center;">You're All Set!</h3>
                    <p style="text-align: center;">Your password has been successfully reset. You can now log in to your account with your new password.</p>
                    
                    <div class="success-actions">
                        <a href="../login.php" class="btn" style="flex: 1;">Login Now</a>
                        <a href="../index.php" class="btn btn-outline" style="flex: 1;">Go to Homepage</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer style="background-color: var(--secondary-color); color: white; padding: 1.5rem 0; text-align: center;">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Landlords&Tenants. All rights reserved.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sendOtpBtn = document.getElementById('sendOtpBtn');
            const verifyOtpBtn = document.getElementById('verifyOtpBtn');
            const resendOtpBtn = document.getElementById('resendOtpBtn');
            const proceedForm = document.getElementById('proceedForm');
            const otpError = document.getElementById('otpError');
            const otpSuccess = document.getElementById('otpSuccess');
            const timerDisplay = document.getElementById('timerDisplay');
            const countdown = document.getElementById('countdown');
            const fallbackOtpDisplay = document.getElementById('fallbackOtpDisplay');
            const fallbackOtpCode = document.getElementById('fallbackOtpCode');
            const otpInputGroup = document.getElementById('otpInputGroup');


            const toggleButtons = document.querySelectorAll('.toggle-password');
            
            
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    const icon = this;
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
            
            let otpTimer = null;
            let timeRemaining = 600; // 10 minutes

            // OTP functionality for verify step
            if (sendOtpBtn) {
                // Send OTP
                sendOtpBtn.addEventListener('click', function() {
                    const phoneNumber = '<?php echo htmlspecialchars($phone_number); ?>';
                    
                    if (!phoneNumber) {
                        alert('Phone number not found. Please try again.');
                        window.location.href = 'request-reset.php';
                        return;
                    }

                    sendOtpBtn.disabled = true;
                    sendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

                    fetch('request-reset.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=send_otp&phone_number=${encodeURIComponent(phoneNumber)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.fallback) {
                                // Show the fallback OTP to the user
                                fallbackOtpCode.textContent = data.otp_code;
                                fallbackOtpDisplay.style.display = 'block';
                            }
                            
                            // Show OTP input and verify button
                            otpInputGroup.style.display = 'block';
                            verifyOtpBtn.style.display = 'block';
                            sendOtpBtn.style.display = 'none';
                            
                            // Start timer
                            startOtpTimer();
                            
                            showOtpSuccess(data.message);
                        } else {
                            alert(data.message || 'Failed to send OTP');
                            sendOtpBtn.disabled = false;
                            sendOtpBtn.innerHTML = 'Send OTP';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                        sendOtpBtn.disabled = false;
                        sendOtpBtn.innerHTML = 'Send OTP';
                    });
                });

                // Verify OTP
                verifyOtpBtn.addEventListener('click', function() {
                    const otpCode = document.getElementById('otp_code').value;
                    const phoneNumber = '<?php echo htmlspecialchars($phone_number); ?>';
                    
                    if (!otpCode) {
                        showOtpError('Please enter the OTP code');
                        return;
                    }

                    if (otpCode.length !== 6) {
                        showOtpError('OTP must be 6 digits');
                        return;
                    }

                    verifyOtpBtn.disabled = true;
                    verifyOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

                    fetch('request-reset.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=verify_otp&phone_number=${encodeURIComponent(phoneNumber)}&otp_code=${encodeURIComponent(otpCode)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showOtpSuccess('OTP verified successfully!');
                            clearInterval(otpTimer);
                            verifyOtpBtn.style.display = 'none';
                            if (resendOtpBtn) resendOtpBtn.style.display = 'none';
                            timerDisplay.style.display = 'none';
                            
                            // Show proceed form
                            proceedForm.style.display = 'block';
                        } else {
                            showOtpError(data.message || 'Invalid OTP code');
                            verifyOtpBtn.disabled = false;
                            verifyOtpBtn.innerHTML = 'Verify OTP';
                            
                            if (data.expired) {
                                clearInterval(otpTimer);
                                showResendButton();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showOtpError('An error occurred. Please try again.');
                        verifyOtpBtn.disabled = false;
                        verifyOtpBtn.innerHTML = 'Verify OTP';
                    });
                });

                // Resend OTP
                if (resendOtpBtn) {
                    resendOtpBtn.addEventListener('click', function() {
                        const phoneNumber = '<?php echo htmlspecialchars($phone_number); ?>';
                        
                        resendOtpBtn.disabled = true;
                        resendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';

                        fetch('request-reset.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `ajax=1&action=send_otp&phone_number=${encodeURIComponent(phoneNumber)}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showOtpSuccess('OTP resent successfully!');
                                
                                if (data.fallback) {
                                    fallbackOtpCode.textContent = data.otp_code;
                                    fallbackOtpDisplay.style.display = 'block';
                                }
                                
                                // Restart timer
                                timeRemaining = 600;
                                startOtpTimer();
                                resendOtpBtn.style.display = 'none';
                                verifyOtpBtn.style.display = 'block';
                                verifyOtpBtn.disabled = false;
                                timerDisplay.style.display = 'block';
                            } else {
                                showOtpError(data.message || 'Failed to resend OTP');
                            }
                            resendOtpBtn.disabled = false;
                            resendOtpBtn.innerHTML = 'Resend OTP';
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showOtpError('An error occurred. Please try again.');
                            resendOtpBtn.disabled = false;
                            resendOtpBtn.innerHTML = 'Resend OTP';
                        });
                    });
                }

                function startOtpTimer() {
                    if (otpTimer) {
                        clearInterval(otpTimer);
                    }
                    
                    timeRemaining = 600;
                    timerDisplay.style.display = 'block';
                    updateTimerDisplay();
                    
                    otpTimer = setInterval(function() {
                        timeRemaining--;
                        updateTimerDisplay();
                        
                        if (timeRemaining <= 0) {
                            clearInterval(otpTimer);
                            handleTimerExpired();
                        }
                    }, 1000);
                }

                function updateTimerDisplay() {
                    if (countdown) {
                        const minutes = Math.floor(timeRemaining / 60);
                        const seconds = timeRemaining % 60;
                        countdown.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        
                        if (timeRemaining <= 60) {
                            timerDisplay.classList.add('expired');
                        } else {
                            timerDisplay.classList.remove('expired');
                        }
                    }
                }

                function handleTimerExpired() {
                    if (timerDisplay) {
                        timerDisplay.innerHTML = '<i class="fas fa-exclamation-triangle"></i> OTP has expired';
                        timerDisplay.classList.add('expired');
                    }
                    
                    showOtpError('OTP has expired. Please request a new one.');
                    showResendButton();
                }

                function showResendButton() {
                    if (verifyOtpBtn) verifyOtpBtn.style.display = 'none';
                    if (resendOtpBtn) resendOtpBtn.style.display = 'block';
                }

                function showOtpError(message) {
                    if (otpError) {
                        otpError.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
                        otpError.style.display = 'block';
                        if (otpSuccess) otpSuccess.style.display = 'none';
                    }
                }

                function showOtpSuccess(message) {
                    if (otpSuccess) {
                        otpSuccess.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
                        otpSuccess.style.display = 'block';
                        if (otpError) otpError.style.display = 'none';
                    }
                }

                // Auto-focus on OTP input
                const otpInput = document.getElementById('otp_code');
                if (otpInput) {
                    // Auto-submit when 6 digits are entered
                    otpInput.addEventListener('input', function() {
                        if (this.value.length === 6) {
                            if (verifyOtpBtn && !verifyOtpBtn.disabled) {
                                verifyOtpBtn.click();
                            }
                        }
                    });
                }
            }

            // Password strength validation for reset step
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            const passwordStrength = document.getElementById('passwordStrength');
            const passwordMatch = document.getElementById('passwordMatch');
            const submitBtn = document.getElementById('submitBtn');

            if (newPassword && confirmPassword) {
                const requirements = {
                    length: document.getElementById('reqLength'),
                    uppercase: document.getElementById('reqUppercase'),
                    lowercase: document.getElementById('reqLowercase'),
                    number: document.getElementById('reqNumber')
                };

                function checkPasswordStrength(password) {
                    let strength = 0;
                    
                    // Length check
                    if (password.length >= 8) {
                        strength++;
                        requirements.length.classList.add('met');
                    } else {
                        requirements.length.classList.remove('met');
                    }
                    
                    // Uppercase check
                    if (/[A-Z]/.test(password)) {
                        strength++;
                        requirements.uppercase.classList.add('met');
                    } else {
                        requirements.uppercase.classList.remove('met');
                    }
                    
                    // Lowercase check
                    if (/[a-z]/.test(password)) {
                        strength++;
                        requirements.lowercase.classList.add('met');
                    } else {
                        requirements.lowercase.classList.remove('met');
                    }
                    
                    // Number check
                    if (/[0-9]/.test(password)) {
                        strength++;
                        requirements.number.classList.add('met');
                    } else {
                        requirements.number.classList.remove('met');
                    }
                    
                    // Determine strength level
                    if (password.length === 0) {
                        passwordStrength.textContent = '';
                        passwordStrength.className = 'password-strength';
                    } else if (strength <= 2) {
                        passwordStrength.textContent = 'Weak password';
                        passwordStrength.className = 'password-strength strength-weak';
                    } else if (strength === 3) {
                        passwordStrength.textContent = 'Medium password';
                        passwordStrength.className = 'password-strength strength-medium';
                    } else {
                        passwordStrength.textContent = 'Strong password';
                        passwordStrength.className = 'password-strength strength-strong';
                    }
                    
                    return strength;
                }

                function checkPasswordMatch() {
                    const password = newPassword.value;
                    const confirm = confirmPassword.value;
                    
                    if (confirm.length === 0) {
                        passwordMatch.textContent = '';
                        passwordMatch.className = 'password-strength';
                    } else if (password === confirm) {
                        passwordMatch.textContent = 'Passwords match';
                        passwordMatch.className = 'password-strength strength-strong';
                    } else {
                        passwordMatch.textContent = 'Passwords do not match';
                        passwordMatch.className = 'password-strength strength-weak';
                    }
                }

                function validateForm() {
                    const strength = checkPasswordStrength(newPassword.value);
                    const isMatch = newPassword.value === confirmPassword.value;
                    const isValid = strength >= 3 && isMatch && newPassword.value.length >= 8;
                    
                    if (submitBtn) {
                        submitBtn.disabled = !isValid;
                    }
                }

                newPassword.addEventListener('input', validateForm);
                confirmPassword.addEventListener('input', validateForm);
                
                // Initial validation
                validateForm();
            }

            // Focus on appropriate fields
            const emailInput = document.getElementById('email');
            if (emailInput) {
                emailInput.focus();
            }

            if (newPassword) {
                newPassword.focus();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
