<?php
namespace App\Helpers;

use PDOException;
use App\Database;

class EmailHelper 
{
    public static function checkRateLimit(string $ip): bool 
    {
        $config = include 'config/email.php';
        $pdo = Database::getInstance();
        
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempt_count 
                FROM contact_messages 
                WHERE ip_address = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ip, $config['rate_limit']['time_period']]);
            $result = $stmt->fetch();
            
            return $result['attempt_count'] < $config['rate_limit']['max_attempts'];
        } catch (PDOException $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            return true;
        }
    }
}