<?php
require_once 'config/database.php';

try {
    $pdo = Database::getInstance();
    
    // Get failed SMS logs with error messages
    $stmt = $pdo->query("SELECT phone_number, status, error_message, created_at FROM sms_logs WHERE status IN ('failed', 'error') ORDER BY created_at DESC LIMIT 20");
    $failedLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Recent Failed SMS Attempts:\n";
    echo "============================\n";
    
    if (empty($failedLogs)) {
        echo "No failed SMS logs found\n";
    } else {
        foreach ($failedLogs as $log) {
            echo "Phone: {$log['phone_number']}\n";
            echo "Status: {$log['status']}\n";
            echo "Error: " . ($log['error_message'] ?: 'No error message') . "\n";
            echo "Time: {$log['created_at']}\n";
            echo "---\n";
        }
    }
    
    // Check phone number formatting issues
    echo "\nPhone Number Format Analysis:\n";
    echo "=============================\n";
    
    $stmt = $pdo->query("SELECT DISTINCT phone_number FROM sms_logs");
    $phones = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($phones as $phone) {
        $formatted = formatPhoneNumber($phone);
        $isValid = isValidPhoneNumber($formatted);
        echo "Original: '$phone' -> Formatted: '$formatted' -> Valid: " . ($isValid ? 'YES' : 'NO') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

function formatPhoneNumber($phoneNumber) {
    // Remove any spaces, dashes, or other characters
    $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
    
    // Handle Ghana phone numbers
    if (preg_match('/^0/', $phoneNumber)) {
        // Convert 0XXXXXXXXX to +233XXXXXXXXX
        return '+233' . substr($phoneNumber, 1);
    } elseif (preg_match('/^233/', $phoneNumber)) {
        // Add + if missing
        return '+' . $phoneNumber;
    } elseif (!preg_match('/^\+/', $phoneNumber)) {
        // Assume it's a Ghana number without country code
        return '+233' . $phoneNumber;
    }
    
    return $phoneNumber;
}

function isValidPhoneNumber($phoneNumber) {
    // Ghana phone numbers: +233XXXXXXXXX (9 digits after country code)
    return preg_match('/^\+233[0-9]{9}$/', $phoneNumber);
}
?>
