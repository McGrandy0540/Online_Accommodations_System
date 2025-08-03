<?php
/**
 * Email Helper Class
 * Provides utility functions for email operations
 */

class EmailHelper 
{
    /**
     * Get admin email from database with fallback
     */
    public static function getAdminEmail() {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT email FROM users WHERE status = 'admin' AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $admin = $stmt->fetch();
            
            if ($admin && !empty($admin['email']) && filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                return $admin['email'];
            }
        } catch (Exception $e) {
            error_log("Failed to get admin email from database: " . $e->getMessage());
        }
        
        // Fallback to default admin email
        return DEFAULT_ADMIN_EMAIL;
    }
    
    /**
     * Check email rate limiting
     */
    public static function checkRateLimit($ip_address) {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as email_count 
                FROM contact_messages 
                WHERE ip_address = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$ip_address]);
            $result = $stmt->fetch();
            
            return ($result['email_count'] < EMAIL_RATE_LIMIT);
        } catch (Exception $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            return true; // Allow if check fails
        }
    }
    
    /**
     * Log email attempt
     */
    public static function logEmailAttempt($to, $subject, $status, $error = null) {
        try {
            $log_entry = date('Y-m-d H:i:s') . " - Email to: {$to}, Subject: {$subject}, Status: {$status}";
            if ($error) {
                $log_entry .= ", Error: {$error}";
            }
            error_log($log_entry);
        } catch (Exception $e) {
            // Silent fail for logging
        }
    }
    
    /**
     * Sanitize email content
     */
    public static function sanitizeEmailContent($content) {
        // Remove potentially dangerous content
        $content = strip_tags($content, '<p><br><strong><em><ul><li><ol>');
        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        return $content;
    }
    
    /**
     * Validate email address
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
?>
