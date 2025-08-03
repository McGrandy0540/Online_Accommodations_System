<?php
/**
 * Enhanced System Functions
 */

// ==========================================
// Error Handling & Debugging
// ==========================================

function setErrorHandling() {
    if (DEBUG_MODE) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
    } else {
        error_reporting(0);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        ini_set('error_log', LOG_PATH . 'errors.log');
    }
}

function logError($message, $context = []) {
    $logMessage = date('[Y-m-d H:i:s]') . " " . $message;
    if (!empty($context)) {
        $logMessage .= " Context: " . json_encode($context);
    }
    error_log($logMessage);
}

// ==========================================
// Security Functions
// ==========================================

function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function validatePasswordStrength($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    if (!preg_match("#[0-9]+#", $password)) {
        $errors[] = "Password must include at least one number";
    }
    if (!preg_match("#[a-zA-Z]+#", $password)) {
        $errors[] = "Password must include at least one letter";
    }
    if (!preg_match("#[^a-zA-Z0-9]+#", $password)) {
        $errors[] = "Password must include at least one special character";
    }
    return $errors;
}

function generateRememberMeToken($userId) {
    $token = generateSecureToken();
    $expiry = time() + REMEMBER_ME_EXPIRE;
    $cookieValue = base64_encode("$userId:$expiry:$token");
    
    // Store token in database
    global $db;
    $hashedToken = hash('sha256', $token);
    $stmt = $db->prepare("INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $hashedToken, date('Y-m-d H:i:s', $expiry)]);
    
    return $cookieValue;
}

// ==========================================
// Database Helpers
// ==========================================

function dbQuery($sql, $params = []) {
    global $db;
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        logError("Database Query Error: " . $e->getMessage(), [
            'sql' => $sql,
            'params' => $params
        ]);
        return false;
    }
}

function dbFetchAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : false;
}

function dbFetchOne($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
}

// ==========================================
// File Handling
// ==========================================

function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9\-\._]/', '', $filename);
    $filename = str_replace(' ', '-', $filename);
    return substr($filename, 0, 255);
}

function deleteFile($path) {
    if (file_exists($path)) {
        try {
            return unlink($path);
        } catch (Exception $e) {
            logError("File deletion failed: " . $e->getMessage());
            return false;
        }
    }
    return false;
}

// ==========================================
// User & Authentication
// ==========================================

function getUserById($userId) {
    return dbFetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
}

function checkUserAccess($requiredRole, $userId = null) {
    $userId = $userId ?? $_SESSION['user_id'] ?? null;
    if (!$userId) return false;
    
    $user = getUserById($userId);
    if (!$user) return false;
    
    // Implement role hierarchy if needed
    $roleHierarchy = [
        'admin' => ['admin'],
        'property_owner' => ['property_owner', 'admin'],
        'student' => ['student', 'property_owner', 'admin']
    ];
    
    return isset($roleHierarchy[$user['status']]) && 
           in_array($requiredRole, $roleHierarchy[$user['status']]);
}

// ==========================================
// API Helpers
// ==========================================

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function validateApiRequest() {
    // Check for API key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if (!$apiKey) {
        jsonResponse(['error' => 'API key required'], 401);
    }
    
    // Validate API key
    $validKey = dbFetchOne("SELECT user_id FROM api_keys WHERE api_key = ? AND expires_at > NOW()", [$apiKey]);
    if (!$validKey) {
        jsonResponse(['error' => 'Invalid API key'], 403);
    }
    
    return $validKey['user_id'];
}

// ==========================================
// Notification System
// ==========================================

function sendNotification($userId, $message, $type = 'info', $data = []) {
    $stmt = dbQuery(
        "INSERT INTO notifications (user_id, message, type, data) VALUES (?, ?, ?, ?)",
        [$userId, $message, $type, json_encode($data)]
    );
    
    // // Send real-time notification if enabled
    // if (ENABLE_REALTIME_NOTIFICATIONS) {
    //     sendRealtimeNotification($userId, [
    //         'message' => $message,
    //         'type' => $type,
    //         'timestamp' => time()
    //     ]);
    // }
    
    return $stmt !== false;
}

// ==========================================
// Template Rendering
// ==========================================

function renderTemplate($template, $data = []) {
    $templatePath = TEMPLATE_PATH . $template . '.php';
    if (!file_exists($templatePath)) {
        logError("Template not found: $template");
        return false;
    }
    
    extract($data);
    ob_start();
    include $templatePath;
    return ob_get_clean();
}

// ==========================================
// Utility Functions
// ==========================================

function formatCurrency($amount, $currency = null) {
    $currency = $currency ?? PAYSTACK_CURRENCY;
    $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    return $formatter->formatCurrency($amount, $currency);
}

function generateQRCode($data, $size = 200) {
    $url = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
    return $url;
}

function getClientDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (stripos($userAgent, 'mobile') !== false) {
        return 'mobile';
    } elseif (stripos($userAgent, 'tablet') !== false) {
        return 'tablet';
    } else {
        return 'desktop';
    }
}

// ==========================================
// System Maintenance
// ==========================================

function performNightlyMaintenance() {
    // Cleanup expired sessions
    dbQuery("DELETE FROM sessions WHERE expires_at < NOW()");
    
    // Cleanup old password reset tokens
    dbQuery("DELETE FROM password_resets WHERE expires_at < NOW()");
    
    // Archive old logs
    if (file_exists(LOG_PATH . 'errors.log')) {
        $archiveName = LOG_PATH . 'archive/errors-' . date('Y-m-d') . '.log';
        rename(LOG_PATH . 'errors.log', $archiveName);
    }
    
    // Backup database (implementation depends on your DB system)
    // ...
}