<?php
require_once 'config/database.php';

try {
    $pdo = Database::getInstance();
    
    // Check if sms_logs table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'sms_logs'");
    if ($stmt->rowCount() > 0) {
        echo "SMS Logs Table exists\n";
        
        // Get recent SMS logs
        $stmt = $pdo->query("SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 10");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($logs)) {
            echo "No SMS logs found\n";
        } else {
            echo "Recent SMS Logs:\n";
            foreach ($logs as $log) {
                echo "ID: {$log['id']}, Phone: {$log['phone_number']}, Status: {$log['status']}, Created: {$log['created_at']}";
                if ($log['error_message']) {
                    echo ", Error: {$log['error_message']}";
                }
                echo "\n";
            }
        }
        
        // Get stats by status
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM sms_logs GROUP BY status");
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nSMS Status Statistics:\n";
        foreach ($stats as $stat) {
            echo "{$stat['status']}: {$stat['count']}\n";
        }
        
        // Get unique phone numbers that have been attempted
        $stmt = $pdo->query("SELECT DISTINCT phone_number, COUNT(*) as attempts FROM sms_logs GROUP BY phone_number ORDER BY attempts DESC");
        $phones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nPhone Numbers Attempted:\n";
        foreach ($phones as $phone) {
            echo "Phone: {$phone['phone_number']}, Attempts: {$phone['attempts']}\n";
        }
        
    } else {
        echo "SMS Logs table does not exist\n";
    }
    
    // Check users table for phone numbers
    echo "\nChecking users table for phone numbers:\n";
    $stmt = $pdo->query("SELECT id, username, phone_number FROM users WHERE phone_number IS NOT NULL AND phone_number != '' LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "No users with phone numbers found\n";
    } else {
        foreach ($users as $user) {
            echo "User ID: {$user['id']}, Username: {$user['username']}, Phone: {$user['phone_number']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
