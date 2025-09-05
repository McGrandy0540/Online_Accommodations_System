<?php
/**
 * Email Service Class
 * Handles all email operations with improved error handling and debugging
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php'; // Ensure Composer autoload is included
require_once __DIR__ . '/../config/email.php'; // Email configuration constants
require_once __DIR__ . '/../config/database.php'; // Database class
require_once __DIR__ . '/../EmailHelper.php'; // EmailHelper class

class EmailService {
    private $mail;
    private $debug_mode;
    
    public function __construct($debug_mode = false) {
        $this->debug_mode = $debug_mode;
        $this->initializeMailer();
    }
    
    /**
     * Initialize PHPMailer with proper configuration
     */
    private function initializeMailer() {
        $this->mail = new PHPMailer(true);
        
        try {
            // Server settings - try SSL first
            $this->mail->isSMTP();
            $this->mail->Host = SMTP_HOST;
            $this->mail->SMTPAuth = true;
            $this->mail->Username = SMTP_USERNAME;
            $this->mail->Password = SMTP_PASSWORD;
            
            // Use SSL encryption for port 465
            if (SMTP_ENCRYPTION === 'ssl') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $this->mail->Port = SMTP_PORT;
            $this->mail->Timeout = EMAIL_TIMEOUT;
            
            // Debug settings
            if ($this->debug_mode) {
                $this->mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = function($str, $level) {
                    error_log("PHPMailer Debug Level $level: $str");
                };
            } else {
                $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
            }
            
            // Enhanced SMTP options for better Gmail compatibility
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'cafile' => false,
                    'capath' => false,
                    'ciphers' => 'HIGH:!SSLv2:!SSLv3'
                ),
                'tls' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Character encoding
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            
            // Additional Gmail-specific settings
            $this->mail->SMTPKeepAlive = true;
            $this->mail->Mailer = 'smtp';
            
        } catch (Exception $e) {
            error_log("EmailService initialization failed: " . $e->getMessage());
            throw new Exception("Email service initialization failed");
        }
    }


    /**
     * Send announcement email to a user with enhanced template
     */
    public function sendAnnouncement($recipientEmail, $subject, $message, $senderName = null, $isUrgent = false, $targetGroup = null) {
        try {
            // Clear previous recipients and attachments
            $this->mail->clearAddresses();
            $this->mail->clearReplyTos();
            $this->mail->clearAttachments();

            // Validate recipient email
            if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Recipient email address is missing or invalid.',
                    'debug' => $this->debug_mode ? 'Recipient email: ' . $recipientEmail : null
                ];
            }

            // Set sender
            $this->mail->setFrom(DEFAULT_FROM_EMAIL, DEFAULT_FROM_NAME);

            // Set recipient
            $this->mail->addAddress($recipientEmail);

            // Email content with enhanced template
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            
            // Create HTML body using announcement template
            $html_body = $this->createAnnouncementEmailTemplate($subject, $message, $senderName, $isUrgent, $targetGroup);
            $this->mail->Body = $html_body;
            
            // Create plain text alternative
            $text_body = $this->createAnnouncementEmailTextVersion($subject, $message, $senderName, $isUrgent);
            $this->mail->AltBody = $text_body;

            // Send email with retry logic
            $sendResult = $this->sendWithRetry();

            if ($sendResult['success']) {
                // Log the announcement email
                EmailHelper::logEmailAttempt($recipientEmail, $subject, 'SUCCESS');
                return $sendResult;
            } else {
                EmailHelper::logEmailAttempt($recipientEmail, $subject, 'FAILED', $sendResult['message']);
                return $sendResult;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            EmailHelper::logEmailAttempt($recipientEmail ?? 'unknown', $subject ?? 'Announcement', 'ERROR', $error_message);
            
            return [
                'success' => false,
                'message' => 'Announcement email failed: ' . $error_message,
                'debug' => $this->debug_mode ? $error_message : null
            ];
        }
    }

    /**
     * Send bulk announcement emails to multiple recipients
     */
    public function sendBulkAnnouncement($recipients, $subject, $message, $senderName = null, $isUrgent = false, $targetGroup = null) {
        $results = [
            'total' => count($recipients),
            'sent' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? $recipient['email'] : $recipient;
            $result = $this->sendAnnouncement($email, $subject, $message, $senderName, $isUrgent, $targetGroup);
            
            if ($result['success']) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'email' => $email,
                    'error' => $result['message']
                ];
            }
            
            // Small delay to prevent overwhelming the SMTP server
            usleep(100000); // 0.1 second delay
        }

        return $results;
    }
    
    /**
     * Send contact form email to admin or specific property owner
     */
    public function sendContactFormEmail($name, $email, $subject, $message, $owner_id = null) {
        try {
        // Clear any previous recipients
        $this->mail->clearAddresses();
        $this->mail->clearReplyTos();
        $this->mail->clearAttachments();

        // Get recipient email - either specific property owner or admin
        if ($owner_id) {
            $recipient_email = EmailHelper::getPropertyOwnerEmail($owner_id);
            $recipient_type = 'property owner';
        } else {
            $recipient_email = EmailHelper::getAdminEmail();
            $recipient_type = 'admin';
        }

        // Validate recipient email
        if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => ucfirst($recipient_type) . ' email address is missing or invalid.',
                'debug' => $this->debug_mode ? ucfirst($recipient_type) . ' email: ' . $recipient_email : null
            ];
        }

        // Set sender
        $this->mail->setFrom(DEFAULT_FROM_EMAIL, DEFAULT_FROM_NAME);
        $this->mail->addReplyTo($email, $name);

        // Set recipient
        $this->mail->addAddress($recipient_email);
            // Email content
            $this->mail->isHTML(true);
            $this->mail->Subject = "New Contact Message: " . ($subject ?: "No Subject");
            
            // Create HTML body
            $html_body = $this->createContactEmailTemplate($name, $email, $subject, $message);
            $this->mail->Body = $html_body;
            
            // Create plain text alternative
            $text_body = $this->createContactEmailTextVersion($name, $email, $subject, $message);
            $this->mail->AltBody = $text_body;
            
            // Send email with retry logic
            $sendResult = $this->sendWithRetry();
            
            if ($sendResult['success']) {
                EmailHelper::logEmailAttempt($recipient_email, $this->mail->Subject, 'SUCCESS');
                return $sendResult;
            } else {
                EmailHelper::logEmailAttempt($recipient_email, $this->mail->Subject, 'FAILED', $sendResult['message']);
                return $sendResult;
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            EmailHelper::logEmailAttempt($owner_email ?? 'unknown', $subject ?? 'Contact Form', 'ERROR', $error_message);
            
            return [
                'success' => false, 
                'message' => 'Email sending failed: ' . $error_message,
                'debug' => $this->debug_mode ? $error_message : null
            ];
        }
    }
    
    /**
     * Send confirmation email to user
     */
    public function sendConfirmationEmail($name, $email, $message) {
        try {
  // Clear any previous recipients
        $this->mail->clearAddresses();
        $this->mail->clearReplyTos();

        // Validate user email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Recipient email address is missing or invalid.',
                'debug' => $this->debug_mode ? 'Recipient email: ' . $email : null
            ];
        }

        // Set sender
        $this->mail->setFrom(DEFAULT_FROM_EMAIL, DEFAULT_FROM_NAME);

        // Set recipient
        $this->mail->addAddress($email, $name);
            
            // Email content
            $this->mail->isHTML(true);
            $this->mail->Subject = "Thank you for contacting Landlords&Tenant";
            
            // Create HTML body
            $html_body = $this->createConfirmationEmailTemplate($name, $message);
            $this->mail->Body = $html_body;
            
            // Create plain text alternative
            $text_body = $this->createConfirmationEmailTextVersion($name, $message);
            $this->mail->AltBody = $text_body;
            
            // Send email with retry logic
            $sendResult = $this->sendWithRetry();
            
            if ($sendResult['success']) {
                EmailHelper::logEmailAttempt($email, $this->mail->Subject, 'SUCCESS');
                return $sendResult;
            } else {
                EmailHelper::logEmailAttempt($email, $this->mail->Subject, 'FAILED', $sendResult['message']);
                return $sendResult;
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            EmailHelper::logEmailAttempt($email, 'Confirmation Email', 'ERROR', $error_message);
            
            return [
                'success' => false, 
                'message' => 'Confirmation email failed: ' . $error_message,
                'debug' => $this->debug_mode ? $error_message : null
            ];
        }
    }
    
    /**
     * Create HTML template for contact email
     */
    private function createContactEmailTemplate($name, $email, $subject, $message) {
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safe_subject = htmlspecialchars($subject ?: "None", ENT_QUOTES, 'UTF-8');
        $safe_message = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $current_time = date('Y-m-d H:i:s');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>New Contact Message</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #3498db; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 20px; }
                .field { margin-bottom: 15px; }
                .label { font-weight: bold; color: #2c3e50; }
                .message-box { background: white; padding: 15px; border-left: 4px solid #3498db; margin: 15px 0; }
                .footer { background: #34495e; color: white; padding: 15px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>New Contact Message Received</h2>
                </div>
                <div class='content'>
                    <div class='field'>
                        <span class='label'>From:</span> {$safe_name} ({$safe_email})
                    </div>
                    <div class='field'>
                        <span class='label'>Subject:</span> {$safe_subject}
                    </div>
                    <div class='field'>
                        <span class='label'>Message:</span>
                        <div class='message-box'>{$safe_message}</div>
                    </div>
                    <div class='field'>
                        <span class='label'>Received:</span> {$current_time}
                    </div>
                    <div class='field'>
                        <span class='label'>IP Address:</span> {$ip_address}
                    </div>
                </div>
                <div class='footer'>
                    <p>This message was sent through the Landlords&Tenants contact form.</p>
                    <p>Please reply directly to {$safe_email} to respond to this inquiry.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Create plain text version for contact email
     */
    private function createContactEmailTextVersion($name, $email, $subject, $message) {
        $current_time = date('Y-m-d H:i:s');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        return "
NEW CONTACT MESSAGE RECEIVED

From: {$name} ({$email})
Subject: " . ($subject ?: "None") . "

Message:
{$message}

Received: {$current_time}
IP Address: {$ip_address}

---
This message was sent through the Landlords&Tenants contact form.
Please reply directly to {$email} to respond to this inquiry.
        ";
    }
    
    /**
     * Create HTML template for confirmation email
     */
    private function createConfirmationEmailTemplate($name, $message) {
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_message = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Thank you for contacting Landlords&Tenants</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #28a745; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 20px; }
                .message-box { background: white; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; }
                .footer { background: #34495e; color: white; padding: 15px; text-align: center; }
                .contact-info { background: #e9ecef; padding: 15px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Thank you for your message, {$safe_name}!</h2>
                </div>
                <div class='content'>
                    <p>We've received your message and our team will get back to you as soon as possible.</p>
                    
                    <p><strong>Your message:</strong></p>
                    <div class='message-box'>{$safe_message}</div>
                    
                    <div class='contact-info'>
                        <h3>Need immediate assistance?</h3>
                        <p><strong>Phone:</strong> +233 240687599</p>
                        <p><strong>Email:</strong> appiahjoseph020458@gmail.com</p>
                        <p><strong>Location:</strong> Koforidua Technical University campus</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>Thank you for choosing Landlords&Tenant!</p>
                    <p>Your trusted partner for online accommodation solutions.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Create plain text version for confirmation email
     */
    private function createConfirmationEmailTextVersion($name, $message) {
        return "
THANK YOU FOR YOUR MESSAGE, {$name}!

We've received your message and our team will get back to you as soon as possible.

Your message:
{$message}

NEED IMMEDIATE ASSISTANCE?
Phone: +233 240687599
Email: appiahjoseph020458@gmail.com
Location: Koforidua Technical University campus

---
Thank you for choosing Landlords&Tenant!
Your trusted partner for Online accommodation solutions.
        ";
    }

    /**
     * Create HTML template for announcement email
     */
    private function createAnnouncementEmailTemplate($subject, $message, $senderName = null, $isUrgent = false, $targetGroup = null) {
        $safe_subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safe_message = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $safe_sender = htmlspecialchars($senderName ?: 'Property Owner', ENT_QUOTES, 'UTF-8');
        $current_time = date('Y-m-d H:i:s');
        
        // Determine urgency styling
        $urgency_class = $isUrgent ? 'urgent' : 'normal';
        $urgency_color = $isUrgent ? '#e74c3c' : '#3498db';
        $urgency_text = $isUrgent ? 'URGENT ANNOUNCEMENT' : 'ANNOUNCEMENT';
        
        // Determine target group display
        $target_display = '';
        switch ($targetGroup) {
            case 'all':
                $target_display = 'All Users';
                break;
            case 'admin':
                $target_display = 'Administrators';
                break;
            case 'my_properties':
                $target_display = 'All Tenants';
                break;
            case 'specific_property':
                $target_display = 'Property Tenants';
                break;
            case 'specific_room':
                $target_display = 'Room Occupants';
                break;
            case 'specific_student':
                $target_display = 'Individual Student';
                break;
            default:
                $target_display = 'Recipients';
                break;
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>{$safe_subject}</title>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0; 
                    padding: 0; 
                    background-color: #f5f7fa;
                }
                .container { 
                    max-width: 600px; 
                    margin: 20px auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                }
                .header { 
                    background: {$urgency_color}; 
                    color: white; 
                    padding: 25px 20px; 
                    text-align: center;
                    position: relative;
                }
                .header::before {
                    content: '';
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    height: 4px;
                    background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
                }
                .urgency-badge {
                    display: inline-block;
                    background: rgba(255, 255, 255, 0.2);
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 0.9rem;
                    font-weight: bold;
                    margin-bottom: 10px;
                    letter-spacing: 1px;
                }
                .header h1 {
                    margin: 0;
                    font-size: 1.8rem;
                    font-weight: 600;
                }
                .content { 
                    padding: 30px 25px; 
                }
                .announcement-meta {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 25px;
                    border-left: 4px solid {$urgency_color};
                }
                .meta-item {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 8px;
                    font-size: 0.9rem;
                }
                .meta-item:last-child {
                    margin-bottom: 0;
                }
                .meta-label {
                    font-weight: 600;
                    color: #2c3e50;
                }
                .meta-value {
                    color: #7f8c8d;
                }
                .message-content {
                    background: #ffffff;
                    padding: 25px;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    margin: 20px 0;
                    font-size: 1.1rem;
                    line-height: 1.7;
                }
                .footer { 
                    background: #34495e; 
                    color: white; 
                    padding: 20px 25px; 
                    text-align: center; 
                }
                .footer p {
                    margin: 5px 0;
                    font-size: 0.9rem;
                }
                .contact-info {
                    background: #ecf0f1;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 8px;
                    text-align: center;
                }
                .contact-info h3 {
                    color: #2c3e50;
                    margin-bottom: 15px;
                    font-size: 1.2rem;
                }
                .contact-item {
                    margin: 8px 0;
                    font-size: 0.95rem;
                }
                .urgent-notice {
                    background: #fff5f5;
                    border: 2px solid #fed7d7;
                    color: #c53030;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    text-align: center;
                    font-weight: 600;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='urgency-badge'>{$urgency_text}</div>
                    <h1>{$safe_subject}</h1>
                </div>
                <div class='content'>
                    " . ($isUrgent ? "<div class='urgent-notice'>⚠️ This is an urgent announcement that requires your immediate attention!</div>" : "") . "
                    
                    <div class='announcement-meta'>
                        <div class='meta-item'>
                            <span class='meta-label'>From:</span>
                            <span class='meta-value'>{$safe_sender}</span>
                        </div>
                        <div class='meta-item'>
                            <span class='meta-label'>Target Group:</span>
                            <span class='meta-value'>{$target_display}</span>
                        </div>
                        <div class='meta-item'>
                            <span class='meta-label'>Date:</span>
                            <span class='meta-value'>{$current_time}</span>
                        </div>
                    </div>
                    
                    <div class='message-content'>
                        {$safe_message}
                    </div>
                    
                    <div class='contact-info'>
                        <h3>Need to Contact Us?</h3>
                        <div class='contact-item'><strong>📞 Phone:</strong> +233 240687599</div>
                        <div class='contact-item'><strong>📧 Email:</strong> appiahjoseph020458@gmail.com</div>
                        <div class='contact-item'><strong>📍 Location:</strong> Koforidua Technical University Campus</div>
                    </div>
                </div>
                <div class='footer'>
                    <p><strong>Landlords&Tenant System</strong></p>
                    <p>Your trusted partner for online accommodation solutions</p>
                    <p style='font-size: 0.8rem; margin-top: 10px; opacity: 0.8;'>
                        This announcement was sent through the Landlords&Tenant platform.
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Create plain text version for announcement email
     */
    private function createAnnouncementEmailTextVersion($subject, $message, $senderName = null, $isUrgent = false) {
        $sender = $senderName ?: 'Property Owner';
        $current_time = date('Y-m-d H:i:s');
        $urgency_text = $isUrgent ? '[URGENT] ' : '';
        
        return "
{$urgency_text}ANNOUNCEMENT: {$subject}

" . ($isUrgent ? "⚠️ URGENT: This announcement requires your immediate attention!\n\n" : "") . "From: {$sender}
Date: {$current_time}

MESSAGE:
{$message}

---
CONTACT INFORMATION:
Phone: +233 240687599
Email: appiahjoseph020458@gmail.com
Location: Koforidua Technical University Campus

---
Landlords&Tenant System
Your trusted partner for online accommodation solutions

This announcement was sent through the Landlords&Tenant platform.
        ";
    }
    
    /**
     * Test email configuration with retry logic
     */
    public function testEmailConfiguration() {
        $attempts = 0;
        $maxAttempts = 2;
        $lastError = '';
        
        while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                
                // Test SMTP connection
                $this->mail->smtpConnect();
                $this->mail->smtpClose();
                
                return ['success' => true, 'message' => 'Email configuration is working'];
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                
                // If first attempt failed and we have fallback settings, try them
                if ($attempts === 1 && defined('SMTP_HOST_FALLBACK')) {
                    $this->initializeMailerWithFallback();
                    continue;
                }
                
                // If this is the last attempt, return the error
                if ($attempts >= $maxAttempts) {
                    break;
                }
                
                // Wait a bit before retry
                sleep(1);
            }
        }
        
        return ['success' => false, 'message' => 'Email configuration failed after ' . $maxAttempts . ' attempts: ' . $lastError];
    }
    
    /**
     * Initialize PHPMailer with fallback configuration
     */
    private function initializeMailerWithFallback() {
        try {
            // Reset mailer with fallback settings
            $this->mail = new PHPMailer(true);
            
            $this->mail->isSMTP();
            $this->mail->Host = SMTP_HOST_FALLBACK;
            $this->mail->SMTPAuth = true;
            $this->mail->Username = SMTP_USERNAME;
            $this->mail->Password = SMTP_PASSWORD;
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mail->Port = SMTP_PORT_FALLBACK;
            $this->mail->Timeout = EMAIL_TIMEOUT;
            
            // Debug settings
            if ($this->debug_mode) {
                $this->mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = function($str, $level) {
                    error_log("PHPMailer Fallback Debug Level $level: $str");
                };
            } else {
                $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
            }
            
            // Enhanced SMTP options
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'cafile' => false,
                    'capath' => false,
                    'ciphers' => 'HIGH:!SSLv2:!SSLv3'
                ),
                'tls' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            $this->mail->SMTPKeepAlive = true;
            $this->mail->Mailer = 'smtp';
            
        } catch (Exception $e) {
            error_log("EmailService fallback initialization failed: " . $e->getMessage());
            throw new Exception("Email service fallback initialization failed");
        }
    }
    
    /**
     * Send email with retry logic
     */
    private function sendWithRetry($maxAttempts = 2) {
        $attempts = 0;
        $lastError = '';
        
        while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                $result = $this->mail->send();
                
                if ($result) {
                    return ['success' => true, 'message' => 'Email sent successfully'];
                }
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                
                // If first attempt failed and we have fallback settings, try them
                if ($attempts === 1 && defined('SMTP_HOST_FALLBACK')) {
                    $this->initializeMailerWithFallback();
                    continue;
                }
                
                // If this is the last attempt, break
                if ($attempts >= $maxAttempts) {
                    break;
                }
                
                // Wait a bit before retry
                sleep(1);
            }
        }
        
        return ['success' => false, 'message' => 'Email sending failed after ' . $maxAttempts . ' attempts: ' . $lastError];
    }
    
    /**
     * Get last error message
     */
    public function getLastError() {
        return $this->mail->ErrorInfo;
    }
}
?>
