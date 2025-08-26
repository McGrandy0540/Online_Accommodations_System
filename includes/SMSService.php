<?php

class SMSService {
    private $apiKey;
    private $apiUrl;
    private $sender;
    
    public function __construct() {
        // Arkesel API configuration
        $this->apiKey = 'eHJMYnlrUUV5c29pd2FOaEhmdHo'; // Your Arkesel API key
        $this->apiUrl = 'https://sms.arkesel.com/api/v2/sms/send';
        $this->sender = 'Landlords'; // Your approved sender ID (shortened to fit requirements)
    }
    
    /**
     * Format phone number for Ghana
     */
    private function formatPhoneNumber($phoneNumber) {
        // Remove any spaces, dashes, or other characters
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        // Handle Ghana phone numbers
        if (preg_match('/^0/', $phoneNumber)) {
            // Convert 0XXXXXXXXX to 233XXXXXXXXX (without + for Arkesel)
            return '233' . substr($phoneNumber, 1);
        } elseif (preg_match('/^\+233/', $phoneNumber)) {
            // Remove the + prefix for Arkesel
            return substr($phoneNumber, 1);
        } elseif (preg_match('/^233/', $phoneNumber)) {
            return $phoneNumber;
        } else {
            // Assume it's a Ghana number without country code
            return '233' . $phoneNumber;
        }
    }
    
