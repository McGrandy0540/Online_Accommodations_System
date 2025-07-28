<?php
session_start();
require_once __DIR__.'/../../config/database.php';

if (isset($_POST['reset-request-submit'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            // Create token and set expiration (30 minutes from now)
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            // Update user record with token and expiration
            $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE email = ?");
            $stmt->execute([$token, $expires, $email]);
            
            // Send email (in production, use a proper mailer)
            $resetLink = "https://".$_SERVER['HTTP_HOST']."/auth/password-reset/reset-password.php?token=".$token;
            
            // For demo purposes, we'll store the link in session
            $_SESSION['reset_link'] = $resetLink;
            $_SESSION['reset_success'] = "Password reset link sent to your email!";
            
            // In production, you would actually send an email here:
            // $to = $email;
            // $subject = "Password Reset Request";
            // $message = "Click this link to reset your password: ".$resetLink;
            // $headers = "From: noreply@yourdomain.com";
            // mail($to, $subject, $message, $headers);
        } else {
            $_SESSION['reset_error'] = "No account found with that email address.";
        }
    } catch (PDOException $e) {
        error_log("Password reset error: ".$e->getMessage());
        $_SESSION['reset_error'] = "An error occurred. Please try again later.";
    }
    
    header("Location: request-reset.php");
    exit();
} else {
    header("Location: ../login.php");
    exit();
}