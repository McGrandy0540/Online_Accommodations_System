<?php
require_once 'config/database.php';
require_once 'includes/OTPService.php';

// Test the OTP service
$otpService = new OTPService();

// Test phone number (replace with a valid Ghana number for testing)
$testPhoneNumber = '233240687599'; // The number from your error

echo "<h2>Testing OTP Service</h2>";
echo "<p>Testing with phone number: " . $testPhoneNumber . "</p>";

// Test sending OTP
echo "<h3>1. Testing OTP Send</h3>";
$result = $otpService->sendOTP($testPhoneNumber, 'test');

if ($result['success']) {
    echo "<p style='color: green;'>✓ OTP sent successfully!</p>";
    echo "<p>Message: " . $result['message'] . "</p>";
    
    // Prompt for OTP verification
    echo "<h3>2. OTP Verification Test</h3>";
    echo "<p>Check your phone for the OTP and enter it below:</p>";
    echo "<form method='post'>";
    echo "<input type='text' name='otp_code' placeholder='Enter OTP' maxlength='6' required>";
    echo "<input type='hidden' name='phone_number' value='" . $testPhoneNumber . "'>";
    echo "<button type='submit' name='verify_otp'>Verify OTP</button>";
    echo "</form>";
    
} else {
    echo "<p style='color: red;'>✗ OTP send failed!</p>";
    echo "<p>Error: " . $result['message'] . "</p>";
}

// Handle OTP verification
if (isset($_POST['verify_otp'])) {
    $otpCode = $_POST['otp_code'];
    $phoneNumber = $_POST['phone_number'];
    
    echo "<h3>Verifying OTP: " . $otpCode . "</h3>";
    
    $verifyResult = $otpService->verifyOTP($phoneNumber, $otpCode, 'test');
    
    if ($verifyResult['success']) {
        echo "<p style='color: green;'>✓ OTP verified successfully!</p>";
        echo "<p>Message: " . $verifyResult['message'] . "</p>";
    } else {
        echo "<p style='color: red;'>✗ OTP verification failed!</p>";
        echo "<p>Error: " . $verifyResult['message'] . "</p>";
    }
}

echo "<hr>";
echo "<h3>API Configuration Check</h3>";
echo "<p>API Key: ZWVKT1VnSnlNWXFWVkhKUlFQcUs</p>";
echo "<p>API URL: https://sms.arkesel.com/api/otp/generate</p>";
echo "<p>Phone Number Format: " . $testPhoneNumber . "</p>";

// Test direct API call
echo "<h3>Direct API Test</h3>";
$fields = [
    'expiry' => 5,
    'length' => 6,
    'medium' => 'sms',
    'message' => 'Your test verification code is, %otp_code%',
    'number' => $testPhoneNumber,
    'sender_id' => 'Landlords',
    'type' => 'numeric'
];

$postvars = '';
foreach($fields as $key => $value) {
    $postvars .= $key . "=" . urlencode($value) . "&";
}
$postvars = rtrim($postvars, '&');

$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://sms.arkesel.com/api/otp/generate',
    CURLOPT_HTTPHEADER => array('api-key: ZWVKT1VnSnlNWXFWVkhKUlFQcUs'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => $postvars,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
));

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

echo "<p><strong>HTTP Code:</strong> " . $httpCode . "</p>";
echo "<p><strong>Response:</strong> " . htmlspecialchars($response) . "</p>";
if ($curlError) {
    echo "<p><strong>CURL Error:</strong> " . $curlError . "</p>";
}

$responseData = json_decode($response, true);
if ($responseData) {
    echo "<p><strong>Parsed Response:</strong></p>";
    echo "<pre>" . print_r($responseData, true) . "</pre>";
} else {
    echo "<p><strong>JSON Parse Error:</strong> " . json_last_error_msg() . "</p>";
}
?>
