<?php
/**
 * Student Email Notification System
 * 
 * Real-time email notifications for all student activities on the platform.
 * Completely separate from the existing EmailService.
 * Sends beautiful, professional emails instantly.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once dirname(__DIR__) . '/vendor/autoload.php';

class StudentEmailNotifier {
    private $mail;
    private $from_email;
    private $from_name;
    private $base_url;
    
    public function __construct() {
        // Load email configuration
        if (file_exists(dirname(__DIR__) . '/config/email.php')) {
            require_once dirname(__DIR__) . '/config/email.php';
        }
        
        $this->from_email = defined('DEFAULT_FROM_EMAIL') ? DEFAULT_FROM_EMAIL : 'noreply@landlordstenant.com';
        $this->from_name = defined('DEFAULT_FROM_NAME') ? DEFAULT_FROM_NAME : 'Landlords & Tenant';
        $this->base_url = 'http://localhost/projects/Online_Accommodation_System';
        
        $this->initializeMailer();
    }
    
    /**
     * Initialize PHPMailer
     */
    private function initializeMailer() {
        $this->mail = new PHPMailer(true);
        
        try {
            $this->mail->isSMTP();
            $this->mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $this->mail->SMTPAuth = true;
            $this->mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
            $this->mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
            
            if (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $this->mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 465;
            } else {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $this->mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
            }
            
            $this->mail->Timeout = 30;
            $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            
        } catch (Exception $e) {
            error_log("StudentEmailNotifier initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Send email notification based on type
     */
    public function sendNotificationEmail($userEmail, $userName, $notificationType, $details = []) {
        if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        try {
            $this->mail->clearAddresses();
            $this->mail->clearReplyTos();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->from_email, $this->from_name);
            $this->mail->addAddress($userEmail, $userName);
            $this->mail->isHTML(true);
            
            // Generate email content based on notification type
            switch ($notificationType) {
                case 'booking_update':
                    $content = $this->createBookingUpdateEmail($userName, $details);
                    break;
                case 'payment_received':
                    $content = $this->createPaymentEmail($userName, $details);
                    break;
                case 'maintenance':
                    $content = $this->createMaintenanceEmail($userName, $details);
                    break;
                case 'announcement':
                    $content = $this->createAnnouncementEmail($userName, $details);
                    break;
                case 'system_alert':
                    $content = $this->createSystemAlertEmail($userName, $details);
                    break;
                default:
                    $content = $this->createGeneralEmail($userName, $details);
            }
            
            $this->mail->Subject = $content['subject'];
            $this->mail->Body = $content['html'];
            $this->mail->AltBody = $content['text'];
            
            $result = $this->mail->send();
            
            if ($result) {
                error_log("✓ Email sent to {$userEmail} - Type: {$notificationType}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("StudentEmailNotifier error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Booking Update Email
     */
    private function createBookingUpdateEmail($name, $details) {
        $status = $details['status'] ?? 'updated';
        $propertyName = $details['property_name'] ?? 'Your Property';
        $roomNumber = $details['room_number'] ?? null;
        $bookingId = $details['booking_id'] ?? 'N/A';
        
        $statusInfo = $this->getBookingStatusInfo($status);
        
        $subject = "Booking {$statusInfo['action']} - {$propertyName}";
        
        $html = $this->createEmailTemplate($name, $statusInfo['icon'], $statusInfo['color'], 
            "Booking {$statusInfo['action']}", "
            <p>Your booking has been <strong>{$statusInfo['action']}</strong>.</p>
            
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <div style='margin-bottom: 10px;'>
                    <strong>Booking ID:</strong> #{$bookingId}
                </div>
                <div style='margin-bottom: 10px;'>
                    <strong>Property:</strong> {$propertyName}
                </div>
                " . ($roomNumber ? "<div style='margin-bottom: 10px;'><strong>Room:</strong> {$roomNumber}</div>" : "") . "
                <div>
                    <strong>Status:</strong> <span style='color: {$statusInfo['color']};'>{$statusInfo['status']}</span>
                </div>
            </div>
            
            {$statusInfo['message']}
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/bookings/details.php?id={$bookingId}' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    View Booking Details
                </a>
            </div>
        ");
        
        $text = "Booking {$statusInfo['action']} - {$propertyName}\n\n";
        $text .= "Dear {$name},\n\nYour booking has been {$statusInfo['action']}.\n\n";
        $text .= "Booking ID: #{$bookingId}\n";
        $text .= "Property: {$propertyName}\n";
        if ($roomNumber) $text .= "Room: {$roomNumber}\n";
        $text .= "Status: {$statusInfo['status']}\n\n";
        $text .= strip_tags($statusInfo['message']) . "\n\n";
        $text .= "View details: {$this->base_url}/user/bookings/details.php?id={$bookingId}\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * Payment Notification Email
     */
    private function createPaymentEmail($name, $details) {
        $status = $details['status'] ?? 'completed';
        $amount = $details['amount'] ?? 0;
        $propertyName = $details['property_name'] ?? 'Your Property';
        $paymentId = $details['payment_id'] ?? 'N/A';
        
        $statusInfo = $this->getPaymentStatusInfo($status);
        
        $subject = "Payment {$statusInfo['status']} - GHS " . number_format($amount, 2);
        
        $html = $this->createEmailTemplate($name, $statusInfo['icon'], $statusInfo['color'],
            "Payment {$statusInfo['status']}", "
            <p>{$statusInfo['message']}</p>
            
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <div style='margin-bottom: 10px;'>
                    <strong>Payment ID:</strong> #{$paymentId}
                </div>
                <div style='margin-bottom: 10px;'>
                    <strong>Amount:</strong> <span style='font-size: 24px; color: #2c3e50;'>GHS " . number_format($amount, 2) . "</span>
                </div>
                <div style='margin-bottom: 10px;'>
                    <strong>Property:</strong> {$propertyName}
                </div>
                <div>
                    <strong>Status:</strong> <span style='color: {$statusInfo['color']};'>{$statusInfo['status']}</span>
                </div>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/payments/payment_history.php' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    View Payment History
                </a>
            </div>
        ");
        
        $text = "Payment {$statusInfo['status']} - GHS " . number_format($amount, 2) . "\n\n";
        $text .= "Dear {$name},\n\n" . strip_tags($statusInfo['message']) . "\n\n";
        $text .= "Payment ID: #{$paymentId}\n";
        $text .= "Amount: GHS " . number_format($amount, 2) . "\n";
        $text .= "Property: {$propertyName}\n";
        $text .= "Status: {$statusInfo['status']}\n\n";
        $text .= "View payment history: {$this->base_url}/user/payments/payment_history.php\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * Maintenance Request Email
     */
    private function createMaintenanceEmail($name, $details) {
        $status = $details['status'] ?? 'pending';
        $title = $details['title'] ?? 'Maintenance Request';
        $propertyName = $details['property_name'] ?? 'Your Property';
        $requestId = $details['request_id'] ?? 'N/A';
        
        $statusInfo = $this->getMaintenanceStatusInfo($status);
        
        $subject = "Maintenance Request {$statusInfo['status']} - {$title}";
        
        $html = $this->createEmailTemplate($name, $statusInfo['icon'], $statusInfo['color'],
            "Maintenance Request {$statusInfo['status']}", "
            <p>{$statusInfo['message']}</p>
            
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <div style='margin-bottom: 10px;'>
                    <strong>Request ID:</strong> #{$requestId}
                </div>
                <div style='margin-bottom: 10px;'>
                    <strong>Title:</strong> {$title}
                </div>
                <div style='margin-bottom: 10px;'>
                    <strong>Property:</strong> {$propertyName}
                </div>
                <div>
                    <strong>Status:</strong> <span style='color: {$statusInfo['color']};'>{$statusInfo['status']}</span>
                </div>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/maintenance/' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    View Maintenance Requests
                </a>
            </div>
        ");
        
        $text = "Maintenance Request {$statusInfo['status']} - {$title}\n\n";
        $text .= "Dear {$name},\n\n" . strip_tags($statusInfo['message']) . "\n\n";
        $text .= "Request ID: #{$requestId}\n";
        $text .= "Title: {$title}\n";
        $text .= "Property: {$propertyName}\n";
        $text .= "Status: {$statusInfo['status']}\n\n";
        $text .= "View requests: {$this->base_url}/user/maintenance/\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * Announcement Email
     */
    private function createAnnouncementEmail($name, $details) {
        $title = $details['title'] ?? 'New Announcement';
        $content = $details['content'] ?? '';
        
        $subject = "📢 New Announcement: {$title}";
        
        $html = $this->createEmailTemplate($name, '📢', '#3498db',
            "New Announcement", "
            <h2 style='color: #2c3e50; margin: 20px 0;'>{$title}</h2>
            
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; line-height: 1.6;'>
                " . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . "
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/announcement.php' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    View All Announcements
                </a>
            </div>
        ");
        
        $text = "New Announcement: {$title}\n\n";
        $text .= "Dear {$name},\n\n{$content}\n\n";
        $text .= "View all announcements: {$this->base_url}/user/announcement.php\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * System Alert Email
     */
    private function createSystemAlertEmail($name, $details) {
        $message = $details['message'] ?? 'System notification';
        
        $subject = "⚠️ System Alert - Action Required";
        
        $html = $this->createEmailTemplate($name, '⚠️', '#e74c3c',
            "System Alert", "
            <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                <strong style='color: #856404;'>Important System Notification</strong>
                <p style='color: #856404; margin: 10px 0 0 0;'>{$message}</p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/dashboard.php' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    Go to Dashboard
                </a>
            </div>
        ");
        
        $text = "System Alert - Action Required\n\n";
        $text .= "Dear {$name},\n\n{$message}\n\n";
        $text .= "Go to dashboard: {$this->base_url}/user/dashboard.php\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * General Notification Email
     */
    private function createGeneralEmail($name, $details) {
        $message = $details['message'] ?? 'You have a new notification';
        
        $subject = "New Notification - Landlords & Tenant";
        
        $html = $this->createEmailTemplate($name, '🔔', '#3498db',
            "New Notification", "
            <p>{$message}</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$this->base_url}/user/notification/' 
                   style='display: inline-block; padding: 12px 30px; background-color: #3498db; color: white !important; 
                          text-decoration: none; border-radius: 5px; font-weight: 600;'>
                    View Notifications
                </a>
            </div>
        ");
        
        $text = "New Notification\n\n";
        $text .= "Dear {$name},\n\n{$message}\n\n";
        $text .= "View notifications: {$this->base_url}/user/notification/\n\n";
        $text .= "Best regards,\nLandlords & Tenant Team";
        
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
    
    /**
     * Create email template wrapper
     */
    private function createEmailTemplate($name, $icon, $color, $title, $content) {
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$title}</title>
        </head>
        <body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f5f7fa;'>
            <div style='max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                <div style='background: linear-gradient(135deg, {$color}, " . $this->darkenColor($color) . "); color: white; padding: 30px 20px; text-align: center;'>
                    <div style='font-size: 48px; margin-bottom: 10px;'>{$icon}</div>
                    <h1 style='margin: 0; font-size: 24px; font-weight: 600;'>{$title}</h1>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #2c3e50; margin-top: 0; font-size: 20px;'>Dear {$safe_name},</h2>
                    {$content}
                </div>
                
                <div style='background-color: #2c3e50; color: #ecf0f1; padding: 20px; text-align: center; font-size: 14px;'>
                    <p><strong>Contact Us</strong></p>
                    <p style='margin: 5px 0;'>📞 Phone: +233 240687599</p>
                    <p style='margin: 5px 0;'>📧 Email: appiahjoseph020458@gmail.com</p>
                    <p style='margin: 5px 0;'>📍 Koforidua Technical University Campus</p>
                    <p style='margin-top: 15px; font-size: 12px; color: #95a5a6;'>
                        This is an automated notification from Landlords & Tenant System.
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Helper methods for status information
     */
    private function getBookingStatusInfo($status) {
        $info = [
            'confirmed' => [
                'status' => 'Confirmed',
                'action' => 'Confirmed',
                'icon' => '✅',
                'color' => '#28a745',
                'message' => '<p style="color: #28a745;"><strong>Great news!</strong> Your booking has been confirmed by the property owner.</p>'
            ],
            'rejected' => [
                'status' => 'Rejected',
                'action' => 'Rejected',
                'icon' => '❌',
                'color' => '#dc3545',
                'message' => '<p>Unfortunately, your booking has been rejected. You can browse other available properties.</p>'
            ],
            'cancelled' => [
                'status' => 'Cancelled',
                'action' => 'Cancelled',
                'icon' => '⚠️',
                'color' => '#ffc107',
                'message' => '<p>Your booking has been cancelled. If this was not expected, please contact us.</p>'
            ],
            'paid' => [
                'status' => 'Payment Confirmed',
                'action' => 'Updated',
                'icon' => '💳',
                'color' => '#17a2b8',
                'message' => '<p style="color: #17a2b8;"><strong>Payment received!</strong> Your booking payment has been confirmed.</p>'
            ]
        ];
        
        return $info[$status] ?? [
            'status' => ucfirst($status),
            'action' => 'Updated',
            'icon' => '🔔',
            'color' => '#6c757d',
            'message' => '<p>Your booking status has been updated.</p>'
        ];
    }
    
    private function getPaymentStatusInfo($status) {
        $info = [
            'completed' => [
                'status' => 'Completed',
                'icon' => '✅',
                'color' => '#28a745',
                'message' => '<p style="color: #28a745;"><strong>Payment successful!</strong> Your payment has been completed successfully.</p>'
            ],
            'failed' => [
                'status' => 'Failed',
                'icon' => '❌',
                'color' => '#dc3545',
                'message' => '<p style="color: #dc3545;">Your payment attempt has failed. Please try again or use a different payment method.</p>'
            ],
            'pending' => [
                'status' => 'Pending',
                'icon' => '⏳',
                'color' => '#ffc107',
                'message' => '<p>Your payment is being processed. We\'ll notify you once it\'s complete.</p>'
            ]
        ];
        
        return $info[$status] ?? [
            'status' => ucfirst($status),
            'icon' => '💳',
            'color' => '#6c757d',
            'message' => '<p>Your payment status has been updated.</p>'
        ];
    }
    
    private function getMaintenanceStatusInfo($status) {
        $info = [
            'pending' => [
                'status' => 'Pending',
                'icon' => '⏳',
                'color' => '#ffc107',
                'message' => '<p>Your maintenance request has been submitted and is awaiting review.</p>'
            ],
            'in_progress' => [
                'status' => 'In Progress',
                'icon' => '🔧',
                'color' => '#17a2b8',
                'message' => '<p style="color: #17a2b8;">Good news! Work has started on your maintenance request.</p>'
            ],
            'completed' => [
                'status' => 'Completed',
                'icon' => '✅',
                'color' => '#28a745',
                'message' => '<p style="color: #28a745;"><strong>All done!</strong> Your maintenance request has been completed.</p>'
            ],
            'cancelled' => [
                'status' => 'Cancelled',
                'icon' => '❌',
                'color' => '#dc3545',
                'message' => '<p>Your maintenance request has been cancelled.</p>'
            ]
        ];
        
        return $info[$status] ?? [
            'status' => ucfirst($status),
            'icon' => '🔧',
            'color' => '#6c757d',
            'message' => '<p>Your maintenance request status has been updated.</p>'
        ];
    }
    
    /**
     * Darken color for gradient
     */
    private function darkenColor($hex, $percent = 20) {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = max(0, $r - ($r * $percent / 100));
        $g = max(0, $g - ($g * $percent / 100));
        $b = max(0, $b - ($b * $percent / 100));
        
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) 
                  . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) 
                  . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get last error
     */
    public function getLastError() {
        return $this->mail->ErrorInfo;
    }
}
