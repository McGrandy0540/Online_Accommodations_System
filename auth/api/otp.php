<?php
header('Content-Type: application/json');
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../config/database.php';
require_once '../../includes/OTPService.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$action = $input['action'] ?? '';
$phoneNumber = $input['phone_number'] ?? '';
$purpose = $input['purpose'] ?? 'registration';

try {
    $otpService = new OTPService();
    
    switch ($action) {
        case 'send':
            // Validate required fields
            if (empty($phoneNumber)) {
                echo json_encode(['success' => false, 'message' => 'Phone number is required']);
                exit;
            }
            
            $userId = $input['user_id'] ?? null;
            $result = $otpService->sendOTP($phoneNumber, $purpose, $userId);
            
            if ($result['success']) {
                // Store OTP session data for verification
                $_SESSION['otp_phone'] = $phoneNumber;
                $_SESSION['otp_purpose'] = $purpose;
                $_SESSION['otp_sent_at'] = time();
            }
            
            echo json_encode($result);
            break;
            
        case 'verify':
            $otpCode = $input['otp_code'] ?? '';
            
            // Validate required fields
            if (empty($phoneNumber) || empty($otpCode)) {
                echo json_encode(['success' => false, 'message' => 'Phone number and OTP code are required']);
                exit;
            }
            
            // Check session data
            if (!isset($_SESSION['otp_phone']) || $_SESSION['otp_phone'] !== $phoneNumber) {
                echo json_encode(['success' => false, 'message' => 'Invalid session data']);
                exit;
            }
            
            // Check if OTP was sent recently (within 15 minutes)
            if (!isset($_SESSION['otp_sent_at']) || (time() - $_SESSION['otp_sent_at']) > 900) {
                echo json_encode(['success' => false, 'message' => 'OTP session expired']);
                exit;
            }
            
            $result = $otpService->verifyOTP($phoneNumber, $otpCode, $purpose);
            
            if ($result['success']) {
                // Mark OTP as verified in session
                $_SESSION['otp_verified'] = true;
                $_SESSION['otp_verified_at'] = time();
                $_SESSION['verified_phone'] = $phoneNumber;
                
                // Clean up OTP session data
                unset($_SESSION['otp_phone']);
                unset($_SESSION['otp_purpose']);
                unset($_SESSION['otp_sent_at']);
            }
            
            echo json_encode($result);
            break;
            
        case 'resend':
            // Validate required fields
            if (empty($phoneNumber)) {
                echo json_encode(['success' => false, 'message' => 'Phone number is required']);
                exit;
            }
            
            // Check session data
            if (!isset($_SESSION['otp_phone']) || $_SESSION['otp_phone'] !== $phoneNumber) {
                echo json_encode(['success' => false, 'message' => 'Invalid session data']);
                exit;
            }
            
            // Check rate limiting (minimum 2 minutes between resends)
            if (isset($_SESSION['otp_sent_at']) && (time() - $_SESSION['otp_sent_at']) < 120) {
                $waitTime = 120 - (time() - $_SESSION['otp_sent_at']);
                echo json_encode([
                    'success' => false, 
                    'message' => "Please wait {$waitTime} seconds before requesting another OTP"
                ]);
                exit;
            }
            
            $userId = $input['user_id'] ?? null;
            $result = $otpService->sendOTP($phoneNumber, $purpose, $userId);
            
            if ($result['success']) {
                $_SESSION['otp_sent_at'] = time();
            }
            
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("OTP API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
