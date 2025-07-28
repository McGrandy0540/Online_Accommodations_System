<?php
session_start();

// Check if the success message exists in session
if (!isset($_SESSION['reset_success'])) {
    header("Location: request-reset.php");
    exit();
}

$message = $_SESSION['reset_success'];
unset($_SESSION['reset_success']); // Clear the message after displaying
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful | University Accommodation System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --success-color: #28a745;
            --light-color: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--light-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .success-container {
            background: white;
            padding: 2.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        
        .success-icon {
            font-size: 4rem;
            color: var(--success-color);
            margin-bottom: 1.5rem;
        }
        
        h1 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }
        
        p {
            color: #555;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            padding: 0.8rem 1.5rem;
            border-radius: 5px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        @media (max-width: 576px) {
            .success-container {
                padding: 1.5rem;
            }
            
            .success-icon {
                font-size: 3rem;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h1>Password Reset Successful!</h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a href="../login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Return to Login
        </a>
    </div>
</body>
</html>