    /**
     * Send SMS notification using Arkesel API
     */
    public function sendSMS($phoneNumber, $message, $notificationId = null) {
        try {
            // Format phone number
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            // Validate phone number
            if (!$this->isValidPhoneNumber($formattedPhone)) {
                error_log("Invalid phone number format: $phoneNumber");
                return false;
            }
            
            // Prepare message payload for Arkesel
            $postData = [
                'sender' => $this->sender,
                'message' => $this->sanitizeMessage($message),
                'recipients' => [$formattedPhone]
            ];
            
            // Send SMS via cURL to Arkesel
            $response = $this->makeArkeselApiCall($postData);
            
            if ($response['success']) {
                $responseData = json_decode($response['data'], true);
                
                // Check if message was accepted by Arkesel
                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    // Log successful SMS
                    $this->logSMS($phoneNumber, $message, 'sent', $notificationId);
                    return true;
                }
            }
            
            // Log failed SMS
            $this->logSMS($phoneNumber, $message, 'failed', $notificationId, $response['error']);
            return false;
            
        } catch (Exception $e) {
            error_log("SMS Service Error: " . $e->getMessage());
            $this->logSMS($phoneNumber, $message, 'error', $notificationId, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send bulk SMS notifications using Arkesel
     */
    public function sendBulkSMS($recipients, $message) {
        $results = [];
        $phoneNumbers = [];
        
        // Extract phone numbers from recipients
        foreach ($recipients as $recipient) {
            $phoneNumber = $this->formatPhoneNumber($recipient['phone_number']);
            if ($this->isValidPhoneNumber($phoneNumber)) {
                $phoneNumbers[] = $phoneNumber;
            }
        }
        
        if (empty($phoneNumbers)) {
            return $results;
        }
        
        // Prepare message payload for Arkesel
        $postData = [
            'sender' => $this->sender,
            'message' => $this->sanitizeMessage($message),
            'recipients' => $phoneNumbers
        ];
        
        // Send bulk SMS via Arkesel
        $response = $this->makeArkeselApiCall($postData);
        
        // Process results
        foreach ($recipients as $recipient) {
            $phoneNumber = $recipient['phone_number'];
            $notificationId = $recipient['notification_id'] ?? null;
            
            $results[] = [
                'phone_number' => $phoneNumber,
                'success' => $response['success']
            ];
            
            // Log the SMS
            $status = $response['success'] ? 'sent' : 'failed';
            $this->logSMS($phoneNumber, $message, $status, $notificationId, 
                         $response['success'] ? null : $response['error']);
        }
        
        return $results;
    }
    
    /**
     * Make API call to Arkesel
     */
    private function makeArkeselApiCall($postData) {
        $ch = curl_init();
        
        // Prepare the correct data format for Arkesel v2 API (JSON format)
        $arkeselData = [
            'sender' => $postData['sender'],
            'message' => $postData['message'],
            'recipients' => $postData['recipients'] // Keep as array for v2 API
        ];
        
        // Add optional parameters if they exist
        if (isset($postData['scheduled_date'])) {
            $arkeselData['scheduled_date'] = $postData['scheduled_date'];
        }
        if (isset($postData['callback_url'])) {
            $arkeselData['callback_url'] = $postData['callback_url'];
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($arkeselData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'api-key: ' . $this->apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Disable SSL verification for local development
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error, 'data' => null];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'error' => null, 'data' => $response];
        }
        
        return ['success' => false, 'error' => "HTTP $httpCode: $response", 'data' => $response];
    }
    
    /**
     * Schedule SMS using Arkesel
     */
    public function scheduleSMS($phoneNumber, $message, $scheduledDate, $notificationId = null) {
        try {
            // Format phone number
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            // Validate phone number
            if (!$this->isValidPhoneNumber($formattedPhone)) {
                error_log("Invalid phone number format: $phoneNumber");
                return false;
            }
            
            // Prepare message payload for Arkesel with scheduled date
            $postData = [
                'sender' => $this->sender,
                'message' => $this->sanitizeMessage($message),
                'recipients' => [$formattedPhone],
                'scheduled_date' => $scheduledDate
            ];
            
            // Send SMS via cURL to Arkesel
            $response = $this->makeArkeselApiCall($postData);
            
            if ($response['success']) {
                $responseData = json_decode($response['data'], true);
                
                // Check if message was accepted by Arkesel
                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    // Log scheduled SMS
                    $this->logSMS($phoneNumber, $message, 'scheduled', $notificationId, 
                                 "Scheduled for: $scheduledDate");
                    return true;
                }
            }
            
            // Log failed SMS scheduling
            $this->logSMS($phoneNumber, $message, 'failed', $notificationId, $response['error']);
            return false;
            
        } catch (Exception $e) {
            error_log("SMS Scheduling Error: " . $e->getMessage());
            $this->logSMS($phoneNumber, $message, 'error', $notificationId, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send SMS with delivery webhook using Arkesel
     */
    public function sendSMSWithWebhook($phoneNumber, $message, $callbackUrl, $notificationId = null) {
        try {
            // Format phone number
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            // Validate phone number
            if (!$this->isValidPhoneNumber($formattedPhone)) {
                error_log("Invalid phone number format: $phoneNumber");
                return false;
            }
            
            // Prepare message payload for Arkesel with callback URL
            $postData = [
                'sender' => $this->sender,
                'message' => $this->sanitizeMessage($message),
                'recipients' => [$formattedPhone],
                'callback_url' => $callbackUrl
            ];
            
            // Send SMS via cURL to Arkesel
            $response = $this->makeArkeselApiCall($postData);
            
            if ($response['success']) {
                $responseData = json_decode($response['data'], true);
                
                // Check if message was accepted by Arkesel
                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    // Log SMS with webhook
                    $this->logSMS($phoneNumber, $message, 'sent_with_webhook', $notificationId, 
                                 "Webhook: $callbackUrl");
                    return true;
                }
            }
            
            // Log failed SMS with webhook
            $this->logSMS($phoneNumber, $message, 'failed', $notificationId, $response['error']);
            return false;
            
        } catch (Exception $e) {
            error_log("SMS with Webhook Error: " . $e->getMessage());
            $this->logSMS($phoneNumber, $message, 'error', $notificationId, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate phone number format for Ghana
     */
    private function isValidPhoneNumber($phoneNumber) {
        // Ghana phone numbers: 233XXXXXXXXX (9 digits after country code)
        return preg_match('/^233[0-9]{9}$/', $phoneNumber);
    }
    
    /**
     * Sanitize message content
     */
    private function sanitizeMessage($message) {
        // Remove HTML tags and decode entities
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8');
        
        // Limit message length (SMS limit is 160 characters for single SMS)
        if (strlen($message) > 160) {
            $message = substr($message, 0, 157) . '...';
        }
        
        return $message;
    }
    
    /**
     * Log SMS activity (same as before)
     */
    private function logSMS($phoneNumber, $message, $status, $notificationId = null, $error = null) {
        try {
            $pdo = Database::getInstance();
            
            // Create SMS log table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sms_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    phone_number VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    status ENUM('sent', 'failed', 'error', 'delivered', 'scheduled', 'sent_with_webhook') NOT NULL,
                    notification_id INT NULL,
                    error_message TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_phone (phone_number),
                    INDEX idx_status (status),
                    INDEX idx_notification (notification_id)
                )
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO sms_logs (phone_number, message, status, notification_id, error_message) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $phoneNumber,
                $message,
                $status,
                $notificationId,
                $error
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to log SMS: " . $e->getMessage());
        }
    }
    
    /**
     * Get SMS statistics (same as before)
     */
    public function getSMSStats($userId = null) {
        try {
            $pdo = Database::getInstance();
            
            $sql = "
                SELECT 
                    status,
                    COUNT(*) as count,
                    DATE(created_at) as date
                FROM sms_logs 
            ";
            
            $params = [];
            
            if ($userId) {
                $sql .= " WHERE notification_id IN (
                    SELECT id FROM notifications WHERE user_id = ?
                )";
                $params[] = $userId;
            }
            
            $sql .= " GROUP BY status, DATE(created_at) ORDER BY date DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get SMS stats: " . $e->getMessage());
            return [];
        }
    }
}
