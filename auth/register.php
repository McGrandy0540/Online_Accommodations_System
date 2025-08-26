<?php
ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include required files
require_once '../config/database.php';
require_once '../includes/SMSService.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Initialize variables
$errors = [];
$formData = [
    'username' => '',
    'email' => '',
    'phone_number' => '',
    'location' => ''
];
$step = $_POST['step'] ?? $_GET['step'] ?? 'form';
$otpVerified = isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'];

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
            
            // Store OTP in session with timestamp
            $_SESSION['registration_phone'] = $phoneNumber;
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            // Send OTP via Arkesel SMS
            $smsService = new SMSService();
            $message = "Your Landlords&Tenants registration OTP is: {$otpCode}. Valid for 30 seconds. Do not share this code.";
            
            $smsResult = $smsService->sendSMS($phoneNumber, $message);
            
            if ($smsResult) {
                echo json_encode([
                    'success' => true,
                    'message' => 'OTP sent successfully to your phone',
                    'expires_in' => 30
                ]);
            } else {
                // Still allow registration with locally generated OTP even if SMS fails
                echo json_encode([
                    'success' => true,
                    'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                    'fallback' => true,
                    'otp_code' => $otpCode,
                    'expires_in' => 30
                ]);
            }
            
        } catch (Exception $e) {
            error_log("OTP Send Error: " . $e->getMessage());
            
            // Generate OTP locally as fallback
            $otpCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['registration_phone'] = $phoneNumber;
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            echo json_encode([
                'success' => true,
                'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                'fallback' => true,
                'otp_code' => $otpCode,
                'expires_in' => 30
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
        
        // Check if OTP has expired (30 seconds)
        $currentTime = time();
        $otpSentTime = $_SESSION['otp_sent_at'];
        $timeElapsed = $currentTime - $otpSentTime;
        
        if ($timeElapsed > 30) {
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
        if ($_SESSION['otp_code'] === $otpCode && $_SESSION['registration_phone'] === $phoneNumber) {
            $_SESSION['otp_verified'] = true;
            $_SESSION['otp_verified_at'] = time();
            $_SESSION['verified_phone'] = $phoneNumber;
            
            // Clear OTP data
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_sent_at']);
            unset($_SESSION['otp_attempts']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'OTP verified successfully!',
                'remaining_time' => max(0, 30 - $timeElapsed)
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid OTP code. Attempts remaining: ' . (3 - $_SESSION['otp_attempts']),
                'remaining_time' => max(0, 30 - $timeElapsed)
            ]);
        }
        exit;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    $formData['username'] = trim($_POST['username'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $formData['phone_number'] = trim($_POST['phone_number'] ?? '');
    $formData['location'] = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? 'student';
    $sex = $_POST['sex'] ?? 'other';
    
    // Security: Prevent admin registration from public form
    if ($status === 'admin') {
        $status = 'student';
    }

    // Validation
    if (empty($formData['username'])) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($formData['username']) < 3) {
        $errors['username'] = 'Username must be at least 3 characters';
    }

    if (empty($formData['email'])) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    if (empty($formData['phone_number'])) {
        $errors['phone_number'] = 'Phone number is required';
    }

    if (empty($formData['location'])) {
        $errors['location'] = 'Location is required';
    }

    // Check if OTP is verified for students and property owners
    if (($status === 'student' || $status === 'property_owner') && !$otpVerified) {
        $errors['otp'] = 'Phone number verification is required';
        $step = 'otp';
    }

    if (empty($errors)) {
        try {
            $db = Database::getInstance();
            $db->beginTransaction();
            
            // Check if email already exists
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = :email");
            $checkEmail->bindParam(':email', $formData['email']);
            $checkEmail->execute();
            
            if ($checkEmail->rowCount() > 0) {
                $errors['email'] = 'Email already registered';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $query = "INSERT INTO users (
                    username, 
                    pwd, 
                    status, 
                    sex, 
                    email, 
                    location, 
                    phone_number,
                    email_notifications,
                    sms_notifications,
                    credit_score,
                    phone_verified
                ) VALUES (
                    :username, 
                    :password, 
                    :status, 
                    :sex, 
                    :email, 
                    :location, 
                    :phone_number,
                    1,
                    0,
                    100.00,
                    :phone_verified
                )";
                
                $phoneVerified = $otpVerified ? 1 : 0;
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $formData['username']);
                $stmt->bindParam(':password', $hashedPassword);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':sex', $sex);
                $stmt->bindParam(':email', $formData['email']);
                $stmt->bindParam(':location', $formData['location']);
                $stmt->bindParam(':phone_number', $formData['phone_number']);
                $stmt->bindParam(':phone_verified', $phoneVerified);
                
                if ($stmt->execute()) {
                    $userId = $db->lastInsertId();
                    
                    if ($status === 'property_owner') {
                        $ownerStmt = $db->prepare("INSERT INTO property_owners (owner_id) VALUES (:user_id)");
                        $ownerStmt->bindParam(':user_id', $userId);
                        $ownerStmt->execute();
                    }
                    
                    $db->commit();
                    
                    // Clean up session data
                    unset($_SESSION['otp_verified']);
                    unset($_SESSION['registration_phone']);
                    unset($_SESSION['verified_phone']);
                    
                    header('Location: login.php?success=1');
                    exit();
                } else {
                    throw new Exception("Failed to execute user insertion query");
                }
            }
        } catch(Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $errors['system'] = 'A system error occurred. Please try again later.';
            error_log("Registration Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Landlords&Tenants</title>
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
            padding: 2rem 0;
        }

        .register-container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .register-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .register-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .register-header p {
            font-size: 1rem;
        }

        .register-body {
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
            left: 1rem;
            transform: translateY(-50%);
            color: #777;
        }

        .input-with-icon input {
            padding-left: 3rem;
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

        .text-danger {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-top: 0.3rem;
            display: block;
        }

        .text-success {
            color: var(--success-color);
            font-size: 0.9rem;
            margin-top: 0.3rem;
            display: block;
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

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .register-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
            text-align: center;
        }

        .register-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .register-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .radio-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
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
            margin: 0 0.5rem;
        }

        .step.active {
            background-color: var(--primary-color);
            color: white;
        }

        .step.completed {
            background-color: var(--success-color);
            color: white;
        }

        .otp-container {
            text-align: center;
            padding: 2rem 0;
        }

        .otp-input {
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            max-width: 200px;
            margin: 1rem auto;
        }

        .timer-display {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--accent-color);
            margin: 1rem 0;
        }

        .timer-display.expired {
            color: #dc3545;
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

        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .register-container {
                margin: 1rem auto;
            }
            
            .register-header h1 {
                font-size: 1.5rem;
            }
            
            .step-indicator {
                flex-wrap: wrap;
            }
            
            .step {
                margin: 0.25rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .register-body {
                padding: 1.5rem;
            }
            
            .register-header h1 {
                font-size: 1.3rem;
            }
            
            .register-header p {
                font-size: 0.9rem;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="../index.php" class="logo">Landlords<span>&Tenants</span></a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="register-container">
                <div class="register-header">
                    <h1>Create an Account</h1>
                    <p>Join our accommodation platform</p>
                </div>
                
                <div class="register-body">
                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step <?php echo $step === 'form' ? 'active' : ($step !== 'form' ? 'completed' : ''); ?>">
                            <i class="fas fa-user"></i>
                            <span>Details</span>
                        </div>
                        <div class="step <?php echo $step === 'otp' ? 'active' : ($step === 'subscription' ? 'completed' : ''); ?>">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Verify</span>
                        </div>
                    </div>

                    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Registration successful! You can now login.
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($errors['system'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Registration Form -->
                    <form id="registrationForm" action="register.php" method="POST">
                        <input type="hidden" name="step" value="<?php echo htmlspecialchars($step); ?>">
                        
                        <?php if ($step === 'form'): ?>
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($formData['username']); ?>" required>
                            <?php if (isset($errors['username'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['username']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['email']); ?>" required>
                            </div>
                            <?php if (isset($errors['email'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['email']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="password" name="password" class="form-control" required>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['password']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            </div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['confirm_password']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>
                            <div class="input-with-icon">
                                <i class="fas fa-phone"></i>
                                <input type="tel" id="phone_number" name="phone_number" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['phone_number']); ?>" required>
                            </div>
                            <?php if (isset($errors['phone_number'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['phone_number']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Location</label>
                            <div class="input-with-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <input type="text" id="location" name="location" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['location']); ?>" required>
                            </div>
                            <?php if (isset($errors['location'])): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['location']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Account Type</label>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="student" name="status" value="student" checked>
                                    <label for="student">Student</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="owner" name="status" value="property_owner">
                                    <label for="owner">Property Owner</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Gender</label>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="male" name="sex" value="male">
                                    <label for="male">Male</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="female" name="sex" value="female">
                                    <label for="female">Female</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="other" name="sex" value="other" checked>
                                    <label for="other">Other</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" id="sendOtpBtn" class="btn">Send OTP</button>
                        </div>
                        <?php endif; ?>

                        <?php if ($step === 'otp'): ?>
                        <div class="otp-container">
                            <h3>Verify Your Phone Number</h3>
                            <p>We've sent a verification code to your phone number</p>
                            <p><strong>Time limit: 30 seconds</strong></p>
                            
                            <div id="timerDisplay" class="timer-display">Time remaining: <span id="countdown">30</span> seconds</div>
                            
                            <div id="fallbackOtpDisplay" class="fallback-otp" style="display: none;">
                                Your OTP: <span id="fallbackOtpCode"></span>
                            </div>
                            
                            <div class="form-group">
                                <input type="text" id="otp_code" class="form-control otp-input" 
                                       placeholder="Enter OTP" maxlength="6" required>
                                <span id="otpError" class="text-danger" style="display: none;"></span>
                                <span id="otpSuccess" class="text-success" style="display: none;"></span>
                            </div>
                            
                            <div class="form-group">
                                <button type="button" id="verifyOtpBtn" class="btn">Verify OTP</button>
                            </div>
                            
                            <div class="form-group">
                                <button type="button" id="resendOtpBtn" class="btn btn-warning" style="display: none;">Resend OTP</button>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" id="submitBtn" class="btn btn-success" style="display: none;">Complete Registration</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                    
                    <div class="register-footer">
                        <p>Already have an account? <a href="login.php">Login here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer style="background-color: var(--secondary-color); color: white; padding: 1.5rem 0; text-align: center;">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Landlords&Tenants. All rights reserved.</p>
            <p><a href="../index.php" style="color: var(--primary-color); text-decoration: none;">Return to homepage</a></p>
        </div>
    </footer>

    <script>
        // Registration form handling
        document.addEventListener('DOMContentLoaded', function() {
            const sendOtpBtn = document.getElementById('sendOtpBtn');
            const verifyOtpBtn = document.getElementById('verifyOtpBtn');
            const resendOtpBtn = document.getElementById('resendOtpBtn');
            const submitBtn = document.getElementById('submitBtn');
            const otpError = document.getElementById('otpError');
            const otpSuccess = document.getElementById('otpSuccess');
            const timerDisplay = document.getElementById('timerDisplay');
            const countdown = document.getElementById('countdown');
            const fallbackOtpDisplay = document.getElementById('fallbackOtpDisplay');
            const fallbackOtpCode = document.getElementById('fallbackOtpCode');
            
            let otpTimer = null;
            let timeRemaining = 30;

            // Send OTP
            if (sendOtpBtn) {
                sendOtpBtn.addEventListener('click', function() {
                    const phoneNumber = document.getElementById('phone_number').value;
                    const status = document.querySelector('input[name="status"]:checked').value;
                    
                    if (!phoneNumber) {
                        alert('Please enter your phone number');
                        return;
                    }

                    // Only require OTP for students and property owners
                    if (status !== 'student' && status !== 'property_owner') {
                        // Submit form directly for other user types
                        document.getElementById('registrationForm').submit();
                        return;
                    }

                    sendOtpBtn.disabled = true;
                    sendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

                    fetch('register.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=send_otp&phone_number=${encodeURIComponent(phoneNumber)}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.text();
                    })
                    .then(text => {
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            console.error('Invalid JSON response:', text);
                            throw new Error('Server returned invalid JSON response');
                        }
                        
                        if (data.success) {
                            if (data.fallback) {
                                // Show the fallback OTP to the user
                                fallbackOtpCode.textContent = data.otp_code;
                                fallbackOtpDisplay.style.display = 'block';
                            }
                            
                            // Store form data and redirect to OTP step
                            const formData = new FormData(document.getElementById('registrationForm'));
                            const params = new URLSearchParams();
                            for (let [key, value] of formData.entries()) {
                                params.append(key, value);
                            }
                            params.set('step', 'otp');
                            
                            window.location.href = 'register.php?' + params.toString();
                        } else {
                            alert(data.message || 'Failed to send OTP');
                            sendOtpBtn.disabled = false;
                            sendOtpBtn.innerHTML = 'Send OTP';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred: ' + error.message);
                        sendOtpBtn.disabled = false;
                        sendOtpBtn.innerHTML = 'Send OTP';
                    });
                });
            }

            // Start timer for OTP page
            if (countdown && timerDisplay) {
                startOtpTimer();
            }

            // Verify OTP
            if (verifyOtpBtn) {
                verifyOtpBtn.addEventListener('click', function() {
                    const otpCode = document.getElementById('otp_code').value;
                    const phoneNumber = '<?php echo isset($_SESSION['registration_phone']) ? htmlspecialchars($_SESSION['registration_phone']) : ''; ?>';
                    
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

                    fetch('register.php', {
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
                            if (submitBtn) submitBtn.style.display = 'block';
                            timerDisplay.style.display = 'none';
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
            }

            // Resend OTP
            if (resendOtpBtn) {
                resendOtpBtn.addEventListener('click', function() {
                    const phoneNumber = '<?php echo isset($_SESSION['registration_phone']) ? htmlspecialchars($_SESSION['registration_phone']) : ''; ?>';
                    
                    resendOtpBtn.disabled = true;
                    resendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';

                    fetch('register.php', {
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
                            timeRemaining = 30;
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
                
                timeRemaining = 30;
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
                    countdown.textContent = timeRemaining;
                    
                    if (timeRemaining <= 10) {
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
                otpInput.focus();
                
                // Auto-submit when 6 digits are entered
                otpInput.addEventListener('input', function() {
                    // Only allow numbers
                    this.value = this.value.replace(/[^0-9]/g, '');
                    
                    if (this.value.length === 6) {
                        if (verifyOtpBtn && !verifyOtpBtn.disabled) {
                            verifyOtpBtn.click();
                        }
                    }
                });
            }

            // Focus on first field when page loads
            const firstInput = document.querySelector('input[type="text"], input[type="email"]');
            if (firstInput) {
                firstInput.focus();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
