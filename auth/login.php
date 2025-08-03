<?php
session_start();

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $dashboard = match($_SESSION['status']) {
        'student' => '../student/dashboard.php',
        'property_owner' => '../owner/dashboard.php',
        'admin' => '../admin/dashboard.php',
        default => '../index.php'
    };
    header("Location: $dashboard");
    exit();
}

// Database connection
require_once '../config/database.php';

// Initialize variables
$email = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
            
            // Check user credentials
            $query = "SELECT id, username, pwd, status, email_notifications, credit_score 
                      FROM users 
                      WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['pwd'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['status'] = $user['status'];
                    $_SESSION['email'] = $email;
                    $_SESSION['notifications'] = $user['email_notifications'];
                    $_SESSION['credit_score'] = $user['credit_score'];
                    
                    // Update last login time
                    $updateQuery = "UPDATE users SET updated_at = NOW() WHERE id = :id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
                    $updateStmt->execute();
                    
                    // Redirect to appropriate dashboard
                    $dashboard = match($user['status']) {
                        'student' => '../user/dashboard.php',
                        'property_owner' => '../owner/dashboard.php',
                        'admin' => '../admin/dashboard.php',
                        default => '../index.php'
                    };
                    header("Location: $dashboard");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Landlords&Tenants</title>
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

        .login-container {
            width: 100%;
            max-width: 500px;
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

        .social-login {
            margin-top: 1.5rem;
        }

        .social-login p {
            position: relative;
            text-align: center;
            margin-bottom: 1rem;
            color: #777;
        }

        .social-login p::before,
        .social-login p::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 30%;
            height: 1px;
            background-color: #ddd;
        }

        .social-login p::before {
            left: 0;
        }

        .social-login p::after {
            right: 0;
        }

        .social-icons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .social-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            color: white;
            font-size: 1.2rem;
            transition: transform 0.3s;
        }

        .social-icon:hover {
            transform: translateY(-3px);
        }

        .facebook {
            background-color: #3b5998;
        }

        .google {
            background-color: #db4437;
        }

        .twitter {
            background-color: #1da1f2;
        }

        /* Footer */
        footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
        }

        footer p {
            margin-bottom: 0.5rem;
        }

        footer a {
            color: var(--primary-color);
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                margin: 1rem auto;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
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
            <a href="../index.php" class="logo">Landlords<span>&Tenants</span></a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="login-container">
                <div class="login-header">
                    <h1>Welcome Back</h1>
                    <p>Sign in to access your account</p>
                </div>
                
                <div class="login-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
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
                            <p><a href="forgot-password.php">Forgot your password?</a></p>
                        </div>
                        
                        <div class="social-login">
                            <p>Or login with</p>
                            <div class="social-icons">
                                <a href="#" class="social-icon facebook">
                                    <i class="fab fa-facebook-f"></i>
                                </a>
                                <a href="#" class="social-icon google">
                                    <i class="fab fa-google"></i>
                                </a>
                                <a href="#" class="social-icon twitter">
                                    <i class="fab fa-twitter"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Landlords&Tenants. All rights reserved.</p>
            <p><a href="../index.php">Return to homepage</a></p>
        </div>
    </footer>

    <script>
        // Focus on email field when page loads
        document.getElementById('email').focus();

        // Toggle password visibility
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.createElement('button');
            passwordToggle.type = 'button';
            passwordToggle.innerHTML = '<i class="fas fa-eye"></i>';
            passwordToggle.style.position = 'absolute';
            passwordToggle.style.right = '10px';
            passwordToggle.style.top = '50%';
            passwordToggle.style.transform = 'translateY(-50%)';
            passwordToggle.style.background = 'none';
            passwordToggle.style.border = 'none';
            passwordToggle.style.cursor = 'pointer';
            passwordToggle.style.color = '#777';
            
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    passwordInput.type = 'password';
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });

            const passwordWrapper = document.querySelector('.input-with-icon');
            passwordWrapper.style.position = 'relative';
            passwordWrapper.appendChild(passwordToggle);
        });
    </script>
</body>
</html>