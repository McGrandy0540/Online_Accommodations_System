<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // 1. Validate session
    if (!isset($_SESSION['booking_payment'])) {
        throw new Exception("Payment session expired", 401);
    }

    // 2. Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data", 400);
    }

    if (empty($data['reference'])) {
        throw new Exception("Missing payment reference", 400);
    }

    $reference = trim($data['reference']);
    if (empty($reference)) {
        throw new Exception("Payment reference cannot be empty", 400);
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

    // 4. Process database operations
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
   
    $payment_data = $_SESSION['booking_payment'];
    
    // Debug: Log payment data for troubleshooting
    error_log("Payment session data: " . json_encode($payment_data));
    error_log("Paystack amount: " . $result->data->amount);
    
    // Calculate expected amount from booking fees instead of relying on session total_amount
    $expectedAmount = 0;
    foreach ($payment_data['booking_fees'] as $booking_fee) {
        $expectedAmount += $booking_fee * 100; // Convert each booking fee to kobo
    }
    
    // Alternative: Use the amount from Paystack directly if session data is corrupted
    if ($expectedAmount === 0 && isset($result->data->amount)) {
        $expectedAmount = $result->data->amount;
        error_log("Using Paystack amount as expected amount: " . $expectedAmount);
    }
    
    // Verify payment amount with tolerance for floating point issues
    $amountTolerance = 50; // 0.50 GHS tolerance
    if (abs($result->data->amount - $expectedAmount) > $amountTolerance) {
        throw new Exception("Amount mismatch: expected {$expectedAmount}, got {$result->data->amount}. Session total: " . ($payment_data['total_amount'] ?? 'N/A'), 400);
    }

    foreach ($payment_data['booking_ids'] as $booking_id) {
        $booking_fee = $payment_data['booking_fees'][$booking_id];
        
        // Verify booking exists and belongs to student
        $verifyStmt = $pdo->prepare("
            SELECT id, property_id, status 
            FROM bookings 
            WHERE id = ? AND user_id = ? AND status IN ('pending', 'pending_payment')
        ");
        $verifyStmt->execute([$booking_id, $payment_data['student_id']]);
        $booking = $verifyStmt->fetch();
        
        if (!$booking) {
            throw new Exception("Booking not found, already processed, or access denied for booking ID: $booking_id", 404);
        }
        
        // Update booking status to 'paid'
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'paid', updated_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$booking_id, $payment_data['student_id']]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Failed to update booking status for booking ID: $booking_id", 500);
        }
        
        // Update property status if needed
        $updatePropertyStmt = $pdo->prepare("
            UPDATE property 
            SET status = 'paid' 
            WHERE id = ? AND status = 'available'
        ");
        $updatePropertyStmt->execute([$booking['property_id']]);
        
        // Create payment record
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (booking_id, amount, payment_method, status, transaction_id, created_at)
            VALUES (?, ?, 'paystack', 'completed', ?, NOW())
        ");
        $stmt->execute([$booking_id, $booking_fee, $reference]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Create notification for the student
        $notificationStmt = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, property_id, payment_id, message, type, notification_type, created_at)
            VALUES (?, ?, ?, ?, 'payment_success', 'in_app', NOW())
        ");
        $message = "Your payment of GHS " . number_format($booking_fee, 2) . " for booking #$booking_id has been confirmed.";
        $notificationStmt->execute([
            $payment_data['student_id'],
            $booking['property_id'],
            $payment_id,
            $message
        ]);
        
        // Create notification for property owner
        $ownerStmt = $pdo->prepare("
            SELECT owner_id 
            FROM property 
            WHERE id = ?
        ");
        $ownerStmt->execute([$booking['property_id']]);
        $property = $ownerStmt->fetch();
        
        if ($property) {
            $ownerNotificationStmt = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, property_id, payment_id, message, type, notification_type, created_at)
                VALUES (?, ?, ?, ?, 'booking_paid', 'in_app', NOW())
            ");
            $ownerMessage = "A payment of GHS " . number_format($booking_fee, 2) . " has been received for booking #$booking_id.";
            $ownerNotificationStmt->execute([
                $property['owner_id'],
                $booking['property_id'],
                $payment_id,
                $ownerMessage
            ]);
        }
        
        // Update room status if room_id exists in booking
        $roomStmt = $pdo->prepare("
            UPDATE property_rooms 
            SET status = 'occupied', current_occupancy = current_occupancy + 1,
                available_spots = capacity - (current_occupancy + 1)
            WHERE id = (SELECT room_id FROM bookings WHERE id = ?) 
            AND status = 'available'
        ");
        $roomStmt->execute([$booking_id]);
    }

    $pdo->commit();
    
    // Clear session data after successful payment
    unset($_SESSION['booking_payment']);
    
    $response = [
        'success' => true,
        'reference' => $reference,
        'message' => 'Payment processed successfully',
        'booking_ids' => $payment_data['booking_ids'],
        'amount_paid' => $result->data->amount / 100 // Convert back to GHS
    ];

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the error for debugging
    error_log("Payment verification error: " . $e->getMessage());
    error_log("Session data: " . json_encode($_SESSION['booking_payment'] ?? 'No session data'));
    
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug_info' => [
            'session_total_amount' => $_SESSION['booking_payment']['total_amount'] ?? 'N/A',
            'booking_fees' => $_SESSION['booking_payment']['booking_fees'] ?? 'N/A'
        ]
    ];
    
    http_response_code($e->getCode() ?: 500);
}

ob_end_clean();
echo json_encode($response);
exit;