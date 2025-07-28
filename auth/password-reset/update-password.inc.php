<?php
session_start();
require_once __DIR__.'/../../config/database.php';

if (isset($_POST['reset-password-submit'])) {
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate passwords
    if ($password !== $confirmPassword) {
        $_SESSION['password_error'] = "Passwords do not match.";
        header("Location: reset-password.php?token=".$token);
        exit();
    }
    
    if (strlen($password) < 8) {
        $_SESSION['password_error'] = "Password must be at least 8 characters.";
        header("Location: reset-password.php?token=".$token);
        exit();
    }
    
    try {
        $db = Database::getInstance();
        
        // Verify token and get user ID
        $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW()");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() === 0) {
            $_SESSION['reset_error'] = "Invalid or expired reset link.";
            header("Location: request-reset.php");
            exit();
        }
        
        $user = $stmt->fetch();
        $userId = $user['id'];
        
        // Update password and clear reset token
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET pwd = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        
        $_SESSION['reset_success'] = "Password updated successfully! You can now login.";
        header("Location: ../login.php");
        exit();
        
    } catch (PDOException $e) {
        error_log("Password update error: ".$e->getMessage());
        $_SESSION['password_error'] = "An error occurred. Please try again.";
        header("Location: reset-password.php?token=".$token);
        exit();
    }
} else {
    header("Location: request-reset.php");
    exit();
}