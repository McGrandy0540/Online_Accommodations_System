<?php

class OTPService {
    private $apiKey;
    private $baseUrl;
    private $db;



    
    public function __construct() {
        $this->apiKey = 'ZWVKT1VnSnlNWXFWVkhKUlFQcUs'; // Your Arkesel OTP API key
        $this->baseUrl = 'https://sms.arkesel.com/api/otp';
        $this->db = Database::getInstance();
        
    }
    
    /**
     * Generate and send OTP to phone number
     */
    public function sendOTP($phoneNumber, $purpose = 'registration', $userId = null) {
        try {
            // Clean phone number (remove spaces, dashes, etc.)
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            // Validate phone number format
            if (!$this->isValidPhoneNumber($phoneNumber)) {
                return [
                    'success' => false,
                    'message' => 'Invalid phone number format'
                ];
            }
            
            // Check if there's a recent OTP request (rate limiting)
            if ($this->hasRecentOTPRequest($phoneNumber, $purpose)) {
                return [
                    'success' => false,
                    'message' => 'Please wait before requesting another OTP'
                ];
            }
            
            // Generate 6-digit OTP
            $otpCode = $this->generateOTP();
            
            // Store OTP in database
            $otpId = $this->storeOTP($phoneNumber, $otpCode, $purpose, $userId);
            
            if (!$otpId) {
                return [
                    'success' => false,
                    'message' => 'Failed to store OTP'
                ];
            }
            
            // Send OTP via SMS
            $smsResult = $this->sendSMS($phoneNumber, $otpCode, $purpose);
            
            if ($smsResult['success']) {
                return [
                    'success' => true,
                    'message' => 'OTP sent successfully',
                    'otp_id' => $otpId
                ];
            } else {
                // Delete stored OTP if SMS failed
                $this->deleteOTP($otpId);
                return [
                    'success' => false,
                    'message' => 'Failed to send OTP: ' . $smsResult['message']
                ];
            }
            
        } catch (Exception $e) {
            error_log("OTP Send Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error occurred'
            ];
        }
    }
    
    /**
     * Verify OTP code using Arkesel API
     */
    public function verifyOTP($phoneNumber, $otpCode, $purpose = 'registration') {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            // First verify with Arkesel API
            $apiResult = $this->verifyOTPWithAPI($phoneNumber, $otpCode);
            
            if (!$apiResult['success']) {
                return $apiResult;
            }
            
            // Get OTP record from database
            $query = "SELECT * FROM otp_verifications 
                     WHERE phone_number = :phone_number 
                     AND purpose = :purpose 
                     AND is_verified = FALSE 
                     AND expires_at > NOW() 
                     ORDER BY created_at DESC 
                     LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':phone_number', $phoneNumber);
            $stmt->bindParam(':purpose', $purpose);
            $stmt->execute();
            
