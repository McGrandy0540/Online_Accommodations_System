<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'

]);

require __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../config/constants.php';

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





    // 4. Process database operations
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
   
    $payment_data = $_SESSION['booking_payment'];

    foreach ($payment_data['booking_ids'] as $booking_id) {
        $booking_fee = $payment_data['booking_fees'][$booking_id];
        
        // Update booking
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'paid' WHERE id = ? AND user_id = ?");
        $stmt->execute([$booking_id, $payment_data['student_id']]);
        
        // Create payment
        $stmt = $pdo->prepare("INSERT INTO payments 
            (booking_id, amount, payment_method, status, transaction_id, created_at)
            VALUES (?, ?, 'paystack', 'completed', ?, NOW())");
        $stmt->execute([$booking_id, $booking_fee, $reference]);
    }

    $pdo->commit();
    unset($_SESSION['booking_payment']);
    
    $data = [
        'success' => true,
        'reference' => $reference,
        'message' => 'Payment processed successfully'
    ];

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $data = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ];
    http_response_code($e->getCode() ?: 500);
}

ob_end_clean();
echo json_encode($data);
exit;

