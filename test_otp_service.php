<?php
require_once 'config/database.php';
require_once 'includes/OTPService.php';

// Test the OTP service
$otpService = new OTPService();

// Test phone number (replace with a valid Ghana number for testing)
$testPhoneNumber = '233240687599';

echo "<h2>Testing OTP Service with Arkesel API</h2>";

// Test 1: Send OTP
echo "<h3>Test 1: Sending OTP</h3>";
$sendResult = $otpService->sendOTP($testPhoneNumber, 'registration');

if ($sendResult['success']) {
    echo "<p style='color: green;'>✓ OTP sent successfully!</p>";
    echo "<p>Message: " . $sendResult['message'] . "</p>";
    echo "<p>OTP ID: " . $sendResult['otp_id'] . "</p>";
    
    // Test 2: Verify OTP (you would need to enter the actual OTP received)
    echo "<h3>Test 2: OTP Verification</h3>";
    echo "<p>To test verification, you need to:</p>";
    echo "<ol>";
    echo "<li>Check the SMS received on " . $testPhoneNumber . "</li>";
    echo "<li>Use the OTP code with the verification endpoint</li>";
    echo "</ol>";
    
    // Example verification (uncomment and use actual OTP code)
    /*
    $otpCode = '123456'; // Replace with actual OTP received
    $verifyResult = $otpService->verifyOTP($testPhoneNumber, $otpCode, 'registration');
    
    if ($verifyResult['success']) {
        echo "<p style='color: green;'>✓ OTP verified successfully!</p>";
        echo "<p>Message: " . $verifyResult['message'] . "</p>";
    } else {
        echo "<p style='color: red;'>✗ OTP verification failed!</p>";
        echo "<p>Error: " . $verifyResult['message'] . "</p>";
    }
    */
    
} else {
    echo "<p style='color: red;'>✗ Failed to send OTP!</p>";
    echo "<p>Error: " . $sendResult['message'] . "</p>";
}

// Test 3: Phone number cleaning
echo "<h3>Test 3: Phone Number Cleaning</h3>";
$testNumbers = [
    '0544919953',
    '544919953',
    '233544919953',
    '+233544919953',
    '0544-919-953'
];

foreach ($testNumbers as $number) {
    $reflection = new ReflectionClass($otpService);
    $method = $reflection->getMethod('cleanPhoneNumber');
    $method->setAccessible(true);
    $cleaned = $method->invoke($otpService, $number);
    echo "<p>$number → $cleaned</p>";
}

// Test 4: Phone number validation
echo "<h3>Test 4: Phone Number Validation</h3>";
$testValidation = [
    '233240687599',
    '233123456789',
    '544919953',
    '0544919953',
    '1234567890'
];

foreach ($testValidation as $number) {
    $reflection = new ReflectionClass($otpService);
    $method = $reflection->getMethod('isValidPhoneNumber');
    $method->setAccessible(true);
    $isValid = $method->invoke($otpService, $number);
    $status = $isValid ? '✓ Valid' : '✗ Invalid';
    echo "<p>$number → $status</p>";
}

echo "<h3>API Configuration</h3>";
echo "<p>Base URL: https://sms.arkesel.com/api/otp</p>";
echo "<p>API Key: eHJMYnlrUUV5c29pd2FOaEhmdHo (configured)</p>";
echo "<p>Sender ID: UniHomes</p>";

echo "<h3>Next Steps</h3>";
echo "<ol>";
echo "<li>Check your SMS logs for any delivery reports</li>";
echo "<li>Verify the API key is correct and active</li>";
echo "<li>Test with a real phone number to receive SMS</li>";
echo "<li>Check error logs for detailed API responses</li>";
echo "</ol>";
?>