            $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$otpRecord) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired OTP'
                ];
            }
            
            // Check if max attempts exceeded
            if ($otpRecord['attempts'] >= $otpRecord['max_attempts']) {
                return [
                    'success' => false,
                    'message' => 'Maximum verification attempts exceeded'
                ];
            }
            
            // Increment attempts
            $this->incrementOTPAttempts($otpRecord['id']);
            
            // Mark as verified since API verification passed
            $this->markOTPAsVerified($otpRecord['id']);
            
            // Update user phone verification status if applicable
            if ($otpRecord['user_id']) {
                $this->updateUserPhoneVerification($otpRecord['user_id']);
            }
            
            return [
                'success' => true,
                'message' => 'OTP verified successfully',
                'user_id' => $otpRecord['user_id']
            ];
            
        } catch (Exception $e) {
            error_log("OTP Verify Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error occurred'
            ];
        }
    }
    
    /**
     * Send OTP using Arkesel OTP API
     */
    private function sendSMS($phoneNumber, $otpCode, $purpose) {
        try {
            // Use Arkesel OTP generation API
            $fields = [
                'expiry' => 5,
                'length' => 6,
                'medium' => 'sms',
                'message' => 'Your verification code is,',
                'number' => $phoneNumber,
                'sender_id' => 'Landlords',
                'type' => 'numeric'
            ];

            echo "fields: " . print_r($fields, true);
            $postvars = '';
            foreach($fields as $key => $value) {
                $postvars .= $key . "=" . urlencode($value) . "&";
            }
            $postvars = rtrim($postvars, '&');
            echo "postvar:". print_r($postvars, true);
            
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://sms.arkesel.com/api/otp/generate',
                CURLOPT_HTTPHEADER => array('api-key: ' . $this->apiKey),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 10,
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
            
            // Log the response for debugging
            error_log("Arkesel OTP API Response: " . $response);
            error_log("HTTP Code: " . $httpCode);
            
            if ($curlError) {
                error_log("CURL Error: " . $curlError);
                return [
                    'success' => false,
                    'message' => 'Failed to connect to SMS API: ' . $curlError
                ];
            }
            
            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to get response from SMS API'
                ];
            }
            
            $responseData = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON Decode Error: " . json_last_error_msg());
                return [
                    'success' => false,
                    'message' => 'Invalid response format from SMS API'
                ];
            }
            
            if ($httpCode === 200 && $responseData) {
                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    return [
                        'success' => true,
                        'message' => 'OTP sent successfully',
                        'data' => $responseData
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => $responseData['message'] ?? 'OTP generation failed'
                    ];
                }
            } else {
                $errorMessage = 'SMS API request failed with HTTP code: ' . $httpCode;
                if ($responseData && isset($responseData['message'])) {
                    $errorMessage .= ' - ' . $responseData['message'];
                }
                return [
                    'success' => false,
                    'message' => $errorMessage
                ];
            }
            
        } catch (Exception $e) {
            error_log("OTP Send Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'OTP sending failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate 6-digit OTP
     */
    private function generateOTP() {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Clean phone number format
     */
    private function cleanPhoneNumber($phoneNumber) {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Add Ghana country code if not present
        if (strlen($cleaned) === 10 && substr($cleaned, 0, 1) === '0') {
            $cleaned = '233' . substr($cleaned, 1);
        } elseif (strlen($cleaned) === 9) {
            $cleaned = '233' . $cleaned;
        }
        
        return $cleaned;
    }
    
    /**
     * Validate phone number format
     */
    private function isValidPhoneNumber($phoneNumber) {
        // Ghana phone number validation
        return preg_match('/^233[0-9]{9}$/', $phoneNumber);
    }
    
    /**
     * Check for recent OTP requests (rate limiting)
     */
    private function hasRecentOTPRequest($phoneNumber, $purpose) {
        $query = "SELECT COUNT(*) as count FROM otp_verifications 
                 WHERE phone_number = :phone_number 
                 AND purpose = :purpose 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':phone_number', $phoneNumber);
        $stmt->bindParam(':purpose', $purpose);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Store OTP in database
     */
    private function storeOTP($phoneNumber, $otpCode, $purpose, $userId = null) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        $query = "INSERT INTO otp_verifications 
                 (phone_number, otp_code, purpose, user_id, expires_at) 
                 VALUES (:phone_number, :otp_code, :purpose, :user_id, :expires_at)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':phone_number', $phoneNumber);
        $stmt->bindParam(':otp_code', $otpCode);
        $stmt->bindParam(':purpose', $purpose);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':expires_at', $expiresAt);
        
        if ($stmt->execute()) {
            return $this->db->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * Delete OTP record
     */
    private function deleteOTP($otpId) {
        $query = "DELETE FROM otp_verifications WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $otpId);
        return $stmt->execute();
    }
    
    /**
     * Increment OTP attempts
     */
    private function incrementOTPAttempts($otpId) {
        $query = "UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $otpId);
        return $stmt->execute();
    }
    
    /**
     * Mark OTP as verified
     */
    private function markOTPAsVerified($otpId) {
        $query = "UPDATE otp_verifications 
                 SET is_verified = TRUE, verified_at = NOW() 
                 WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $otpId);
        return $stmt->execute();
    }
    
    /**
     * Update user phone verification status
     */
    private function updateUserPhoneVerification($userId) {
        $query = "UPDATE users SET phone_verified = TRUE WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $userId);
        return $stmt->execute();
    }
    
    /**
     * Get OTP message based on purpose
     */
    private function getOTPMessage($otpCode, $purpose) {
        switch ($purpose) {
            case 'registration':
                return "Your Landlords&Tenant registration OTP is: {$otpCode}. Valid for 10 minutes. Do not share this code.";
            case 'login':
                return "Your Landlords&Tenant login OTP is: {$otpCode}. Valid for 10 minutes. Do not share this code.";
            case 'password_reset':
                return "Your Landlords&Tenant password reset OTP is: {$otpCode}. Valid for 10 minutes. Do not share this code.";
            default:
                return "Your Landlords&Tenant verification code is: {$otpCode}. Valid for 10 minutes.";
        }
    }
    
    /**
     * Verify OTP with Arkesel API
     */
    private function verifyOTPWithAPI($phoneNumber, $otpCode) {
        try {
            $fields = [
                'api_key' => $this->apiKey,
                'code' => $otpCode,
                'number' => $phoneNumber,
            ];
            
            $postvars = '';
            foreach($fields as $key => $value) {
                $postvars .= $key . "=" . $value . "&";
            }
            
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://sms.arkesel.com/api/otp/verify',
                CURLOPT_HTTPHEADER => array('api-key: ' . $this->apiKey),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 7,
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
            
            // Log the response for debugging
            error_log("Arkesel OTP Verify API Response: " . $response);
            error_log("HTTP Code: " . $httpCode);
            if ($curlError) {
                error_log("CURL Error: " . $curlError);
            }
            
            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to OTP verification API: ' . $curlError
                ];
            }
            
            $responseData = json_decode($response, true);
            
            if ($httpCode === 200 && $responseData) {
                if (isset($responseData['status']) && $responseData['status'] === 'success') {
                    return [
                        'success' => true,
                        'message' => 'OTP verified successfully with API',
                        'data' => $responseData
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => $responseData['message'] ?? 'OTP verification failed'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'OTP verification API request failed with HTTP code: ' . $httpCode
                ];
            }
            
        } catch (Exception $e) {
            error_log("OTP API Verify Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'OTP verification failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Clean up expired OTPs (should be called periodically)
     */
    public function cleanupExpiredOTPs() {
        $query = "DELETE FROM otp_verifications WHERE expires_at < NOW()";
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
}
