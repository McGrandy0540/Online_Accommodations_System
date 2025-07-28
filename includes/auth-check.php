<?php
/**
 * Authentication Verification Middleware
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define constants if not already defined (should ideally be in config/constants.php)
defined('SITE_URL') or define('SITE_URL', 'http://localhost/projects/Online_Accommodation_System');
defined('SESSION_TIMEOUT') or define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
defined('CSRF_TOKEN_NAME') or define('CSRF_TOKEN_NAME', 'csrf_token');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Store requested URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    
    // Set error message
    $_SESSION['error'] = 'You must be logged in to access this page';
    
    // Redirect to login page
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit();
}

// Check if user's account exists
require_once __DIR__.'/../config/database.php';
$db = Database::getInstance();

// Modified query to match your database structure (without 'deleted' column)
$stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {
    // Account no longer exists
    session_unset();
    session_destroy();
    
    header('Location: ' . SITE_URL . '/auth/login.php?error=account_not_found');
    exit();
}

// Check if user has required role (if specified)
if (isset($requiredRole)) {
    $userRole = $_SESSION['status'] ?? '';
    
    // Define role hierarchy
    $roleHierarchy = [
        'admin' => ['admin'],
        'property_owner' => ['property_owner', 'admin'],
        'student' => ['student', 'property_owner', 'admin']
    ];
    
    if (!isset($roleHierarchy[$userRole])) {
        // User role not recognized
        header('Location: ' . SITE_URL . '/error/403.php');
        exit();
    }
    
    if (!in_array($requiredRole, $roleHierarchy[$userRole])) {
        // Insufficient privileges
        header('Location: ' . SITE_URL . '/error/403.php');
        exit();
    }
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Check for session timeout
if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    
    header('Location: ' . SITE_URL . '/auth/login.php?error=session_expired');
    exit();
}

// CSRF Protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[CSRF_TOKEN_NAME]) || 
        !validateCsrfToken($_POST[CSRF_TOKEN_NAME])) {
        // Potential CSRF attack
        error_log("CSRF token validation failed - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        
        header('Location: ' . SITE_URL . '/error/400.php');
        exit();
    }
}

// Helper function to validate CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Helper function to get client IP
function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}