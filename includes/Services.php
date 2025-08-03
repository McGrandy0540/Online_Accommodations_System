<?php
/**
 * Email Service Class
 * Handles all email operations with improved error handling and debugging
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

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
     * Send contact form email to admin
     */
    public function sendContactFormEmail($name, $email, $subject, $message) {
        try {
            // Clear any previous recipients
            $this->mail->clearAddresses();
            $this->mail->clearReplyTos();
            $this->mail->clearAttachments();
            
            // Get admin email
            $admin_email = EmailHelper::getAdminEmail();
            
            // Set sender
            $this->mail->setFrom(DEFAULT_FROM_EMAIL, DEFAULT_FROM_NAME);
            $this->mail->addReplyTo($email, $name);
            
            
            // Set recipient
            $this->mail->addAddress($admin_email);
            
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
                EmailHelper::logEmailAttempt($admin_email, $this->mail->Subject, 'SUCCESS');
                return $sendResult;
            } else {
                EmailHelper::logEmailAttempt($admin_email, $this->mail->Subject, 'FAILED', $sendResult['message']);
                return $sendResult;
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            EmailHelper::logEmailAttempt($admin_email ?? 'unknown', $subject ?? 'Contact Form', 'ERROR', $error_message);
            
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
                    <p>This message was sent through the Landlords&Tenant contact form.</p>
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
This message was sent through the Landlords&Tenant contact form.
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
            <title>Thank you for contacting Landlords&Tenant</title>
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
                    <p>Thank you for choosing Landlords&Tenants!</p>
                    <p>Your trusted partner for Online accommodation solutions.</p>
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