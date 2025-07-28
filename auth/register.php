<?php
ob_start(); // Start output buffering
session_start();

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../config/database.php';

// Initialize variables
$errors = [];
$formData = [
    'username' => '',
    'email' => '',
    'phone_number' => '',
    'location' => ''
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate inputs
    $formData['username'] = trim($_POST['username'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $formData['phone_number'] = trim($_POST['phone_number'] ?? '');
    $formData['location'] = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? 'student';
    $sex = $_POST['sex'] ?? 'other';

    // Validate inputs
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

    // If no errors, proceed with registration
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
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
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
                    credit_score
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
                    100.00
                )";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $formData['username']);
                $stmt->bindParam(':password', $hashedPassword);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':sex', $sex);
                $stmt->bindParam(':email', $formData['email']);
                $stmt->bindParam(':location', $formData['location']);
                $stmt->bindParam(':phone_number', $formData['phone_number']);
                
                if ($stmt->execute()) {
                    // Get the new user ID
                    $userId = $db->lastInsertId();
                    
                    // Create appropriate records based on user type
                    if ($status === 'property_owner') {
                        $ownerStmt = $db->prepare("INSERT INTO property_owners (owner_id) VALUES (:user_id)");
                        $ownerStmt->bindParam(':user_id', $userId);
                        $ownerStmt->execute();
                    } elseif ($status === 'admin') {
                        // Admin account creation
                        $adminStmt = $db->prepare("INSERT INTO admin (user_id, access_level) VALUES (:user_id, 1)");
                        $adminStmt->bindParam(':user_id', $userId);
                        $adminStmt->execute();
                    }
                    
                    // Set session variables
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $formData['username'];
                    $_SESSION['status'] = $status;
                    $_SESSION['email'] = $formData['email'];
                    $_SESSION['notifications'] = 1;
                    $_SESSION['credit_score'] = 100.00;
                    
                    // Verify session was set
                    if (!isset($_SESSION['user_id'])) {
                        throw new Exception("Session variables not set correctly");
                    }
                    
                    $db->commit();
                    
                    // Handle redirect
                    $statusDirs = ['student', 'property_owner', 'admin'];
                    if (!in_array($status, $statusDirs)) {
                        $status = 'student'; // Default to student
                    }
                    
                    $redirectPath = '../' . $status . '/dashboard.php';
                    if (!headers_sent()) {
                        header('Location: ' . $redirectPath);
                        exit();
                    } else {
                        // If headers were already sent, show success message
                        $_SESSION['registration_success'] = true;
                        header('Location: register.php?success=1');
                        exit();
                    }
                } else {
                    throw new Exception("Failed to execute user insertion query");
                }
            }
        } catch(PDOException $e) {
            $db->rollBack();
            error_log("Registration Error - User: " . $formData['username'] . " | Email: " . $formData['email'] . " | Error: " . $e->getMessage());
            error_log("Backtrace: " . print_r(debug_backtrace(), true));
            $errors['system'] = 'A system error occurred. Please try again later.';
        } catch(Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            error_log("Registration Error: " . $e->getMessage());
            $errors['system'] = 'A system error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | University Accommodation System</title>
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

        .text-danger {
            color: var(--accent-color);
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .register-container {
                margin: 1rem auto;
            }
            
            .register-header h1 {
                font-size: 1.5rem;
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
            <a href="../index.php" class="logo">Uni<span>Homes</span></a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="register-container">
                <div class="register-header">
                    <h1>Create an Account</h1>
                    <p>Join our university accommodation platform</p>
                </div>
                
                <div class="register-body">
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
                    
                    <form action="register.php" method="POST">
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
                                <div class="radio-option">
                                    <input type="radio" id="admin" name="status" value="admin">
                                    <label for="admin">Admin</label>
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
                        
                        <div class="register-footer">
                            <p>Already have an account? <a href="login.php">Login here</a></p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> University Accommodation System. All rights reserved.</p>
            <p><a href="../index.php">Return to homepage</a></p>
        </div>
    </footer>

    <script>
        // Focus on first field when page loads
        document.getElementById('username').focus();

        // Toggle password visibility
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            
            function createToggle(input) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.innerHTML = '<i class="fas fa-eye"></i>';
                toggle.style.position = 'absolute';
                toggle.style.right = '10px';
                toggle.style.top = '50%';
                toggle.style.transform = 'translateY(-50%)';
                toggle.style.background = 'none';
                toggle.style.border = 'none';
                toggle.style.cursor = 'pointer';
                toggle.style.color = '#777';
                
                toggle.addEventListener('click', function() {
                    if (input.type === 'password') {
                        input.type = 'text';
                        this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    } else {
                        input.type = 'password';
                        this.innerHTML = '<i class="fas fa-eye"></i>';
                    }
                });

                const wrapper = input.parentNode;
                wrapper.style.position = 'relative';
                wrapper.appendChild(toggle);
            }
            
            createToggle(passwordInput);
            createToggle(confirmInput);
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>