<?php
ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include required files
require_once '../config/database.php';
require_once '../config/constants.php'; // Make sure your Paystack API key is here

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

// Handle Paystack callback
if (isset($_GET['paystack_callback']) && $_GET['paystack_callback'] === 'true') {
    if (isset($_GET['reference']) && isset($_SESSION['pending_registration'])) {
        $reference = $_GET['reference'];
        $registrationData = $_SESSION['pending_registration'];
        
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
            $errors['payment'] = 'Payment verification failed: ' . $err;
        } else {
            $tranx = json_decode($response);
            
            if (!$tranx->status) {
                $errors['payment'] = 'Payment verification failed: ' . $tranx->message;
            } else if ('success' == $tranx->data->status) {
                // Payment was successful
                $amount = $tranx->data->amount / 100; // Convert from kobo to currency
                
                if ($amount == 20) { // GHS 20
                    try {
                        $db = Database::getInstance();
                        $db->beginTransaction();
                        
                        // Check if email already exists (double check)
                        $checkEmail = $db->prepare("SELECT id FROM users WHERE email = :email");
                        $checkEmail->bindParam(':email', $registrationData['email']);
                        $checkEmail->execute();
                        
                        if ($checkEmail->rowCount() > 0) {
                            throw new Exception("Email already registered");
                        }
                        
                        // Create the user after successful payment
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
                            phone_verified,
                            subscription_status,
                            subscription_expires_at
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
                            1,
                            'active',
                            :subscription_expires_at
                        )";
                        
                        // Calculate subscription expiry date (8 months from now)
                        $startDate = date('Y-m-d');
                        $expiryDate = date('Y-m-d', strtotime('+8 months'));
                        
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':username', $registrationData['username']);
                        $stmt->bindParam(':password', $registrationData['password']);
                        $stmt->bindParam(':status', $registrationData['status']);
                        $stmt->bindParam(':sex', $registrationData['sex']);
                        $stmt->bindParam(':email', $registrationData['email']);
                        $stmt->bindParam(':location', $registrationData['location']);
                        $stmt->bindParam(':phone_number', $registrationData['phone_number']);
                        $stmt->bindParam(':subscription_expires_at', $expiryDate);
                        
                        if ($stmt->execute()) {
                            $userId = $db->lastInsertId();
                            
                            // Create property owner record if needed
                            if ($registrationData['status'] === 'property_owner') {
                                $ownerStmt = $db->prepare("INSERT INTO property_owners (owner_id) VALUES (:user_id)");
                                $ownerStmt->bindParam(':user_id', $userId);
                                $ownerStmt->execute();
                            }
                            
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
                            
                            // Clear session data
                            unset($_SESSION['pending_registration']);
                            
                            header('Location: login.php?success=1&subscribed=1');
                            exit();
                        } else {
                            throw new Exception("Failed to create user account");
                        }
                    } catch(Exception $e) {
                        if (isset($db)) {
                            $db->rollBack();
                        }
                        $errors['payment'] = 'Database error: ' . $e->getMessage();
                        error_log("Registration after payment Error: " . $e->getMessage());
                    }
                } else {
                    $errors['payment'] = 'Invalid payment amount. Expected GHS 20.';
                }
            } else {
                $errors['payment'] = 'Payment was not successful: ' . $tranx->data->gateway_response;
            }
        }
    } else {
        $errors['payment'] = 'Invalid payment callback parameters or session expired';
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    // Get form data
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

    if (empty($errors)) {
        try {
            $db = Database::getInstance();
            
            // Check if email already exists
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = :email");
            $checkEmail->bindParam(':email', $formData['email']);
            $checkEmail->execute();
            
            if ($checkEmail->rowCount() > 0) {
                $errors['email'] = 'Email already registered';
            } else {
                // Store form data in session for later use
                $_SESSION['pending_registration'] = [
                    'username' => $formData['username'],
                    'email' => $formData['email'],
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'status' => $status,
                    'sex' => $sex,
                    'location' => $formData['location'],
                    'phone_number' => $formData['phone_number']
                ];

                // Role-based redirection
                if ($status === 'student') {
                    // Students need to pay subscription
                    header('Location: register.php?step=payment');
                    exit();
                } else {
                    // Property owners and admins are saved directly to database
                    try {
                        $db->beginTransaction();
                        
                        // Create the user account directly
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
                            phone_verified,
                            subscription_status,
                            subscription_expires_at
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
                            1,
                            'active',
                            NULL
                        )";
                        
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':username', $formData['username']);
                        $stmt->bindParam(':password', $_SESSION['pending_registration']['password']);
                        $stmt->bindParam(':status', $status);
                        $stmt->bindParam(':sex', $sex);
                        $stmt->bindParam(':email', $formData['email']);
                        $stmt->bindParam(':location', $formData['location']);
                        $stmt->bindParam(':phone_number', $formData['phone_number']);
                        
                        if ($stmt->execute()) {
                            $userId = $db->lastInsertId();
                            
                            // Create property owner record if needed
                            if ($status === 'property_owner') {
                                $ownerStmt = $db->prepare("INSERT INTO property_owners (owner_id) VALUES (:user_id)");
                                $ownerStmt->bindParam(':user_id', $userId);
                                $ownerStmt->execute();
                            }
                            
                            $db->commit();
                            
                            // Clear session data
                            unset($_SESSION['pending_registration']);
                            
                            // Redirect based on user type
                            if ($status === 'property_owner') {
                                header('Location: login.php?success=1&message=Property owner account created successfully');
                            } elseif ($status === 'admin') {
                                header('Location: login.php?success=1&message=Admin account created successfully');
                            } else {
                                header('Location: login.php?success=1');
                            }
                            exit();
                        } else {
                            throw new Exception("Failed to create user account");
                        }
                    } catch(Exception $e) {
                        if (isset($db)) {
                            $db->rollBack();
                        }
                        $errors['system'] = 'Database error: ' . $e->getMessage();
                        error_log("Registration Error: " . $e->getMessage());
                    }
                }
            }
        } catch(Exception $e) {
            $errors['system'] = 'A system error occurred. Please try again later.';
            error_log("Registration Error: " . $e->getMessage());
        }            
    }
}

