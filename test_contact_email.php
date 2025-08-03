<?php
/**
 * Test script for contact form email functionality
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once 'config/database.php';
require_once 'config/email.php';
require_once 'EmailHelper.php';
require_once 'includes/EmailService.php';
require_once 'vendor/autoload.php';

echo "<h2>Contact Form Email Test</h2>";

try {
    // Test 1: Check if admin email can be retrieved
    echo "<h3>Test 1: Admin Email Retrieval</h3>";
    $admin_email = EmailHelper::getAdminEmail();
    echo "Admin Email: " . htmlspecialchars($admin_email) . "<br>";
    
    if (empty($admin_email)) {
        echo "<span style='color: red;'>❌ FAILED: No admin email found</span><br>";
    } else {
        echo "<span style='color: green;'>✅ SUCCESS: Admin email retrieved</span><br>";
    }
    
    // Test 2: Initialize EmailService
    echo "<h3>Test 2: EmailService Initialization</h3>";
    $emailService = new EmailService(true); // Debug mode
    echo "<span style='color: green;'>✅ SUCCESS: EmailService initialized</span><br>";
    
    // Test 3: Test email configuration
    echo "<h3>Test 3: Email Configuration Test</h3>";
    $configTest = $emailService->testEmailConfiguration();
    if ($configTest['success']) {
        echo "<span style='color: green;'>✅ SUCCESS: " . htmlspecialchars($configTest['message']) . "</span><br>";
    } else {
        echo "<span style='color: red;'>❌ FAILED: " . htmlspecialchars($configTest['message']) . "</span><br>";
    }
    
    // Test 4: Send test contact email
    echo "<h3>Test 4: Send Test Contact Email</h3>";
    $testResult = $emailService->sendContactFormEmail(
        'Test User',
        'test@example.com',
        'Test Subject',
        'This is a test message from the contact form.'
    );
    
    if ($testResult['success']) {
        echo "<span style='color: green;'>✅ SUCCESS: " . htmlspecialchars($testResult['message']) . "</span><br>";
    } else {
        echo "<span style='color: red;'>❌ FAILED: " . htmlspecialchars($testResult['message']) . "</span><br>";
        if (isset($testResult['debug'])) {
            echo "<pre>Debug Info: " . htmlspecialchars($testResult['debug']) . "</pre>";
        }
    }
    
    // Test 5: Send test confirmation email
    echo "<h3>Test 5: Send Test Confirmation Email</h3>";
    $confirmResult = $emailService->sendConfirmationEmail(
        'Test User',
        'test@example.com',
        'This is a test message from the contact form.'
    );
    
    if ($confirmResult['success']) {
        echo "<span style='color: green;'>✅ SUCCESS: " . htmlspecialchars($confirmResult['message']) . "</span><br>";
    } else {
        echo "<span style='color: red;'>❌ FAILED: " . htmlspecialchars($confirmResult['message']) . "</span><br>";
        if (isset($confirmResult['debug'])) {
            echo "<pre>Debug Info: " . htmlspecialchars($confirmResult['debug']) . "</pre>";
        }
    }
    
} catch (Exception $e) {
    echo "<span style='color: red;'>❌ EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    echo "<pre>Stack Trace: " . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h3>Configuration Summary</h3>";
echo "SMTP Host: " . SMTP_HOST . "<br>";
echo "SMTP Port: " . SMTP_PORT . "<br>";
echo "SMTP Username: " . SMTP_USERNAME . "<br>";
echo "SMTP Encryption: " . SMTP_ENCRYPTION . "<br>";
echo "Default Admin Email: " . DEFAULT_ADMIN_EMAIL . "<br>";
echo "Default From Email: " . DEFAULT_FROM_EMAIL . "<br>";
?>
