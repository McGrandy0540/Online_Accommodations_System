<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require  '../config/database.php';
require  '../config/constants.php';

header('Content-Type: application/json');

// Initialize response array
$response = ['success' => false, 'message' => ''];

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data", 400);
    }

    // Validate required fields with proper type checks
    $required = ['reference', 'amount', 'pending_rooms', 'expired_rooms', 'discount'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: $field", 400);
        }
        
        // Special handling for reference field
        if ($field === 'reference') {
            if (empty(trim($input[$field]))) {
                throw new Exception("Reference cannot be empty", 400);
            }
        } 
        // Numeric fields validation
        else if (!is_numeric($input[$field])) {
            throw new Exception("Invalid value for field: $field", 400);
        }
    }

    $reference = trim($input['reference']);
    $amount = (float)$input['amount'];
    $pending_rooms = (int)$input['pending_rooms'];
    $expired_rooms = (int)$input['expired_rooms'];
    $discount = (float)$input['discount'];
    
    // Ensure we have a valid session
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Session expired - please login again", 401);
    }
    
    $owner_id = $_SESSION['user_id'];

    if ($amount <= 0) {
        throw new Exception("Invalid payment amount", 400);
    }

    // Configure SSL for cURL
    $sslOptions = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    
    // Windows-specific certificate path
    $caBundle = 'C:\\xampp\\php\\extras\\ssl\\cacert.pem';
    
    if (file_exists($caBundle)) {
        $sslOptions[CURLOPT_CAINFO] = $caBundle;
    } else {
        // Fallback to insecure method if certificate not found
        $sslOptions = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        error_log("CA bundle not found at: $caBundle");
    }

    // Verify payment with Paystack API
    $curl = curl_init();
    $curlOptions = [
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "Cache-Control: no-cache",
        ],
    ] + $sslOptions;

    curl_setopt_array($curl, $curlOptions);

    $paystackResponse = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        throw new Exception("cURL Error: " . $err, 500);
    }

    $result = json_decode($paystackResponse);
    if (!$result || !isset($result->status)) {
        throw new Exception("Invalid response from Paystack API. HTTP Code: $httpCode", 500);
    }

    if (!$result->status) {
        $errorMsg = $result->message ?? 'Unknown error';
        if (isset($result->data)) {
            $errorMsg .= " - " . ($result->data->message ?? json_encode($result->data));
        }
        throw new Exception("Paystack API error: $errorMsg", 500);
    }

    if ($result->data->status !== 'success') {
        throw new Exception("Payment failed: " . $result->data->gateway_response, 400);
    }

    // Verify payment amount
    $expectedAmount = $amount * 100; // Convert to kobo
    if ($result->data->amount != $expectedAmount) {
        throw new Exception("Amount mismatch: expected {$expectedAmount}, got {$result->data->amount}", 400);
    }

    // Get database connection
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    try {
        // Create room levy payment record
        $payment_stmt = $pdo->prepare("
            INSERT INTO room_levy_payments (
                owner_id,
                payment_reference,
                amount,
                transaction_id,
                payment_method,
                status,
                room_count,
                payment_date
            ) VALUES (?, ?, ?, ?, 'paystack', 'completed', ?, NOW())
        ");
        
        $room_count = $pending_rooms + $expired_rooms;
        $payment_stmt->execute([
            $owner_id,
            $reference,
            $amount,
            $reference,
            $room_count
        ]);
        
        $payment_id = $pdo->lastInsertId();

        // Update rooms status (both pending AND expired rooms)
        $update_stmt = $pdo->prepare("
            UPDATE property_rooms pr
            JOIN property p ON pr.property_id = p.id
            SET 
                pr.levy_payment_status = 'paid',
                pr.levy_payment_id = ?,
                pr.payment_date = NOW(),
                pr.transaction_id = ?,
                pr.payment_amount = 50.00
            WHERE 
                p.owner_id = ? 
                AND (
                    (pr.levy_payment_status = 'pending' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date < CURDATE()))
                    OR (pr.levy_expiry_date IS NOT NULL AND pr.levy_expiry_date < CURDATE())
                )
        ");
        $update_stmt->execute([$payment_id, $reference, $owner_id]);

        // Commit transaction
        $pdo->commit();

        $response = [
            'success' => true,
            'message' => 'Payment verified and processed successfully',
            'reference' => $reference,
            'payment_id' => $payment_id,
            'updated_rooms' => $update_stmt->rowCount(),
            'pending_rooms' => $pending_rooms,
            'expired_rooms' => $expired_rooms
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ];
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
}

ob_end_clean();
echo json_encode($response);
exit;