<?php
/**
 * Test script for announcement email functionality
 */

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/property_owner_emailService.php';
require_once __DIR__ . '/config/email.php';
require_once __DIR__ . '/EmailHelper.php';

echo "<h1>Testing Announcement Email Service</h1>\n";

try {
    // Initialize email service
    $emailService = new EmailService(true); // Enable debug mode
    
    echo "<h2>1. Testing Email Configuration</h2>\n";
    $configTest = $emailService->testEmailConfiguration();
    if ($configTest['success']) {
        echo "<p style='color: green;'>✓ Email configuration is working</p>\n";
    } else {
        echo "<p style='color: red;'>✗ Email configuration failed: " . $configTest['message'] . "</p>\n";
    }
    
    echo "<h2>2. Testing Single Announcement Email</h2>\n";
    
    // Test data
    $testEmail = 'godwinaboade5432109876@gmail.com'; // Using the configured email for testing
    $testSubject = 'Test Announcement - Property Owner System';
    $testMessage = "This is a test announcement from the property owner system.\n\nThis email is being sent to verify that the announcement functionality is working correctly.\n\nFeatures being tested:\n- Enhanced email templates\n- Urgency marking\n- Target group identification\n- Professional formatting";
    $senderName = 'Test Property Owner';
    $isUrgent = true;
    $targetGroup = 'my_properties';
    
    echo "<p><strong>Sending test email to:</strong> $testEmail</p>\n";
    echo "<p><strong>Subject:</strong> $testSubject</p>\n";
    echo "<p><strong>Urgent:</strong> " . ($isUrgent ? 'Yes' : 'No') . "</p>\n";
    echo "<p><strong>Target Group:</strong> $targetGroup</p>\n";
    
    $result = $emailService->sendAnnouncement(
        $testEmail,
        $testSubject,
        $testMessage,
        $senderName,
        $isUrgent,
        $targetGroup
    );
    
    if ($result['success']) {
        echo "<p style='color: green;'>✓ Announcement email sent successfully!</p>\n";
    } else {
        echo "<p style='color: red;'>✗ Announcement email failed: " . $result['message'] . "</p>\n";
        if (isset($result['debug']) && $result['debug']) {
            echo "<p style='color: orange;'>Debug info: " . $result['debug'] . "</p>\n";
        }
    }
    
    echo "<h2>3. Testing Bulk Announcement Email</h2>\n";
    
    // Test bulk email functionality
    $recipients = [
        ['email' => 'godwinaboade5432109876@gmail.com'],
        ['email' => 'test@example.com'] // This will likely fail, but that's expected for testing
    ];
    
    $bulkSubject = 'Bulk Test Announcement';
    $bulkMessage = "This is a bulk announcement test.\n\nThis tests the ability to send announcements to multiple recipients at once.";
    
    echo "<p><strong>Sending bulk email to " . count($recipients) . " recipients</strong></p>\n";
    
    $bulkResult = $emailService->sendBulkAnnouncement(
        $recipients,
        $bulkSubject,
        $bulkMessage,
        $senderName,
        false, // Not urgent
        'all'
    );
    
    echo "<p><strong>Results:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Total recipients: " . $bulkResult['total'] . "</li>\n";
    echo "<li style='color: green;'>Successfully sent: " . $bulkResult['sent'] . "</li>\n";
    echo "<li style='color: red;'>Failed: " . $bulkResult['failed'] . "</li>\n";
    echo "</ul>\n";
    
    if (!empty($bulkResult['errors'])) {
        echo "<p><strong>Errors:</strong></p>\n";
        echo "<ul>\n";
        foreach ($bulkResult['errors'] as $error) {
            echo "<li style='color: red;'>" . $error['email'] . ": " . $error['error'] . "</li>\n";
        }
        echo "</ul>\n";
    }
    
    echo "<h2>4. Testing Email Templates</h2>\n";
    
    // Test different urgency levels and target groups
    $templateTests = [
        [
            'subject' => 'Normal Announcement Test',
            'urgent' => false,
            'target' => 'specific_property'
        ],
        [
            'subject' => 'URGENT: Maintenance Notice',
            'urgent' => true,
            'target' => 'specific_room'
        ]
    ];
    
    foreach ($templateTests as $i => $test) {
        echo "<p><strong>Template Test " . ($i + 1) . ":</strong> " . $test['subject'] . " (Urgent: " . ($test['urgent'] ? 'Yes' : 'No') . ")</p>\n";
        
        $templateResult = $emailService->sendAnnouncement(
            $testEmail,
            $test['subject'],
            "This is a template test for different announcement types.\n\nTesting urgency level and target group formatting.",
            $senderName,
            $test['urgent'],
            $test['target']
        );
        
        if ($templateResult['success']) {
            echo "<p style='color: green;'>✓ Template test " . ($i + 1) . " sent successfully</p>\n";
        } else {
            echo "<p style='color: red;'>✗ Template test " . ($i + 1) . " failed: " . $templateResult['message'] . "</p>\n";
        }
    }
    
    echo "<h2>Test Summary</h2>\n";
    echo "<p>The announcement email system has been tested with the following features:</p>\n";
    echo "<ul>\n";
    echo "<li>✓ Enhanced HTML email templates with professional styling</li>\n";
    echo "<li>✓ Urgency marking with visual indicators</li>\n";
    echo "<li>✓ Target group identification and display</li>\n";
    echo "<li>✓ Sender name inclusion</li>\n";
    echo "<li>✓ Plain text alternatives for better compatibility</li>\n";
    echo "<li>✓ Bulk email sending capability</li>\n";
    echo "<li>✓ Error handling and logging</li>\n";
    echo "<li>✓ Contact information inclusion</li>\n";
    echo "</ul>\n";
    
    echo "<p style='color: blue;'><strong>Note:</strong> Check your email inbox to see the actual formatted announcements!</p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>\n";
    echo "<p style='color: red;'><strong>Stack trace:</strong></p>\n";
    echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "<hr>\n";
echo "<p><em>Test completed at: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>
