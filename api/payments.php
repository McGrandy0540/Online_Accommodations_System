<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/paystack.php'; // Contains Paystack API key
session_start();

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'initialize_payment':
            $data = json_decode(file_get_contents('php://input'), true);
            $bookingId = $data['booking_id'];
            $amount = $data['amount'] * 100; // Paystack uses kobo (multiply by 100)
            $email = $data['email']; // Paystack requires customer email
            
            // Validate booking belongs to user
            $stmt = $db->prepare("SELECT id FROM bookings WHERE user_id = ? AND id = ?");
            $stmt->execute([$userId, $bookingId]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid booking']);
                break;
            }
            
            // Initialize Paystack payment
            $url = "https://api.paystack.co/transaction/initialize";
            $fields = [
                'email' => $email,
                'amount' => $amount,
                'currency' => 'GHS', // Change to your currency
                'reference' => 'BOOK_' . $bookingId . '_' . time(),
                'metadata' => [
                    'booking_id' => $bookingId,
                    'user_id' => $userId
                ],
                'callback_url' => 'https://yourdomain.com/payment-callback.php'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            
            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }
            
            $result = json_decode($response);
            
            if (!$result->status) {
                throw new Exception("Paystack Error: " . $result->message);
            }
            
            // Store payment reference in database temporarily
            $stmt = $db->prepare("
                INSERT INTO payment_references 
                (booking_id, reference, amount, status, created_at) 
                VALUES (?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$bookingId, $result->data->reference, $amount]);
            
            echo json_encode([
                'success' => true,
                'authorization_url' => $result->data->authorization_url,
                'access_code' => $result->data->access_code,
                'reference' => $result->data->reference
            ]);
            break;
            
        case 'verify_payment':
            $reference = $_GET['reference'];
            
            // Verify with Paystack
            $url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . PAYSTACK_SECRET_KEY
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            
            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }
            
            $result = json_decode($response);
            
            if (!$result->status || $result->data->status !== 'success') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Payment verification failed']);
                break;
            }
            
            $bookingId = $result->data->metadata->booking_id;
            $amount = $result->data->amount / 100; // Convert back to main currency unit
            
            // Record payment in database
            $db->beginTransaction();
            
            try {
                // Update booking status
                $db->prepare("UPDATE bookings SET status = 'paid' WHERE id = ?")
                   ->execute([$bookingId]);
                
                // Create payment record
                $stmt = $db->prepare("
                    INSERT INTO payments 
                    (booking_id, amount, payment_method, status, transaction_id, payment_date)
                    VALUES (?, ?, 'paystack', 'completed', ?, NOW())
                ");
                $stmt->execute([
                    $bookingId,
                    $amount,
                    $reference
                ]);
                
                // Update payment reference status
                $db->prepare("UPDATE payment_references SET status = 'completed' WHERE reference = ?")
                   ->execute([$reference]);
                
                // Create notification
                $paymentId = $db->lastInsertId();
                $db->prepare("
                    INSERT INTO notifications (user_id, property_id, message, type)
                    SELECT b.user_id, b.property_id, CONCAT('Payment received for booking #', b.id), 'payment_received'
                    FROM bookings b WHERE b.id = ?
                ")->execute([$bookingId]);
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'paymentId' => $paymentId,
                    'amount' => $amount,
                    'bookingId' => $bookingId
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'get_payment_methods':
            $stmt = $db->prepare("SELECT * FROM payment_methods WHERE user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'paymentMethods' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Payment API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>