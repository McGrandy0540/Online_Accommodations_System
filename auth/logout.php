<?php
// logout.php
session_start();

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle both GET and POST requests securely
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for POST requests
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Log the CSRF attempt
        error_log("CSRF token validation failed from IP: " . $_SERVER['REMOTE_ADDR']);
        die('Security error: Invalid request');
    }
}

// Store redirect location before destroying session
$redirect = isset($_SESSION['status']) && $_SESSION['status'] === 'admin' ? '../../auth/login.php' : '../login.php';

// Completely destroy the session
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Regenerate session ID for security
session_start();
session_regenerate_id(true);

// Clear any remaining session data
$_SESSION = array();

// Redirect with status message
$_SESSION['logout_message'] = 'You have been successfully logged out.';
header('Location: ' . $redirect);
exit();
?>