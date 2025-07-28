<?php
ob_start();
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

header('Content-Type: application/json');
require '../config/database.php';

$response = ['success' => false, 'message' => ''];

try {
    // Get raw POST data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    // Validate required fields
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received", 400);
    }

    if (!isset($data['reference']) || empty(trim($data['reference']))) {
        throw new Exception("Missing payment reference", 400);
    }

    $reference = trim($data['reference']);
    $owner_id = $_SESSION['user_id'] ?? null;

    if (!$owner_id) {
        throw new Exception("User session expired. Please login again.", 401);
    }

    // Verify Paystack transaction
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "Cache-Control: no-cache"
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $paystackResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Payment verification connection failed: " . $curlError, 503);
    }

    if ($httpStatus !== 200) {
        throw new Exception("Payment verification service unavailable (HTTP $httpStatus)", 503);
    }

    $result = json_decode($paystackResponse);
    if (!$result || json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid payment verification response", 500);
    }

    if (!$result->status || $result->data->status !== 'success') {
        throw new Exception("Payment verification failed: " . ($result->message ?? 'Unknown error'), 402);
    }

    // Get payment amount from Paystack response
    $amount_paid = $result->data->amount / 100; // Convert from kobo to GHS

    // Database operations
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    try {
        // 1. Check if payment already exists to prevent duplicate processing
        $check_stmt = $pdo->prepare("SELECT id FROM room_levy_payments WHERE transaction_id = ?");
        $check_stmt->execute([$reference]);
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("This payment has already been processed", 409);
        }

        // 2. Count pending and expired rooms for this owner
        $count_stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN pr.levy_payment_status = 'pending' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date < CURDATE()) THEN 1 ELSE 0 END) as pending_rooms,
                SUM(CASE WHEN pr.levy_expiry_date IS NOT NULL AND pr.levy_expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_rooms
            FROM property_rooms pr
            JOIN property p ON pr.property_id = p.id
            WHERE p.owner_id = ?
        ");
        $count_stmt->execute([$owner_id]);
        $counts = $count_stmt->fetch();
        
        $pending_rooms = $counts['pending_rooms'] ?? 0;
        $expired_rooms = $counts['expired_rooms'] ?? 0;
        $total_rooms = $pending_rooms + $expired_rooms;

        // 3. Create payment record in room_levy_payments table
        $payment_stmt = $pdo->prepare("
            INSERT INTO room_levy_payments (
                owner_id, 
                payment_reference, 
                amount, 
                transaction_id, 
                payment_method, 
                status, 
                room_count,
                payment_date,
                processed_at,
                duration_days
            ) VALUES (?, ?, ?, ?, 'paystack', 'completed', ?, NOW(), NOW(), 365)
        ");
        $payment_stmt->execute([
            $owner_id,
            $reference,
            $amount_paid,
            $reference,
            $total_rooms
        ]);
        
        $payment_id = $pdo->lastInsertId();

        // 4. Update all pending/expired rooms to paid status
        if ($total_rooms > 0) {
            $update_stmt = $pdo->prepare("
                UPDATE property_rooms pr
                JOIN property p ON pr.property_id = p.id
                SET 
                    pr.levy_payment_status = 'paid',
                    pr.levy_payment_id = ?,
                    pr.transaction_id = ?,
                    pr.payment_date = NOW(),
                    pr.payment_amount = ? / ?,
                    pr.last_renewal_date = IF(pr.levy_payment_status = 'approved', NOW(), NULL)
                WHERE 
                    p.owner_id = ? AND 
                    (
                        (pr.levy_payment_status = 'pending' AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date < CURDATE())) OR 
                        (pr.levy_expiry_date IS NOT NULL AND pr.levy_expiry_date < CURDATE())
                    )
            ");
            $update_stmt->execute([
                $payment_id,
                $reference,
                $amount_paid,
                $total_rooms,
                $owner_id
            ]);
            
            $affected_rooms = $update_stmt->rowCount();
        } else {
            $affected_rooms = 0;
        }

        // 5. Record payment in room_levy_payment_history for each room
        if ($affected_rooms > 0) {
            $history_stmt = $pdo->prepare("
                INSERT INTO room_levy_payment_history (
                    room_id, 
                    payment_id, 
                    payment_date, 
                    expiry_date, 
                    amount, 
                    status
                )
                SELECT 
                    pr.id,
                    ?,
                    NOW(),
                    DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
                    ? / ?,
                    'active'
                FROM property_rooms pr
                JOIN property p ON pr.property_id = p.id
                WHERE p.owner_id = ? AND pr.levy_payment_id = ?
            ");
            $history_stmt->execute([
                $payment_id,
                $amount_paid,
                $total_rooms,
                $owner_id,
                $payment_id
            ]);
        }

        // 6. Create notification for admin to approve the rooms
        $admin_notification_stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id,
                message,
                type,
                notification_type,
                created_at
            ) 
            SELECT 
                id,
                ?,
                'payment_received',
                'in_app',
                NOW()
            FROM users
            WHERE status = 'admin'
        ");
        
        $owner_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $owner_stmt->execute([$owner_id]);
        $owner = $owner_stmt->fetch();
        
        $notification_message = sprintf(
            "Room levy payment received from %s for %d rooms (GHS %.2f). Reference: %s",
            $owner['username'],
            $affected_rooms,
            $amount_paid,
            $reference
        );
        
        $admin_notification_stmt->execute([$notification_message]);
        
        $pdo->commit();

        // Clear any existing payment session data
        unset($_SESSION['room_payment']);

        // Success response
        $response = [
            'success' => true,
            'reference' => $reference,
            'amount_paid' => $amount_paid,
            'rooms_paid' => $affected_rooms,
            'payment_id' => $payment_id,
            'message' => $affected_rooms > 0 
                ? 'Payment processed successfully. ' . $affected_rooms . ' rooms marked as paid and awaiting admin approval.'
                : 'Payment processed successfully. No rooms needed payment.'
        ];

    } catch (PDOException $e) {
        $pdo->rollBack();
        throw new Exception("Database operation failed: " . $e->getMessage(), 500);
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode() ?: 'PAYMENT_ERROR'
    ];
    http_response_code(is_int($e->getCode()) ? $e->getCode() : 500);
}

ob_end_clean();
echo json_encode($response);
exit;