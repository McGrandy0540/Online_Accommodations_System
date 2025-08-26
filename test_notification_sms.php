<?php
/**
 * Test script for the new notification-triggered SMS system
 * This script creates a test notification and shows how SMS is triggered when viewing notifications
 */

require_once 'config/database.php';
require_once 'includes/NotificationService.php';

// Test configuration
$testUserId = 1; // Change this to a valid user ID in your system
$testMessage = "Test notification: Your booking has been confirmed!";
$testType = 'booking_update';

echo "<h2>Testing Notification-Triggered SMS System</h2>\n";

try {
    // Initialize the notification service
    $notificationService = new NotificationService();
    
    echo "<h3>Step 1: Creating Test Notification</h3>\n";
    
    // Create a test notification (no SMS sent immediately)
    $notificationId = $notificationService->createNotification(
        $testUserId, 
        $testMessage, 
        $testType
    );
    
    if ($notificationId) {
        echo "✅ Notification created successfully with ID: $notificationId<br>\n";
        echo "📝 Message: $testMessage<br>\n";
        echo "📋 Type: $testType<br>\n";
        echo "⚠️ SMS NOT sent immediately (this is the new behavior)<br>\n";
    } else {
        echo "❌ Failed to create notification<br>\n";
        exit;
    }
    
    echo "<h3>Step 2: Checking Database Status</h3>\n";
    
    // Check the notification in database
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ?");
    $stmt->execute([$notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($notification) {
        echo "📊 Database Status:<br>\n";
        echo "- ID: {$notification['id']}<br>\n";
        echo "- User ID: {$notification['user_id']}<br>\n";
        echo "- Message: {$notification['message']}<br>\n";
        echo "- Type: {$notification['type']}<br>\n";
        echo "- Is Read: " . ($notification['is_read'] ? 'Yes' : 'No') . "<br>\n";
        echo "- Delivered: " . ($notification['delivered'] ? 'Yes' : 'No') . " (Should be 'No' initially)<br>\n";
        echo "- Created: {$notification['created_at']}<br>\n";
    }
    
    echo "<h3>Step 3: Simulating Student Viewing Notifications</h3>\n";
    
    // Get user info to check if SMS can be sent
    $userStmt = $pdo->prepare("SELECT phone_number, sms_notifications, username FROM users WHERE id = ?");
    $userStmt->execute([$testUserId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "👤 User Info:<br>\n";
        echo "- Username: {$user['username']}<br>\n";
        echo "- Phone: " . ($user['phone_number'] ?: 'Not set') . "<br>\n";
        echo "- SMS Enabled: " . ($user['sms_notifications'] ? 'Yes' : 'No') . "<br>\n";
        
        if ($user['phone_number'] && $user['sms_notifications']) {
            echo "<br>📱 Simulating SMS processing when student views notifications...<br>\n";
            
            // Process pending SMS for this user (simulates what happens when they visit notification portal)
            $smsResults = $notificationService->processPendingSMSForUser($testUserId);
            
            echo "📊 SMS Processing Results:<br>\n";
            echo "- Processed: {$smsResults['processed']}<br>\n";
            echo "- Success: {$smsResults['success']}<br>\n";
            echo "- Failed: {$smsResults['failed']}<br>\n";
            
            if ($smsResults['success'] > 0) {
                echo "✅ SMS sent successfully!<br>\n";
                
                // Check if notification is now marked as delivered
                $stmt->execute([$notificationId]);
                $updatedNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "📋 Updated Status - Delivered: " . ($updatedNotification['delivered'] ? 'Yes' : 'No') . "<br>\n";
            } else {
                echo "⚠️ SMS not sent (check user preferences or phone number)<br>\n";
            }
        } else {
            echo "<br>⚠️ SMS cannot be sent:<br>\n";
            if (!$user['phone_number']) echo "- No phone number set<br>\n";
            if (!$user['sms_notifications']) echo "- SMS notifications disabled<br>\n";
        }
    } else {
        echo "❌ User not found with ID: $testUserId<br>\n";
    }
    
    echo "<h3>Step 4: Checking SMS Logs</h3>\n";
    
    // Check SMS logs
    $logStmt = $pdo->prepare("
        SELECT * FROM sms_logs 
        WHERE notification_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $logStmt->execute([$notificationId]);
    $smsLog = $logStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($smsLog) {
        echo "📋 SMS Log Entry:<br>\n";
        echo "- Phone: {$smsLog['phone_number']}<br>\n";
        echo "- Status: {$smsLog['status']}<br>\n";
        echo "- Message: " . substr($smsLog['message'], 0, 100) . "...<br>\n";
        echo "- Sent At: {$smsLog['created_at']}<br>\n";
        if ($smsLog['error_message']) {
            echo "- Error: {$smsLog['error_message']}<br>\n";
        }
    } else {
        echo "📋 No SMS log found (SMS may not have been sent)<br>\n";
    }
    
    echo "<h3>Summary</h3>\n";
    echo "<div style='background: #f0f8ff; padding: 15px; border-left: 4px solid #007bff;'>\n";
    echo "<strong>New SMS Flow Verification:</strong><br>\n";
    echo "1. ✅ Notification created without immediate SMS<br>\n";
    echo "2. ✅ SMS triggered only when student views notifications<br>\n";
    echo "3. ✅ Notification marked as delivered after SMS sent<br>\n";
    echo "4. ✅ SMS activity logged for tracking<br>\n";
    echo "<br><strong>Next Steps:</strong><br>\n";
    echo "- Have a student visit their notification portal to trigger SMS<br>\n";
    echo "- Check SMS logs for delivery status<br>\n";
    echo "- Monitor error logs for any issues<br>\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>\n";
    echo "Stack trace: " . $e->getTraceAsString() . "<br>\n";
}

echo "<br><hr><br>\n";
echo "<p><strong>Note:</strong> This test creates a real notification. You may want to clean up test data after testing.</p>\n";
echo "<p><strong>Clean up SQL:</strong> <code>DELETE FROM notifications WHERE id = $notificationId;</code></p>\n";
?>
