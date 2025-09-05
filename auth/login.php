<?php
ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include required files
require_once '../config/database.php';
require_once '../includes/SMSService.php';
require_once '../config/constants.php';

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
$step = $_POST['step'] ?? $_GET['step'] ?? 'login';
$otpVerified = isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'];

// Get admin phone number for Paystack
$admin_phone = '';
try {
    $db = Database::getInstance();
    $admin_stmt = $db->prepare("SELECT phone_number FROM users WHERE status = 'admin' LIMIT 1");
    $admin_stmt->execute();
    $admin = $admin_stmt->fetch();
    $admin_phone = $admin ? $admin['phone_number'] : ''; 
} catch(Exception $e) {
    error_log("Admin phone query error: " . $e->getMessage());
    $admin_phone = '';
}

// Handle Paystack callback for subscription renewal
if (isset($_GET['paystack_callback']) && $_GET['paystack_callback'] === 'true') {
    if (isset($_GET['reference']) && isset($_SESSION['pending_login'])) {
        $reference = $_GET['reference'];
        $loginData = $_SESSION['pending_login'];
        
        // Verify payment with Paystack
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
                "Cache-Control: no-cache",
            ],
        ]);
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        
        if ($err) {
            $error = 'Payment verification failed: ' . $err;
        } else {
            $tranx = json_decode($response);
            
            if (!$tranx->status) {
                $error = 'Payment verification failed: ' . $tranx->message;
            } else if ('success' == $tranx->data->status) {
                // Payment was successful
                $amount = $tranx->data->amount / 100; // Convert from kobo to currency
                
                if ($amount == 20) { // GHS 20
                    try {
                        $db = Database::getInstance();
                        $db->beginTransaction();
                        
                        $userId = $loginData['id'];
                        
                        // Calculate new subscription expiry date (8 months from now)
                        $startDate = date('Y-m-d');
                        $expiryDate = date('Y-m-d', strtotime('+8 months'));
                        
                        // Update user subscription status
                        $updateUserQuery = "UPDATE users SET 
                            subscription_status = 'active',
                            subscription_expires_at = :expiry_date
                            WHERE id = :user_id";
                        
                        $updateStmt = $db->prepare($updateUserQuery);
                        $updateStmt->bindParam(':expiry_date', $expiryDate);
                        $updateStmt->bindParam(':user_id', $userId);
                        $updateStmt->execute();
                        
                        // Get the subscription plan (8 months)
                        $planQuery = "SELECT * FROM subscription_plans WHERE is_active = 1 LIMIT 1";
                        $planStmt = $db->prepare($planQuery);
                        $planStmt->execute();
                        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($plan) {
                            // Create subscription record
                            $subscriptionQuery = "INSERT INTO user_subscriptions (
                                user_id, plan_id, payment_reference, amount_paid, 
                                start_date, end_date, status, payment_method
                            ) VALUES (
                                :user_id, :plan_id, :reference, :amount,
                                :start_date, :end_date, 'active', 'paystack'
                            )";
                            
                            $subscriptionStmt = $db->prepare($subscriptionQuery);
                            $subscriptionStmt->bindParam(':user_id', $userId);
                            $subscriptionStmt->bindParam(':plan_id', $plan['id']);
                            $subscriptionStmt->bindParam(':reference', $reference);
                            $subscriptionStmt->bindParam(':amount', $amount);
                            $subscriptionStmt->bindParam(':start_date', $startDate);
                            $subscriptionStmt->bindParam(':end_date', $expiryDate);
                            
                            if ($subscriptionStmt->execute()) {
                                $subscriptionId = $db->lastInsertId();
                                
                                // Log payment
                                $paymentLogQuery = "INSERT INTO subscription_payment_logs (
                                    user_id, subscription_id, payment_reference, amount,
                                    payment_status, payment_method, paystack_response
                                ) VALUES (
                                    :user_id, :subscription_id, :reference, :amount,
                                    'success', 'paystack', :response
                                )";
                                
                                $paymentStmt = $db->prepare($paymentLogQuery);
                                $paymentStmt->bindParam(':user_id', $userId);
                                $paymentStmt->bindParam(':subscription_id', $subscriptionId);
                                $paymentStmt->bindParam(':reference', $reference);
                                $paymentStmt->bindParam(':amount', $amount);
                                $paymentStmt->bindParam(':response', $response);
                                $paymentStmt->execute();
                            }
                        }
                        
                        $db->commit();
                        
                        // Clear session data and redirect to OTP verification
                        unset($_SESSION['pending_login']);
                        
                        // Store user data for OTP verification
                        $_SESSION['login_user_data'] = $loginData;
                        
                        header('Location: login.php?step=otp&subscription_renewed=1');
                        exit();
                    } catch(Exception $e) {
                        if (isset($db)) {
                            $db->rollBack();
                        }
                        $error = 'Database error: ' . $e->getMessage();
                        error_log("Subscription renewal Error: " . $e->getMessage());
                    }
                } else {
                    $error = 'Invalid payment amount. Expected GHS 20.';
                }
            } else {
                $error = 'Payment was not successful: ' . $tranx->data->gateway_response;
            }
        }
    } else {
        $error = 'Invalid payment callback parameters or session expired';
    }
}

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
            $_SESSION['login_phone'] = $phoneNumber;
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            // Send OTP via Arkesel SMS
            $smsService = new SMSService();
            $message = "Your Landlords&Tenants login OTP is: {$otpCode}. Valid for 1 minute. Do not share this code.";

            $smsResult = $smsService->sendSMS($phoneNumber, $message);
            
            if ($smsResult) {
                echo json_encode([
                    'success' => true,
                    'message' => 'OTP sent successfully to your phone',
                    'expires_in' => 60
                ]);
            } else {
                // Still allow login with locally generated OTP even if SMS fails
                echo json_encode([
                    'success' => true,
                    'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                    'fallback' => true,
                    'otp_code' => $otpCode,
                    'expires_in' => 60
                ]);
            }
            
        } catch (Exception $e) {
            error_log("OTP Send Error: " . $e->getMessage());
            
            // Generate OTP locally as fallback
            $otpCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['login_phone'] = $phoneNumber;
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['otp_sent_at'] = time();
            $_SESSION['otp_attempts'] = 0;
            
            echo json_encode([
                'success' => true,
                'message' => 'SMS service temporarily unavailable. Your OTP is: ' . $otpCode,
                'fallback' => true,
                'otp_code' => $otpCode,
                'expires_in' => 60
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
        
        // Check if OTP has expired (60 seconds)
        $currentTime = time();
        $otpSentTime = $_SESSION['otp_sent_at'];
        $timeElapsed = $currentTime - $otpSentTime;
        
        if ($timeElapsed > 60) {
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
        if ($_SESSION['otp_code'] === $otpCode && $_SESSION['login_phone'] === $phoneNumber) {
            $_SESSION['otp_verified'] = true;
            $_SESSION['otp_verified_at'] = time();
            $_SESSION['verified_phone'] = $phoneNumber;
            
            // Clear OTP data
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_sent_at']);
            unset($_SESSION['otp_attempts']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'OTP verified successfully! Logging you in...',
                'remaining_time' => max(0, 60 - $timeElapsed)
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid OTP code. Attempts remaining: ' . (3 - $_SESSION['otp_attempts']),
                'remaining_time' => max(0, 60 - $timeElapsed)
            ]);
        }
        exit;
    }
}

// Handle login completion after OTP verification
if (isset($_POST['complete_login']) && $_POST['complete_login'] === '1') {
    if (isset($_SESSION['login_user_data']) && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified']) {
        $userData = $_SESSION['login_user_data'];
        
        // Set session variables for successful login
        $_SESSION['user_id'] = $userData['id'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['status'] = $userData['status'];
        $_SESSION['email'] = $userData['email'];
        $_SESSION['notifications'] = $userData['email_notifications'];
        $_SESSION['credit_score'] = $userData['credit_score'];
        
        // Update last login time
        try {
            $db = Database::getInstance();
            $updateQuery = "UPDATE users SET updated_at = NOW() WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':id', $userData['id'], PDO::PARAM_INT);
            $updateStmt->execute();
        } catch(Exception $e) {
            error_log("Update last login error: " . $e->getMessage());
        }
        
        // Clear temporary session data
        unset($_SESSION['login_user_data']);
        unset($_SESSION['otp_verified']);
        unset($_SESSION['login_phone']);
        unset($_SESSION['verified_phone']);
        
        // Redirect to appropriate dashboard
        $dashboard = match($userData['status']) {
            'student' => '../user/dashboard.php',
            'property_owner' => '../owner/dashboard.php',
            'admin' => '../admin/dashboard.php',
            default => '../index.php'
        };
        header("Location: $dashboard");
        exit();
    } else {
        $error = 'Login session expired. Please try again.';
        $step = 'login';
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed.');
    }
    
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Validate inputs
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        try {
            // Get PDO connection instance
            $db = Database::getInstance();
            
            // Check user credentials with subscription info
            $query = "SELECT id, username, pwd, status, email, phone_number, email_notifications, credit_score,
                             subscription_status, subscription_expires_at
                      FROM users 
                      WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['pwd'])) {
                    // Store user data for later use
                    $_SESSION['login_user_data'] = $user;
                    
                    // Check subscription status for students only
                    if ($user['status'] === 'student') {
                        $subscriptionExpired = false;
                        
                        if ($user['subscription_status'] !== 'active' || 
                            ($user['subscription_expires_at'] && strtotime($user['subscription_expires_at']) < time())) {
                            $subscriptionExpired = true;
                        }
                        
                        if ($subscriptionExpired) {
                            // Store user data for payment processing
                            $_SESSION['pending_login'] = $user;
                            
                            // Redirect to subscription payment
                            header('Location: login.php?step=subscription');
                            exit();
                        }
                    }
                    
                    // For all users (students with valid subscription, property owners, admin), proceed to OTP
                    $_SESSION['login_phone'] = $user['phone_number'];
                    header('Location: login.php?step=otp');
                    exit();
                    
                } else {
                    $error = 'Invalid email or password';
                    logFailedAttempt($email, $_SERVER['REMOTE_ADDR']);
                }
            } else {
                $error = 'Invalid email or password';
                logFailedAttempt($email, $_SERVER['REMOTE_ADDR']);
            }
        } catch(PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            $error = 'A system error occurred. Please try again later.';
        }
    }
}

