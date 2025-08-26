<?php
echo "<h2>Testing Both API Keys with Arkesel OTP API</h2>";

// Test both API keys
$keys = [
    'OTP Key (your provided)' => 'bnZrQXp4T0V2b3NkRXBseXJWUHY',
    'SMS Key (working)' => 'eHJMYnlrUUV5c29pd2FOaEhmdHo'
];

$testPhoneNumber = '233240687599';

foreach ($keys as $keyName => $apiKey) {
    echo "<h3>Testing: $keyName</h3>";
    echo "<p>Key: $apiKey</p>";
    
    // Test OTP generation
    $fields = [
        'expiry' => 5,
        'length' => 6,
        'medium' => 'sms',
        'message' => 'Test OTP: %otp_code%',
        'number' => $testPhoneNumber,
        'sender_id' => 'Test',
        'type' => 'numeric',
    ];
    
    $postvars = '';
    foreach($fields as $key => $value) {
        $postvars .= $key . "=" . $value . "&";
    }
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://sms.arkesel.com/api/otp/generate',
        CURLOPT_HTTPHEADER => array(
            'api-key: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $postvars,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ));
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    
    echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
    echo "<p><strong>Response:</strong> " . htmlspecialchars($response) . "</p>";
    
    if ($curlError) {
        echo "<p><strong>CURL Error:</strong> $curlError</p>";
    }
    
    $responseData = json_decode($response, true);
    if ($responseData) {
        if (isset($responseData['status'])) {
            if ($responseData['status'] === 'success') {
                echo "<p style='color: green;'>✓ <strong>SUCCESS!</strong> This key works for OTP API</p>";
            } else {
                echo "<p style='color: red;'>✗ <strong>FAILED:</strong> " . ($responseData['message'] ?? 'Unknown error') . "</p>";
            }
        }
    }
    
    echo "<hr>";
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>If the SMS key works for OTP, use that key in your OTPService</li>";
echo "<li>If neither works, contact Arkesel support to verify your OTP API access</li>";
echo "<li>Check if you need to activate OTP service separately from SMS service</li>";
echo "<li>Verify if you have sufficient credits for OTP service</li>";
echo "</ul>";
?>
