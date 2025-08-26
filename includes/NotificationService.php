<?php

require_once 'SMSService.php';

class NotificationService {
    private $pdo;
    private $smsService;
    
    public function __construct() {
        $this->pdo = Database::getInstance();
        $this->smsService = new SMSService();
    }
    
    /**
     * Create a new notification and send SMS immediately for fastest delivery
     */
    public function createNotification($userId, $message, $type = 'general', $propertyId = null, $sendSMS = true) {
        try {
            // Insert notification into database
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, message, type, property_id, is_read, delivered, created_at) 
                VALUES (?, ?, ?, ?, 0, 0, NOW())
            ");
            
            $stmt->execute([$userId, $message, $type, $propertyId]);
            $notificationId = $this->pdo->lastInsertId();
            
            // Send SMS immediately for fastest delivery (no delays)
            if ($sendSMS && $notificationId) {
                $this->sendNotificationSMS($userId, $message, $type, $notificationId);
            }
            
            return $notificationId;
            
        } catch (Exception $e) {
            error_log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send SMS for notifications when student views them
     * This method is called from the notification portal
     */
    public function sendNotificationSMS($userId, $message, $type, $notificationId) {
        try {
            // Get user's phone number and SMS preferences
            $stmt = $this->pdo->prepare("
                SELECT phone_number, username, sms_notifications, 
                       sms_booking_updates, sms_payment_alerts, 
                       sms_maintenance_updates, sms_announcements 
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['phone_number'])) {
                // Check if SMS notifications are enabled globally
                if (!($user['sms_notifications'] ?? 1)) {
                    return false;
                }
                
                // Check specific notification type preferences
                $typeEnabled = true;
                switch ($type) {
                    case 'booking_update':
                        $typeEnabled = $user['sms_booking_updates'] ?? 1;
                        break;
                    case 'payment_received':
                        $typeEnabled = $user['sms_payment_alerts'] ?? 1;
                        break;
                    case 'maintenance':
                        $typeEnabled = $user['sms_maintenance_updates'] ?? 1;
                        break;
                    case 'announcement':
                        $typeEnabled = $user['sms_announcements'] ?? 1;
                        break;
                    case 'system_alert':
                        // System alerts are always sent regardless of preferences
                        $typeEnabled = true;
                        break;
                    default:
                        // For other types, respect global SMS setting
                        $typeEnabled = $user['sms_notifications'] ?? 1;
                }
                
                if (!$typeEnabled) {
                    return false;
                }
                
                // Create SMS-friendly message
                $smsMessage = $this->createSMSMessage($message, $type);
                
                // Send SMS
                $success = $this->smsService->sendSMS($user['phone_number'], $smsMessage, $notificationId);
                
                if ($success) {
                    // Mark notification as delivered
                    $updateStmt = $this->pdo->prepare("UPDATE notifications SET delivered = 1 WHERE id = ?");
                    $updateStmt->execute([$notificationId]);
                }
                
                return $success;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Failed to send notification SMS: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create SMS-friendly message
     */
    private function createSMSMessage($message, $type) {
        $typeLabel = ucfirst(str_replace('_', ' ', $type));
        
        // Create concise SMS message
        $smsMessage = "[$typeLabel] $message - Landlords&Tenants";
        
        // Sanitize and limit length
        $smsMessage = html_entity_decode(strip_tags($smsMessage), ENT_QUOTES, 'UTF-8');
        
        if (strlen($smsMessage) > 160) {
            $smsMessage = substr($smsMessage, 0, 157) . '...';
        }
        
        return $smsMessage;
    }
    
    /**
     * Send booking update notification
     */
    public function sendBookingNotification($userId, $bookingId, $status, $propertyName, $roomNumber = null) {
        $statusMessages = [
            'confirmed' => "Your booking for $propertyName" . ($roomNumber ? " - Room $roomNumber" : "") . " has been confirmed!",
            'rejected' => "Your booking for $propertyName" . ($roomNumber ? " - Room $roomNumber" : "") . " has been rejected.",
            'cancelled' => "Your booking for $propertyName" . ($roomNumber ? " - Room $roomNumber" : "") . " has been cancelled.",
            'paid' => "Payment confirmed for your booking at $propertyName" . ($roomNumber ? " - Room $roomNumber" : "") . "."
        ];
        
        $message = $statusMessages[$status] ?? "Your booking status has been updated to: $status";
        
        return $this->createNotification($userId, $message, 'booking_update', null, true);
    }
    
    /**
     * Send payment notification
     */
    public function sendPaymentNotification($userId, $amount, $status, $propertyName) {
        $statusMessages = [
            'completed' => "Payment of GHS " . number_format($amount, 2) . " for $propertyName has been completed successfully.",
            'failed' => "Payment of GHS " . number_format($amount, 2) . " for $propertyName has failed. Please try again.",
            'pending' => "Payment of GHS " . number_format($amount, 2) . " for $propertyName is being processed."
        ];
        
        $message = $statusMessages[$status] ?? "Payment status updated: $status";
        
        return $this->createNotification($userId, $message, 'payment_received', null, true);
    }
    
    /**
     * Send maintenance notification
     */
    public function sendMaintenanceNotification($userId, $title, $status, $propertyName) {
        $statusMessages = [
            'pending' => "Your maintenance request '$title' at $propertyName has been submitted and is pending review.",
            'in_progress' => "Your maintenance request '$title' at $propertyName is now in progress.",
            'completed' => "Your maintenance request '$title' at $propertyName has been completed.",
            'cancelled' => "Your maintenance request '$title' at $propertyName has been cancelled."
        ];
        
        $message = $statusMessages[$status] ?? "Maintenance request '$title' status updated: $status";
        
        return $this->createNotification($userId, $message, 'maintenance', null, true);
    }
    
    /**
     * Send announcement notification
     */
    public function sendAnnouncementNotification($userId, $title, $content) {
        $message = "New Announcement: $title - " . substr(strip_tags($content), 0, 100) . "...";
        
        return $this->createNotification($userId, $message, 'announcement', null, true);
    }
    
    /**
     * Send bulk notifications to multiple users
     */
    public function sendBulkNotifications($userIds, $message, $type = 'general', $propertyId = null, $sendSMS = true) {
        $results = [];
        
        foreach ($userIds as $userId) {
            $notificationId = $this->createNotification($userId, $message, $type, $propertyId, $sendSMS);
            $results[] = [
                'user_id' => $userId,
                'notification_id' => $notificationId,
                'success' => $notificationId !== false
            ];
        }
        
        return $results;
    }
    
    /**
     * Process pending SMS notifications when student views notifications
     * Optimized for immediate delivery without delays
     */
    public function processPendingSMSForUser($userId) {
        try {
            // Get undelivered notifications for this specific user
            $stmt = $this->pdo->prepare("
                SELECT id, message, type 
                FROM notifications 
                WHERE user_id = ? 
                AND delivered = 0
                AND type IN ('booking_update', 'payment_received', 'announcement', 'maintenance', 'system_alert')
                ORDER BY created_at ASC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $undeliveredNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($undeliveredNotifications as $notification) {
                // Send SMS for this notification immediately
                $success = $this->sendNotificationSMS(
                    $userId, 
                    $notification['message'], 
                    $notification['type'], 
                    $notification['id']
                );
                
                if ($success) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
                
                // No delay - send immediately for fastest delivery
            }
            
            return [
                'processed' => count($undeliveredNotifications),
                'success' => $successCount,
                'failed' => $failureCount
            ];
            
        } catch (Exception $e) {
            error_log("Failed to process pending SMS for user: " . $e->getMessage());
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }
    }
    
    /**
     * Get notification statistics
     */
    public function getNotificationStats($userId = null) {
        try {
            $sql = "
                SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
                    SUM(CASE WHEN delivered = 1 THEN 1 ELSE 0 END) as delivered_count
                FROM notifications 
            ";
            
            $params = [];
            
            if ($userId) {
                $sql .= " WHERE user_id = ?";
                $params[] = $userId;
            }
            
            $sql .= " GROUP BY type ORDER BY count DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get notification stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId = null) {
        try {
            $sql = "UPDATE notifications SET is_read = 1 WHERE id = ?";
            $params = [$notificationId];
            
            if ($userId) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("Failed to mark notification as read: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        try {
            $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            return $stmt->execute([$userId]);
            
        } catch (Exception $e) {
            error_log("Failed to mark all notifications as read: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user notifications
     */
    public function getUserNotifications($userId, $limit = 50, $offset = 0) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT n.*, p.property_name 
                FROM notifications n
                LEFT JOIN property p ON n.property_id = p.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$userId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get user notifications: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount($userId) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("Failed to get unread count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Delete old notifications (for cleanup)
     */
    public function deleteOldNotifications($daysOld = 90) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM notifications 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            return $stmt->execute([$daysOld]);
            
        } catch (Exception $e) {
            error_log("Failed to delete old notifications: " . $e->getMessage());
            return false;
        }
    }
}
