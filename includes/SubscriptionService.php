<?php

require_once '../config/constants.php';

class SubscriptionService {
    private $db;
    private $paystackSecretKey;
    private $paystackPublicKey;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->paystackSecretKey = PAYSTACK_SECRET_KEY;
        $this->paystackPublicKey = PAYSTACK_PUBLIC_KEY;
    }
    
    /**
     * Check if user needs subscription (students only)
     */
    public function userNeedsSubscription($userId) {
        try {
            $query = "SELECT status FROM users WHERE id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user && $user['status'] === 'student';
            
        } catch (Exception $e) {
            error_log("Check subscription need error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user's current subscription status
     */
    public function getUserSubscriptionStatus($userId) {
        try {
            $query = "SELECT us.*, sp.plan_name, sp.duration_months, sp.price
                     FROM user_subscriptions us
                     JOIN subscription_plans sp ON us.plan_id = sp.id
                     WHERE us.user_id = :user_id 
                     AND us.status IN ('active', 'expired')
                     ORDER BY us.created_at DESC
                     LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($subscription) {
                // Check if subscription is expired
                $currentDate = date('Y-m-d');
                if ($subscription['end_date'] < $currentDate && $subscription['status'] === 'active') {
                    // Update status to expired
                    $this->updateSubscriptionStatus($subscription['id'], 'expired');
                    $subscription['status'] = 'expired';
                }
                
                return [
                    'has_subscription' => true,
                    'status' => $subscription['status'],
                    'plan_name' => $subscription['plan_name'],
                    'start_date' => $subscription['start_date'],
                    'end_date' => $subscription['end_date'],
                    'days_remaining' => $this->calculateDaysRemaining($subscription['end_date']),
                    'amount_paid' => $subscription['amount_paid']
                ];
            }
            
            return [
                'has_subscription' => false,
                'status' => 'none'
            ];
            
        } catch (Exception $e) {
            error_log("Get subscription status error: " . $e->getMessage());
            return [
                'has_subscription' => false,
                'status' => 'error'
            ];
        }
    }
    
    /**
     * Get default subscription plan
     */
    public function getDefaultSubscriptionPlan() {
        try {
            $query = "SELECT * FROM subscription_plans WHERE is_active = TRUE ORDER BY id ASC LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get default plan error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create subscription payment
     */
    public function createSubscriptionPayment($userId, $planId, $paymentReference) {
        try {
            $this->db->beginTransaction();
            
            // Get plan details
            $plan = $this->getSubscriptionPlan($planId);
            if (!$plan) {
                throw new Exception("Invalid subscription plan");
            }
            
            // Calculate dates
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime("+{$plan['duration_months']} months"));
            
            // Create subscription record
            $query = "INSERT INTO user_subscriptions 
                     (user_id, plan_id, payment_reference, amount_paid, start_date, end_date, status) 
                     VALUES (:user_id, :plan_id, :payment_reference, :amount_paid, :start_date, :end_date, 'pending')";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':plan_id', $planId);
            $stmt->bindParam(':payment_reference', $paymentReference);
            $stmt->bindParam(':amount_paid', $plan['price']);
            $stmt->bindParam(':start_date', $startDate);
            $stmt->bindParam(':end_date', $endDate);
            $stmt->execute();
            
            $subscriptionId = $this->db->lastInsertId();
            
            // Create payment log
            $this->createPaymentLog($userId, $subscriptionId, $paymentReference, $plan['price'], 'pending');
            
            $this->db->commit();
            
            return [
                'success' => true,
                'subscription_id' => $subscriptionId,
                'amount' => $plan['price'],
                'start_date' => $startDate,
                'end_date' => $endDate
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create subscription payment error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create subscription payment'
            ];
        }
    }
    
    /**
     * Verify Paystack payment
     */
    public function verifyPaystackPayment($paymentReference) {
        try {
            $curl = curl_init();
            
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . $paymentReference,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $this->paystackSecretKey,
                    "Cache-Control: no-cache",
                ),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                throw new Exception("cURL Error: " . $err);
            }
            
            if ($httpCode !== 200) {
                throw new Exception("HTTP Error: " . $httpCode);
            }
            
            $responseData = json_decode($response, true);
            
            if (!$responseData || !$responseData['status']) {
                throw new Exception("Invalid response from Paystack");
            }
            
            return [
                'success' => true,
                'data' => $responseData['data']
            ];
            
        } catch (Exception $e) {
            error_log("Paystack verification error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process successful payment
     */
    public function processSuccessfulPayment($paymentReference, $paystackData) {
        try {
            $this->db->beginTransaction();
            
            // Get subscription by payment reference
            $query = "SELECT * FROM user_subscriptions WHERE payment_reference = :reference";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':reference', $paymentReference);
            $stmt->execute();
            
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$subscription) {
                throw new Exception("Subscription not found");
            }
            
            // Update subscription status to active
            $this->updateSubscriptionStatus($subscription['id'], 'active');
            
            // Update user subscription status
            $this->updateUserSubscriptionStatus($subscription['user_id'], 'active', $subscription['end_date']);
            
            // Update payment log
            $this->updatePaymentLog($paymentReference, 'success', $paystackData);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'subscription' => $subscription
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Process payment error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get subscription plan by ID
     */
    private function getSubscriptionPlan($planId) {
        try {
            $query = "SELECT * FROM subscription_plans WHERE id = :id AND is_active = TRUE";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $planId);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get subscription plan error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update subscription status
     */
    private function updateSubscriptionStatus($subscriptionId, $status) {
        $query = "UPDATE user_subscriptions SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $subscriptionId);
        return $stmt->execute();
    }
    
    /**
     * Update user subscription status
     */
    private function updateUserSubscriptionStatus($userId, $status, $expiresAt = null) {
        $query = "UPDATE users SET subscription_status = :status";
        if ($expiresAt) {
            $query .= ", subscription_expires_at = :expires_at";
        }
        $query .= " WHERE id = :user_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $userId);
        if ($expiresAt) {
            $stmt->bindParam(':expires_at', $expiresAt);
        }
        return $stmt->execute();
    }
    
    /**
     * Create payment log
     */
    private function createPaymentLog($userId, $subscriptionId, $paymentReference, $amount, $status) {
        $query = "INSERT INTO subscription_payment_logs 
                 (user_id, subscription_id, payment_reference, amount, payment_status) 
                 VALUES (:user_id, :subscription_id, :payment_reference, :amount, :payment_status)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':subscription_id', $subscriptionId);
        $stmt->bindParam(':payment_reference', $paymentReference);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':payment_status', $status);
        return $stmt->execute();
    }
    
    /**
     * Update payment log
     */
    private function updatePaymentLog($paymentReference, $status, $paystackResponse = null) {
        $query = "UPDATE subscription_payment_logs 
                 SET payment_status = :status, updated_at = NOW()";
        if ($paystackResponse) {
            $query .= ", paystack_response = :paystack_response";
        }
        $query .= " WHERE payment_reference = :payment_reference";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':payment_reference', $paymentReference);
        if ($paystackResponse) {
            $stmt->bindParam(':paystack_response', json_encode($paystackResponse));
        }
        return $stmt->execute();
    }
    
    /**
     * Calculate days remaining in subscription
     */
    private function calculateDaysRemaining($endDate) {
        $currentDate = new DateTime();
        $expiryDate = new DateTime($endDate);
        
        if ($expiryDate < $currentDate) {
            return 0;
        }
        
        $interval = $currentDate->diff($expiryDate);
        return $interval->days;
    }
    
    /**
     * Check and update expired subscriptions
     */
    public function updateExpiredSubscriptions() {
        try {
            // Update expired subscriptions
            $query = "UPDATE user_subscriptions 
                     SET status = 'expired', updated_at = NOW() 
                     WHERE status = 'active' AND end_date < CURDATE()";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            // Update user subscription status for expired subscriptions
            $query = "UPDATE users u
                     JOIN user_subscriptions us ON u.id = us.user_id
                     SET u.subscription_status = 'expired'
                     WHERE us.status = 'expired' AND us.end_date < CURDATE()
                     AND u.subscription_status = 'active'";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Update expired subscriptions error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get Paystack public key
     */
    public function getPaystackPublicKey() {
        return $this->paystackPublicKey;
    }
}
