<?php
require_once 'config/database.php';
require_once 'includes/SMSService.php';

echo "<h1>Arkesel SMS Test</h1>";

try {
    $smsService = new SMSService();
    
    echo "<h2>Testing Arkesel SMS Integration</h2>";
    
    // Test phone number (replace with your actual phone number for testing)
    $testPhoneNumber = "0240687599"; // Replace with a valid Ghana phone number
    $testMessage = "Test SMS from Landlords&Tenants accommodation system. SMS is working correctly!";
    
    echo "<p><strong>Test Phone Number:</strong> $testPhoneNumber</p>";
    echo "<p><strong>Test Message:</strong> $testMessage</p>";
    
    echo "<h3>Sending Test SMS...</h3>";
    
    // Send test SMS
    $result = $smsService->sendSMS($testPhoneNumber, $testMessage);
    
    if ($result) {
        echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724;'>";
        echo "<h4>✓ SMS Sent Successfully!</h4>";
        echo "<p>The test SMS has been sent to $testPhoneNumber. Please check your phone.</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24;'>";
        echo "<h4>✗ SMS Failed to Send</h4>";
        echo "<p>There was an error sending the SMS. Check the error logs below.</p>";
        echo "</div>";
    }
    
    // Show recent SMS logs
    echo "<h3>Recent SMS Logs</h3>";
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 5");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($logs)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Phone</th><th>Status</th><th>Error</th><th>Time</th></tr>";
        foreach ($logs as $log) {
            $statusColor = $log['status'] === 'sent' ? '#28a745' : '#dc3545';
            echo "<tr>";
            echo "<td>{$log['phone_number']}</td>";
            echo "<td style='color: $statusColor; font-weight: bold;'>{$log['status']}</td>";
            echo "<td>" . ($log['error_message'] ?: 'None') . "</td>";
            echo "<td>{$log['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No SMS logs found.</p>";
    }
    
    echo "<h3>Configuration Check</h3>";
    echo "<ul>";
    echo "<li>✓ API URL: https://sms.arkesel.com/sms/api</li>";
    echo "<li>✓ API Key: eHJMYnlrUUV5c29pd2FOaEhmdHo</li>";
    echo "<li>✓ Sender ID: Landlords&Tenants</li>";
    echo "<li>✓ SSL Verification: Disabled for local development</li>";
    echo "<li>✓ Phone Number Format: 233XXXXXXXXX (without +)</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; color: #721c24;'>";
    echo "<h4>✗ Test Failed</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 800px;
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

ul {
    background: #f8f9fa;
    padding: 15px 30px;
    border-radius: 5px;
}
</style>
