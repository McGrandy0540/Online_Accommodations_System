<?php
/**
 * Debug Payment Errors Script
 * This script helps identify and fix common payment-related errors
 */

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

echo "<h1>Payment System Debug Report</h1>";
echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";

// Test 1: Database Connection
echo "<h2>1. Database Connection Test</h2>";
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = Database::getInstance();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Test database tables
    $tables = ['users', 'bookings', 'payments', 'property', 'notifications'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
            $count = $stmt->fetchColumn();
            echo "<p style='color: green;'>✓ Table '$table' exists with $count records</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Table '$table' error: " . $e->getMessage() . "</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

// Test 2: Paystack Configuration
echo "<h2>2. Paystack Configuration Test</h2>";
if (defined('PAYSTACK_SECRET_KEY') && defined('PAYSTACK_PUBLIC_KEY')) {
    echo "<p style='color: green;'>✓ Paystack keys are defined</p>";
    echo "<p>Public Key: " . substr(PAYSTACK_PUBLIC_KEY, 0, 10) . "...</p>";
    echo "<p>Secret Key: " . substr(PAYSTACK_SECRET_KEY, 0, 10) . "...</p>";
    
    // Test Paystack API connection
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.paystack.co/bank",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "Cache-Control: no-cache"
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        echo "<p style='color: red;'>✗ Paystack API connection failed: $error</p>";
    } elseif ($http_status === 200) {
        echo "<p style='color: green;'>✓ Paystack API connection successful</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Paystack API returned status: $http_status</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Paystack keys not defined</p>";
}

// Test 3: File Paths
echo "<h2>3. File Path Tests</h2>";
$critical_files = [
    'config/database.php',
    'user/payments/verify_booking_payment.php',
    'user/payments/payment_success.php',
    'user/payments/index.php'
];

foreach ($critical_files as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✓ File exists: $file</p>";
    } else {
        echo "<p style='color: red;'>✗ File missing: $file</p>";
    }
}

// Test 4: Session Configuration
echo "<h2>4. Session Configuration</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<p>Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session Save Path: " . session_save_path() . "</p>";

// Test 5: PHP Configuration
echo "<h2>5. PHP Configuration</h2>";
$php_settings = [
    'display_errors' => ini_get('display_errors'),
    'log_errors' => ini_get('log_errors'),
    'error_log' => ini_get('error_log'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize')
];

foreach ($php_settings as $setting => $value) {
    echo "<p>$setting: $value</p>";
}

// Test 6: cURL Configuration
echo "<h2>6. cURL Configuration</h2>";
if (function_exists('curl_version')) {
    $curl_info = curl_version();
    echo "<p style='color: green;'>✓ cURL is available</p>";
    echo "<p>cURL Version: " . $curl_info['version'] . "</p>";
    echo "<p>SSL Version: " . $curl_info['ssl_version'] . "</p>";
} else {
    echo "<p style='color: red;'>✗ cURL is not available</p>";
}

// Test 7: JSON Functions
echo "<h2>7. JSON Functions Test</h2>";
if (function_exists('json_encode') && function_exists('json_decode')) {
    echo "<p style='color: green;'>✓ JSON functions are available</p>";
    
    // Test JSON encoding/decoding
    $test_data = ['test' => 'data', 'number' => 123];
    $json = json_encode($test_data);
    $decoded = json_decode($json, true);
    
    if ($decoded === $test_data) {
        echo "<p style='color: green;'>✓ JSON encoding/decoding works correctly</p>";
    } else {
        echo "<p style='color: red;'>✗ JSON encoding/decoding failed</p>";
    }
} else {
    echo "<p style='color: red;'>✗ JSON functions are not available</p>";
}

// Test 8: Error Log Check
echo "<h2>8. Recent Error Log Entries</h2>";
$error_log_path = ini_get('error_log');
if ($error_log_path && file_exists($error_log_path)) {
    $log_content = file_get_contents($error_log_path);
    $recent_errors = array_slice(explode("\n", $log_content), -10);
    
    echo "<p>Last 10 error log entries:</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>";
    foreach ($recent_errors as $error) {
        if (trim($error)) {
            echo htmlspecialchars($error) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p>Error log not found or not accessible</p>";
}

// Test 9: Browser Storage Test (JavaScript)
echo "<h2>9. Browser Storage Test</h2>";
echo "<div id='storage-test'></div>";
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const testDiv = document.getElementById('storage-test');
    let results = [];
    
    // Test localStorage
    try {
        localStorage.setItem('test', 'value');
        localStorage.removeItem('test');
        results.push('<p style=\"color: green;\">✓ localStorage is available</p>');
    } catch (e) {
        results.push('<p style=\"color: red;\">✗ localStorage failed: ' + e.message + '</p>');
    }
    
    // Test sessionStorage
    try {
        sessionStorage.setItem('test', 'value');
        sessionStorage.removeItem('test');
        results.push('<p style=\"color: green;\">✓ sessionStorage is available</p>');
    } catch (e) {
        results.push('<p style=\"color: red;\">✗ sessionStorage failed: ' + e.message + '</p>');
    }
    
    // Test WebSocket support
    if (typeof WebSocket !== 'undefined') {
        results.push('<p style=\"color: green;\">✓ WebSocket is supported</p>');
    } else {
        results.push('<p style=\"color: red;\">✗ WebSocket is not supported</p>');
    }
    
    testDiv.innerHTML = results.join('');
});
</script>";

// Recommendations
echo "<h2>10. Recommendations</h2>";
echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px; border-left: 4px solid #2196F3;'>";
echo "<h3>To Fix Common Issues:</h3>";
echo "<ol>";
echo "<li><strong>JSON Parsing Error:</strong> Fixed by correcting database include paths in payment files</li>";
echo "<li><strong>WebSocket Connection:</strong> This is likely due to network restrictions or Pusher configuration. Consider using polling as fallback</li>";
echo "<li><strong>Datadog Storage:</strong> This is a browser storage issue. Add error handling for when storage is not available</li>";
echo "<li><strong>Enable Error Logging:</strong> Set log_errors=On in php.ini for better debugging</li>";
echo "<li><strong>Check File Permissions:</strong> Ensure web server can read all PHP files</li>";
echo "</ol>";
echo "</div>";

// Clean up output buffer
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment System Debug Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1 { color: #333; border-bottom: 2px solid #007cba; padding-bottom: 10px; }
        h2 { color: #007cba; margin-top: 30px; }
        h3 { color: #555; }
        pre { overflow-x: auto; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
    </style>
</head>
<body>
    <div style="max-width: 1000px; margin: 0 auto;">
        <!-- Content is generated by PHP above -->
    </div>
</body>
</html>
