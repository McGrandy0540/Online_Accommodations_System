<?php
header('Content-Type: application/json');
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../config/database.php';
require_once '../../includes/SubscriptionService.php';

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

try {
    $subscriptionService = new SubscriptionService();
    
    switch ($action) {
        case 'create_payment':
            // Validate required fields
            $userId = $input['user_id'] ?? null;
            $planId = $input['plan_id'] ?? null;
            $paymentReference = $input['payment_reference'] ?? null;
            
            if (!$userId || !$planId || !$paymentReference) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            // Check if user needs subscription
            if (!$subscriptionService->userNeedsSubscription($userId)) {
                echo json_encode(['success' => false, 'message' => 'User does not need subscription']);
                exit;
            }
            
            $result = $subscriptionService->createSubscriptionPayment($userId, $planId, $paymentReference);
            echo json_encode($result);
            break;
            
        case 'verify_payment':
            $paymentReference = $input['payment_reference'] ?? '';
            
            if (empty($paymentReference)) {
                echo json_encode(['success' => false, 'message' => 'Payment reference is required']);
                exit;
            }
            
            // Verify payment with Paystack
            $verificationResult = $subscriptionService->verifyPaystackPayment($paymentReference);
            
            if (!$verificationResult['success']) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Payment verification failed: ' . $verificationResult['message']
                ]);
                exit;
            }
            
            $paystackData = $verificationResult['data'];
            
            // Check if payment was successful
            if ($paystackData['status'] !== 'success') {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Payment was not successful'
                ]);
                exit;
            }
            
            // Process successful payment
            $processResult = $subscriptionService->processSuccessfulPayment($paymentReference, $paystackData);
            
            if ($processResult['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment verified and processed successfully',
                    'subscription' => $processResult['subscription'],
                    'reference' => $paymentReference
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to process payment: ' . $processResult['message']
                ]);
            }
            break;
            
        case 'get_status':
            $userId = $input['user_id'] ?? null;
            
            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'User ID is required']);
                exit;
            }
            
            $status = $subscriptionService->getUserSubscriptionStatus($userId);
            echo json_encode([
                'success' => true,
                'subscription_status' => $status
            ]);
            break;
            
        case 'get_plan':
            $plan = $subscriptionService->getDefaultSubscriptionPlan();
            
            if ($plan) {
                echo json_encode([
                    'success' => true,
                    'plan' => $plan
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'No subscription plan available'
                ]);
            }
            break;
            
        case 'get_paystack_key':
            echo json_encode([
                'success' => true,
                'public_key' => $subscriptionService->getPaystackPublicKey()
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Subscription API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
