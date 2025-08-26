<?php
require_once 'config/database.php';
require_once 'includes/SMSService.php';
require_once 'includes/NotificationService.php';

echo "<h1>Fast SMS Delivery Test</h1>";

try {
    // Initialize services
    $smsService = new SMSService();
    $notificationService = new NotificationService();
    
    echo "<h2>Testing Optimized SMS Delivery</h2>";
    
    // Test phone number (replace with your actual phone number for testing)
    $testPhoneNumber = "0240687599"; // Replace with a valid Ghana phone number
    $testMessage = "FAST SMS TEST: This message should arrive within 2-5 seconds!";
    
    echo "<p><strong>Test Phone Number:</strong> $testPhoneNumber</p>";
    echo "<p><strong>Test Message:</strong> $testMessage</p>";
    
    // Record start time
    $startTime = microtime(true);
    echo "<h3>Sending SMS with optimized settings...</h3>";
    echo "<p><em>Start time: " . date('H:i:s') . "." . sprintf('%03d', ($startTime - floor($startTime)) * 1000) . "</em></p>";
    
    // Send test SMS directly (fastest method)
    $result = $smsService->sendSMS($testPhoneNumber, $testMessage);
    
    // Record end time
    $endTime = microtime(true);
    $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
    
    echo "<p><em>End time: " . date('H:i:s') . "." . sprintf('%03d', ($endTime - floor($endTime)) * 1000) . "</em></p>";
    echo "<p><strong>API Response Time: " . number_format($duration, 2) . " milliseconds</strong></p>";
    
    if ($result) {
        echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724; margin: 20px 0;'>";
        echo "<h4>✓ SMS Sent Successfully!</h4>";
        echo "<p>The optimized SMS has been sent to $testPhoneNumber in " . number_format($duration, 2) . "ms</p>";
        echo "<p><strong>Expected delivery time: 2-5 seconds</strong></p>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24; margin: 20px 0;'>";
        echo "<h4>✗ SMS Failed to Send</h4>";
        echo "<p>There was an error sending the SMS. Check the error logs below.</p>";
        echo "</div>";
    }
    
    echo "<hr>";
    
    // Test notification with immediate SMS
    echo "<h3>Testing Notification with Immediate SMS...</h3>";
    
    // Get a test user
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SELECT id, username, phone_number FROM users WHERE phone_number IS NOT NULL LIMIT 1");
    $testUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($testUser) {
        echo "<p>Using test user: {$testUser['username']} (Phone: {$testUser['phone_number']})</p>";
        
        $startTime2 = microtime(true);
        
        // Create notification with immediate SMS
        $notificationId = $notificationService->createNotification(
            $testUser['id'],
            'URGENT: Your booking has been confirmed! This is a test of immediate SMS delivery.',
            'booking_update',
            null,
            true // Send SMS immediately
        );
        
        $endTime2 = microtime(true);
        $duration2 = ($endTime2 - $startTime2) * 1000;
        
        if ($notificationId) {
            echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724; margin: 20px 0;'>";
            echo "<h4>✓ Notification Created and SMS Sent!</h4>";
            echo "<p>Notification ID: $notificationId</p>";
            echo "<p>Total processing time: " . number_format($duration2, 2) . "ms</p>";
            echo "</div>";
        } else {
            echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24; margin: 20px 0;'>";
            echo "<h4>✗ Failed to create notification</h4>";
            echo "</div>";
        }
    } else {
        echo "<p><em>No users with phone numbers found. Please add a phone number to a user account to test notification SMS.</em></p>";
    }
    
    echo "<hr>";
    
    // Show recent SMS logs
    echo "<h3>Recent SMS Logs (Last 5)</h3>";
    $stmt = $pdo->query("SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 5");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($logs)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
        echo "<tr style='background: #f8f9fa;'><th>Phone</th><th>Status</th><th>Message Preview</th><th>Time</th><th>Error</th></tr>";
        foreach ($logs as $log) {
            $statusColor = $log['status'] === 'sent' ? '#28a745' : '#dc3545';
            $messagePreview = substr($log['message'], 0, 50) . (strlen($log['message']) > 50 ? '...' : '');
            echo "<tr>";
            echo "<td>{$log['phone_number']}</td>";
            echo "<td style='color: $statusColor; font-weight: bold;'>{$log['status']}</td>";
            echo "<td>" . htmlspecialchars($messagePreview) . "</td>";
            echo "<td>{$log['created_at']}</td>";
            echo "<td>" . ($log['error_message'] ?: 'None') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No SMS logs found.</p>";
    }
    
    echo "<h3>Optimization Summary</h3>";
    echo "<div style='background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Performance Improvements Made:</h4>";
    echo "<ul>";
    echo "<li>✓ Reduced cURL timeout from 10s to 5s</li>";
    echo "<li>✓ Added 2s connection timeout</li>";
    echo "<li>✓ Enabled TCP_NODELAY for faster transmission</li>";
    echo "<li>✓ Force fresh connections (no reuse)</li>";
    echo "<li>✓ Removed 0.1s delay between SMS sends</li>";
    echo "<li>✓ SMS now sent immediately when notifications are created</li>";
    echo "<li>✓ Optimized HTTP headers for faster processing</li>";
    echo "</ul>";
    echo "<p><strong>Expected Result:</strong> SMS should now arrive within 2-5 seconds instead of longer delays.</p>";
    echo "</div>";
    
    echo "<h3>Next Steps</h3>";
    echo "<ol>";
    echo "<li>Test with your actual phone number by updating the test phone number above</li>";
    echo "<li>Monitor SMS delivery times in real-world usage</li>";
    echo "<li>Check SMS logs regularly for any delivery issues</li>";
    echo "<li>Consider setting up delivery reports for even better tracking</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24; margin: 20px 0;'>";
    echo "<h4>✗ Test Failed</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
    line-height: 1.6;
}

h1 {
    color: #333;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

h2, h3 {
    color: #495057;
}

table {
    margin: 20px 0;
}

th, td {
    padding: 8px 12px;
    text-align: left;
    border: 1px solid #ddd;
}

th {
    background-color: #f8f9fa;
    font-weight: bold;
}

ul, ol {
    margin: 10px 0;
}

li {
    margin-bottom: 5px;
}

hr {
    margin: 30px 0;
    border: none;
    border-top: 1px solid #ddd;
}
</style>