function logFailedAttempt($email, $ipAddress) {
    try {
        $db = Database::getInstance();
        $query = "INSERT INTO fraud_detection_logs 
                 (user_id, activity_type, risk_score, details, flagged) 
                 SELECT id, 'failed_login', 20.00, :details, 0 
                 FROM users WHERE email = :email";
        $details = "Failed login attempt from IP: $ipAddress";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $stmt->execute();
    } catch(PDOException $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

// Get phone number for OTP step
$phone_number = '';
if ($step === 'otp') {
    if (isset($_SESSION['login_phone']) && !empty($_SESSION['login_phone'])) {
        $phone_number = $_SESSION['login_phone'];
    } elseif (isset($_SESSION['login_user_data']['phone_number'])) {
        $phone_number = $_SESSION['login_user_data']['phone_number'];
    } elseif (isset($_SESSION['pending_login']['phone_number'])) {
        $phone_number = $_SESSION['pending_login']['phone_number'];
    } else {
        // Fallback: try to get phone number from database using user ID
        try {
            $db = Database::getInstance();
            if (isset($_SESSION['login_user_data']['id'])) {
                $user_id = $_SESSION['login_user_data']['id'];
                $stmt = $db->prepare("SELECT phone_number FROM users WHERE id = :id");
                $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
                $user = $stmt->fetch();
                $phone_number = $user ? $user['phone_number'] : '';
            }
        } catch(Exception $e) {
            error_log("Phone number query error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Landlords&Tenants</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://js.paystack.co/v1/inline.js"></script>
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

        .login-container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .login-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            font-size: 1rem;
        }

        .login-body {
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

        .login-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
            text-align: center;
        }

        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .login-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
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

        .subscription-container {
            text-align: center;
            padding: 2rem 0;
        }
        
        .subscription-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            margin: 1rem 0;
            border: 1px solid #dee2e6;
        }
        
        .subscription-price {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .subscription-duration {
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .subscription-features {
            text-align: left;
            margin: 1rem 0;
        }
        
        .subscription-features li {
            margin-bottom: 0.5rem;
            list-style-type: none;
            position: relative;
            padding-left: 1.5rem;
        }
        
        .subscription-features li:before {
            content: '✓';
            color: var(--success-color);
            position: absolute;
            left: 0;
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                margin: 1rem auto;
            }
            
            .login-header h1 {
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
            .login-body {
                padding: 1.5rem;
            }
            
            .login-header h1 {
                font-size: 1.3rem;
            }
            
            .login-header p {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="../index.php" class="logo">
                <img src="../assets/images/landlords-logo.png" alt="Logo" width="100" height="80" class="me-2">
                Landlords<span>&Tenants</span></a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="login-container">
                <div class="login-header">
                    <h1>
                        <?php 
                        echo match($step) {
                            'subscription' => 'Renew Subscription',
                            'otp' => 'Verify Identity',
                            default => 'Welcome Back'
                        };
                        ?>
                    </h1>
                    <p>
                        <?php 
                        echo match($step) {
                            'subscription' => 'Your subscription has expired. Please renew to continue.',
                            'otp' => 'Enter the OTP sent to your phone',
                            default => 'Sign in to access your account'
                        };
                        ?>
                    </p>
                </div>
                
                <div class="login-body">
                    <!-- Step Indicator -->
                    <?php if ($step !== 'login'): ?>
                    <div class="step-indicator">
                        <div class="step <?php echo $step === 'subscription' ? 'active' : ($step === 'otp' ? 'completed' : ''); ?>">
                            <i class="fas fa-credit-card"></i>
                            <span>Subscription</span>
                        </div>
                        <div class="step <?php echo $step === 'otp' ? 'active' : ''; ?>">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Verify OTP</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['subscription_renewed']) && $_GET['subscription_renewed'] == 1): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            Subscription renewed successfully! Please verify your identity to complete login.
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Login Form -->
                    <?php if ($step === 'login'): ?>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($email); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn">Login</button>
                        </div>
                        
                        <div class="login-footer">
                            <p>Don't have an account? <a href="register.php">Register here</a></p>
                            <p><a href="password-reset/request-reset.php">Forgot your password?</a></p>
                        </div>
                    </form>
                    <?php endif; ?>

                    <!-- Subscription Renewal -->
                    <?php if ($step === 'subscription'): ?>
                    <div class="subscription-container">
                        <h3>Renew Your Subscription</h3>
                        <p>Your subscription has expired. Please renew to continue accessing the platform.</p>
                        
                        <div class="subscription-card">
                            <h4>Premium Subscription</h4>
                            <div class="subscription-price">GHS 20</div>
                            <div class="subscription-duration">8 Months Access</div>
                            
                            <ul class="subscription-features">
                                <li>Full access to property listings</li>
                                <li>Direct messaging with landlords/tenants</li>
                                <li>Priority support</li>
                                <li>No advertisements</li>
                                <li>Enhanced visibility for your listings</li>
                            </ul>
                            
                            <button type="button" id="paystack-btn" class="btn">
                                <i class="fas fa-credit-card"></i> Renew with Paystack
                            </button>
                        </div>
                        
                        <p class="text-muted">You will be redirected to Paystack to complete your payment</p>
                        
                        <div class="login-footer">
                            <p><a href="login.php">Back to Login</a></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- OTP Verification -->
                    <?php if ($step === 'otp'): ?>
                    <div class="otp-container">
                        <h3>Verify Your Identity</h3>
                        <p>We're sending a verification code to your registered phone number</p>
                        <p><strong>Time limit: 60 seconds</strong></p>
                        
                        <div id="timerDisplay" class="timer-display" style="display: none;">
                            Time remaining: <span id="countdown">60</span> seconds
                        </div>
                        
                        <div id="fallbackOtpDisplay" class="fallback-otp" style="display: none;">
                          
                        </div>
                        
                        <div class="form-group">
                            <button type="button" id="sendOtpBtn" class="btn">Send OTP</button>
                        </div>
                        
                        <div class="form-group" id="otpInputGroup" style="display: none;">
                            <input type="text" id="otp_code" class="form-control otp-input" 
                                   placeholder="Enter OTP" maxlength="6" required>
                            <span id="otpError" class="text-danger" style="display: none;"></span>
                            <span id="otpSuccess" class="text-success" style="display: none;"></span>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" id="verifyOtpBtn" class="btn" style="display: none;">Verify OTP</button>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" id="resendOtpBtn" class="btn btn-warning" style="display: none;">Resend OTP</button>
                        </div>
                        
                        <form id="completeLoginForm" method="POST" style="display: none;">
                            <input type="hidden" name="complete_login" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" class="btn btn-success">Complete Login</button>
                        </form>
                        
                        <div class="login-footer">
                            <p><a href="login.php">Back to Login</a></p>
                        </div>
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
            <p><a href="../index.php" style="color: var(--primary-color); text-decoration: none;">Return to homepage</a></p>
        </div>
    </footer>

    <script>
        // Login form handling
        document.addEventListener('DOMContentLoaded', function() {
            const sendOtpBtn = document.getElementById('sendOtpBtn');
            const verifyOtpBtn = document.getElementById('verifyOtpBtn');
            const resendOtpBtn = document.getElementById('resendOtpBtn');
            const completeLoginForm = document.getElementById('completeLoginForm');
            const otpError = document.getElementById('otpError');
            const otpSuccess = document.getElementById('otpSuccess');
            const timerDisplay = document.getElementById('timerDisplay');
            const countdown = document.getElementById('countdown');
            const fallbackOtpDisplay = document.getElementById('fallbackOtpDisplay');
            const fallbackOtpCode = document.getElementById('fallbackOtpCode');
            const otpInputGroup = document.getElementById('otpInputGroup');
            
            let otpTimer = null;
            let timeRemaining = 60;

            // Send OTP
            if (sendOtpBtn) {
                sendOtpBtn.addEventListener('click', function() {
                    const phoneNumber = '<?php echo isset($_SESSION['login_phone']) ? htmlspecialchars($_SESSION['login_phone']) : ''; ?>';
                    
                    if (!phoneNumber) {
                        alert('Phone number not found. Please try logging in again.');
                        window.location.href = 'login.php';
                        return;
                    }

                    sendOtpBtn.disabled = true;
                    sendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

                    fetch('login.php', {
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
                        alert('An error occurred: ' + error.message);
                        sendOtpBtn.disabled = false;
                        sendOtpBtn.innerHTML = 'Send OTP';
                    });
                });
            }

            // Verify OTP
            if (verifyOtpBtn) {
                verifyOtpBtn.addEventListener('click', function() {
                    const otpCode = document.getElementById('otp_code').value;
                     const phoneNumber = '<?php echo !empty($phone_number) ? htmlspecialchars($phone_number) : ''; ?>';
                    
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

                    fetch('login.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `ajax=1&action=verify_otp&phone_number=${encodeURIComponent(phoneNumber)}&otp_code=${encodeURIComponent(otpCode)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showOtpSuccess('OTP verified successfully! Completing login...');
                            clearInterval(otpTimer);
                            verifyOtpBtn.style.display = 'none';
                            if (resendOtpBtn) resendOtpBtn.style.display = 'none';
                            timerDisplay.style.display = 'none';
                            
                            // Show complete login form
                            completeLoginForm.style.display = 'block';
                            
                            // Auto-submit after 2 seconds
                            setTimeout(function() {
                                completeLoginForm.submit();
                            }, 2000);
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
                    const phoneNumber = '<?php echo isset($_SESSION['login_phone']) ? htmlspecialchars($_SESSION['login_phone']) : ''; ?>';
                    
                    resendOtpBtn.disabled = true;
                    resendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';

                    fetch('login.php', {
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
                            timeRemaining = 60;
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
                
                timeRemaining = 60;
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

            // Paystack payment integration
            const paystackBtn = document.getElementById('paystack-btn');
            if (paystackBtn) {
                paystackBtn.addEventListener('click', function() {
                    const userEmail = '<?php echo isset($_SESSION['pending_login']['email']) ? htmlspecialchars($_SESSION['pending_login']['email']) : ''; ?>';
                    const adminPhone = '<?php echo htmlspecialchars($admin_phone, ENT_QUOTES, 'UTF-8'); ?>';
                    
                    // Validate email before proceeding
                    if (!userEmail || userEmail.trim() === '') {
                        alert('User email is required for payment processing. Please try logging in again.');
                        window.location.href = 'login.php';
                        return;
                    }
                    
                    console.log('Processing payment for email:', userEmail);
                    
                    // Create payment with Paystack
                    const handler = PaystackPop.setup({
                        key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
                        email: userEmail,
                        amount: 2000, // 20 GHS in kobo
                        currency: 'GHS',
                        ref: 'SUB_LOGIN_' + Math.floor((Math.random() * 1000000000) + 1),
                        metadata: {
                            custom_fields: [
                                {
                                    display_name: "User Email",
                                    variable_name: "user_email",
                                    value: userEmail
                                },
                                {
                                    display_name: "Admin Phone",
                                    variable_name: "admin_phone",
                                    value: adminPhone
                                },
                                {
                                    display_name: "Payment Type",
                                    variable_name: "payment_type",
                                    value: "subscription_renewal"
                                }
                            ]
                        },
                        callback: function(response) {
                            console.log('Payment successful:', response);
                            window.location.href = 'login.php?paystack_callback=true&reference=' + response.reference;
                        },
                        onClose: function() {
                            alert('Payment was cancelled. Please complete your subscription renewal to access the platform.');
                        }
                    });
                    handler.openIframe();
                });
            }

            // Focus on email field when page loads (login step)
            const emailInput = document.getElementById('email');
            if (emailInput) {
                emailInput.focus();
            }

            // Focus on OTP input when OTP step loads
            if (otpInput && otpInputGroup && otpInputGroup.style.display !== 'none') {
                otpInput.focus();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
