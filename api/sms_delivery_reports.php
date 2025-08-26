<?php
/**
 * SMS Delivery Reports Webhook
 * This endpoint receives delivery reports from Infobip SMS API
 */

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify the request is from Infobip (optional security measure)
$allowedIPs = [
    '185.12.44.0/24',
    '185.12.45.0/24',
    '185.12.46.0/24',
    '185.12.47.0/24'
];

// Get the raw POST data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Log the incoming webhook for debugging
error_log("SMS Delivery Report Webhook: " . $rawData);

try {
    require_once '../config/database.php';
    
    if (!$data || !isset($data['results'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data format']);
        exit;
    }
    
    $pdo = Database::getInstance();
    
    // Process each delivery report
    foreach ($data['results'] as $result) {
        $messageId = $result['messageId'] ?? null;
        $to = $result['to'] ?? null;
        $status = $result['status'] ?? [];
        $statusName = $status['name'] ?? 'unknown';
        $statusDescription = $status['description'] ?? '';
        $doneAt = $result['doneAt'] ?? null;
        $smsCount = $result['smsCount'] ?? 1;
        $price = $result['price'] ?? [];
        $priceAmount = $price['pricePerMessage'] ?? 0;
        $currency = $price['currency'] ?? 'EUR';
        
        // Convert status to our internal format
        $internalStatus = 'unknown';
        switch (strtolower($statusName)) {
            case 'delivered':
            case 'delivered_to_handset':
                $internalStatus = 'delivered';
                break;
            case 'pending':
            case 'pending_waiting_delivery':
                $internalStatus = 'pending';
                break;
            case 'undeliverable':
            case 'expired':
            case 'rejected':
                $internalStatus = 'failed';
                break;
        }
        
        // Update SMS log if exists
        $updateStmt = $pdo->prepare("
            UPDATE sms_logs 
            SET 
                status = ?,
                error_message = ?,
                updated_at = NOW()
            WHERE phone_number = ? 
            AND status IN ('sent', 'pending')
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        $updateStmt->execute([
            $internalStatus,
            $statusDescription,
            $to
        ]);
        
        // If no existing log was updated, create a new delivery report record
        if ($updateStmt->rowCount() === 0) {
            $insertStmt = $pdo->prepare("
                INSERT INTO sms_delivery_reports 
                (message_id, phone_number, status, status_description, delivered_at, sms_count, price_amount, currency, raw_data) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Create delivery reports table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sms_delivery_reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id VARCHAR(100) NULL,
                    phone_number VARCHAR(20) NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    status_description TEXT NULL,
                    delivered_at DATETIME NULL,
                    sms_count INT DEFAULT 1,
                    price_amount DECIMAL(10,4) DEFAULT 0,
                    currency VARCHAR(3) DEFAULT 'EUR',
                    raw_data JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_phone (phone_number),
                    INDEX idx_status (status),
                    INDEX idx_message_id (message_id)
                )
            ");
            
            $deliveredAt = $doneAt ? date('Y-m-d H:i:s', strtotime($doneAt)) : null;
            
            $insertStmt->execute([
                $messageId,
                $to,
                $internalStatus,
                $statusDescription,
                $deliveredAt,
                $smsCount,
                $priceAmount,
                $currency,
                json_encode($result)
            ]);
        }
    }
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'processed' => count($data['results']),
        'message' => 'Delivery reports processed successfully'
    ]);
    
} catch (Exception $e) {
    error_log("SMS Delivery Report Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}
?>
