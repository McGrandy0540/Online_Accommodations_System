<?php
session_start();
require_once __DIR__.'/../../config/database.php';

// Check if token is valid
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW()");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() === 0) {
            $_SESSION['reset_error'] = "Invalid or expired reset link.";
            header("Location: request-reset.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Token validation error: ".$e->getMessage());
        $_SESSION['reset_error'] = "An error occurred. Please try again.";
        header("Location: request-reset.php");
        exit();
    }
} else {
    header("Location: request-reset.php");
    exit();
}

$error = '';
if (isset($_SESSION['password_error'])) {
    $error = $_SESSION['password_error'];
    unset($_SESSION['password_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .reset-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        .reset-container h2 {
            margin-top: 0;
            color: #2c3e50;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #2c3e50;
        }
        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        .form-control:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        .btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background-color: #2980b9;
        }
        .alert {
            padding: 0.8rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .password-toggle {
            position: relative;
        }
        .password-toggle i {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #777;
        }
        small {
            display: block;
            margin-top: 0.3rem;
            color: #666;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <h2>Create New Password</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form action="update-password.inc.php" method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="form-group password-toggle">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" class="form-control" required minlength="8">
                <i class="fas fa-eye" id="togglePassword"></i>
                <small>Minimum 8 characters</small>
            </div>
            
            <div class="form-group password-toggle">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                <i class="fas fa-eye" id="toggleConfirmPassword"></i>
            </div>
            
            <button type="submit" class="btn" name="reset-password-submit">
                <i class="fas fa-key"></i> Update Password
            </button>
        </form>
    </div>

    <script>
        // Toggle password visibility
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.querySelector('#togglePassword');
            const password = document.querySelector('#password');
            const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
            const confirmPassword = document.querySelector('#confirm_password');
            
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
            
            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long!');
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>