// Handle payment step
if (isset($_GET['step']) && $_GET['step'] === 'payment') {
    if (isset($_SESSION['pending_registration']) && !empty($_SESSION['pending_registration']['email'])) {
        $step = 'payment';
        $registrationData = $_SESSION['pending_registration'];
        $formData['email'] = $registrationData['email'];
    } else {
        // Session data is missing, go back to form
        $errors['system'] = 'Registration data missing. Please fill out the form again.';
        $step = 'form';
        error_log("Missing pending_registration session data");
        
        // Clear invalid session data
        unset($_SESSION['pending_registration']);
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
        
        .payment-container {
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
                        <div class="step <?php echo $step === 'payment' ? 'active' : ''; ?>">
                            <i class="fas fa-credit-card"></i>
                            <span>Payment</span>
                        </div>
                    </div>

                    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            <?php 
                            if (isset($_GET['subscribed']) && $_GET['subscribed'] == 1) {
                                echo 'Registration and payment successful! Your subscription is active for 8 months. You can now login.';
                            } elseif (isset($_GET['message'])) {
                                echo htmlspecialchars($_GET['message']) . ' You can now login.';
                            } else {
                                echo 'Registration successful! You can now login.';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($errors['system'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($errors['payment'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['payment']); ?>
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
                            <button type="submit" class="btn">Register</button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($step === 'payment'): ?>
                        <div class="payment-container">
                            <h3>Complete Your Subscription</h3>
                            <p>To access all features, please subscribe to our platform</p>
                            
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
                                    <i class="fas fa-credit-card"></i> Pay with Paystack
                                </button>
                            </div>
                            
                            <p class="text-muted">You will be redirected to Paystack to complete your payment</p>
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

    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script>
        // Registration form handling
        document.addEventListener('DOMContentLoaded', function() {
            // Paystack payment integration
            const paystackBtn = document.getElementById('paystack-btn');
            if (paystackBtn) {
                paystackBtn.addEventListener('click', function() {
                    const userEmail = <?php echo isset($_SESSION['pending_registration']['email']) ? json_encode($_SESSION['pending_registration']['email']) : 'null'; ?>;
                    const adminPhone = '<?php echo htmlspecialchars($admin_phone, ENT_QUOTES, 'UTF-8'); ?>';
                    
                    // Validate email before proceeding
                    if (!userEmail || userEmail.trim() === '') {
                        alert('User email is required for payment processing. Please go back and ensure your email is entered.');
                        window.location.href = 'register.php?step=form';
                        return;
                    }
                    
                    console.log('Processing payment for email:', userEmail);
                    
                    // Create payment with Paystack
                    const handler = PaystackPop.setup({
                        key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
                        email: userEmail,
                        amount: 2000, // 20 GHS in kobo
                        currency: 'GHS',
                        ref: 'SUB' + Math.floor((Math.random() * 1000000000) + 1),
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
                                    display_name: "Registration Type",
                                    variable_name: "registration_type",
                                    value: "subscription_payment"
                                }
                            ]
                        },
                        callback: function(response) {
                            console.log('Payment successful:', response);
                            window.location.href = 'register.php?paystack_callback=true&reference=' + response.reference;
                        },
                        onClose: function() {
                            alert('Payment was cancelled. Please complete your subscription to access the platform.');
                        }
                    });
                    handler.openIframe();
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
