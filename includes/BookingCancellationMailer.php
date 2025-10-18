<?php
/**
 * Booking Cancellation Email Notification System
 * 
 * A simple, standalone email system specifically for sending
 * booking cancellation notifications to students.
 * Does not interfere with the existing EmailService.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once dirname(__DIR__) . '/vendor/autoload.php';

class BookingCancellationMailer {
    private $mail;
    private $from_email;
    private $from_name;
    
    public function __construct() {
        // Load email configuration
        if (file_exists(dirname(__DIR__) . '/config/email.php')) {
            require_once dirname(__DIR__) . '/config/email.php';
        }
        
        $this->from_email = defined('DEFAULT_FROM_EMAIL') ? DEFAULT_FROM_EMAIL : 'noreply@landlordstenant.com';
        $this->from_name = defined('DEFAULT_FROM_NAME') ? DEFAULT_FROM_NAME : 'Landlords & Tenant System';
        
        $this->initializeMailer();
    }
    
    /**
     * Initialize PHPMailer
     */
    private function initializeMailer() {
        $this->mail = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $this->mail->SMTPAuth = true;
            $this->mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
            $this->mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
            
            // Encryption
            if (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $this->mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 465;
            } else {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $this->mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
            }
            
            $this->mail->Timeout = 30;
            $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
            
            // SMTP options for better compatibility
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
            error_log("BookingCancellationMailer initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Send booking cancellation notification
     */
    public function sendCancellationNotification($recipientEmail, $recipientName, $bookingDetails) {
        try {
            // Clear any previous recipients
            $this->mail->clearAddresses();
            $this->mail->clearReplyTos();
            $this->mail->clearAttachments();
            
            // Validate recipient email
            if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            
            // Set sender
            $this->mail->setFrom($this->from_email, $this->from_name);
            
            // Set recipient
            $this->mail->addAddress($recipientEmail, $recipientName);
            
            // Email content
            $this->mail->isHTML(true);
            $this->mail->Subject = "Booking Cancellation Notice - Payment Deadline Expired";
            
            // Create HTML body
            $html_body = $this->createCancellationEmailTemplate($recipientName, $bookingDetails);
            $this->mail->Body = $html_body;
            
            // Create plain text alternative
            $text_body = $this->createCancellationEmailTextVersion($recipientName, $bookingDetails);
            $this->mail->AltBody = $text_body;
            
            // Send email
            $result = $this->mail->send();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("BookingCancellationMailer error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create HTML email template
     */
    private function createCancellationEmailTemplate($name, $details) {
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_property = htmlspecialchars($details['property_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $safe_room = htmlspecialchars($details['room_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $booking_id = htmlspecialchars($details['booking_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $cancelled_date = date('F d, Y \a\t h:i A');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Booking Cancellation Notice</title>
            <style>
                body {
                    font-family: 'Segoe UI', Arial, sans-serif;
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
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #e74c3c, #c0392b);
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                    font-weight: 600;
                }
                .header .icon {
                    font-size: 48px;
                    margin-bottom: 10px;
                }
                .content {
                    padding: 30px;
                }
                .content h2 {
                    color: #2c3e50;
                    margin-top: 0;
                    font-size: 20px;
                }
                .alert-box {
                    background-color: #fee;
                    border-left: 4px solid #e74c3c;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .info-box {
                    background-color: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 10px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    font-weight: 600;
                    color: #6c757d;
                }
                .info-value {
                    color: #2c3e50;
                }
                .action-box {
                    background-color: #e7f3ff;
                    border-left: 4px solid #3498db;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background-color: #3498db;
                    color: white !important;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 10px 0;
                    font-weight: 600;
                }
                .btn:hover {
                    background-color: #2980b9;
                }
                .footer {
                    background-color: #2c3e50;
                    color: #ecf0f1;
                    padding: 20px;
                    text-align: center;
                    font-size: 14px;
                }
                .footer a {
                    color: #3498db;
                    text-decoration: none;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='icon'>⚠️</div>
                    <h1>Booking Automatically Cancelled</h1>
                </div>
                
                <div class='content'>
                    <h2>Dear {$safe_name},</h2>
                    
                    <div class='alert-box'>
                        <strong>⏱️ Payment Deadline Expired</strong><br>
                        Your booking has been automatically cancelled because payment was not completed within the 24-hour deadline.
                    </div>
                    
                    <p>We're writing to inform you that the following booking has been cancelled due to non-payment:</p>
                    
                    <div class='info-box'>
                        <div class='info-row'>
                            <span class='info-label'>Booking ID:</span>
                            <span class='info-value'>#{$booking_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Property:</span>
                            <span class='info-value'>{$safe_property}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Room:</span>
                            <span class='info-value'>Room {$safe_room}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Cancelled Date:</span>
                            <span class='info-value'>{$cancelled_date}</span>
                        </div>
                    </div>
                    
                    <div class='action-box'>
                        <strong>📌 What This Means:</strong>
                        <ul style='margin: 10px 0; padding-left: 20px;'>
                            <li>Your booking reservation has been released</li>
                            <li>The room is now available for other students</li>
                            <li>No charges were made to your account</li>
                        </ul>
                    </div>
                    
                    <p><strong>Want to Book Again?</strong></p>
                    <p>You can make a new booking at any time if rooms are still available. We recommend:</p>
                    <ul>
                        <li>✓ Complete payment within 24 hours of booking</li>
                        <li>✓ Keep your payment method ready before booking</li>
                        <li>✓ Check your email regularly for booking notifications</li>
                    </ul>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='http://localhost/projects/Online_Accommodation_System/user/search/' class='btn'>
                            🔍 Search Available Rooms
                        </a>
                    </div>
                    
                    <p>If you have any questions or need assistance, please don't hesitate to contact us.</p>
                    
                    <p>Best regards,<br>
                    <strong>Landlords & Tenant Team</strong></p>
                </div>
                
                <div class='footer'>
                    <p><strong>Contact Us</strong></p>
                    <p>
                        📞 Phone: +233 240687599<br>
                        📧 Email: appiahjoseph020458@gmail.com<br>
                        📍 Location: Koforidua Technical University Campus
                    </p>
                    <p style='margin-top: 15px; font-size: 12px; color: #95a5a6;'>
                        This is an automated message. Please do not reply to this email.
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Create plain text email version
     */
    private function createCancellationEmailTextVersion($name, $details) {
        $property = $details['property_name'] ?? 'N/A';
        $room = $details['room_number'] ?? 'N/A';
        $booking_id = $details['booking_id'] ?? 'N/A';
        $cancelled_date = date('F d, Y \a\t h:i A');
        
        return "
BOOKING AUTOMATICALLY CANCELLED - PAYMENT DEADLINE EXPIRED

Dear {$name},

We're writing to inform you that your booking has been automatically cancelled because payment was not completed within the 24-hour deadline.

CANCELLED BOOKING DETAILS:
--------------------------
Booking ID: #{$booking_id}
Property: {$property}
Room: Room {$room}
Cancelled Date: {$cancelled_date}

WHAT THIS MEANS:
- Your booking reservation has been released
- The room is now available for other students
- No charges were made to your account

WANT TO BOOK AGAIN?
You can make a new booking at any time if rooms are still available.

We recommend:
✓ Complete payment within 24 hours of booking
✓ Keep your payment method ready before booking
✓ Check your email regularly for booking notifications

Search for available rooms at:
http://localhost/projects/Online_Accommodation_System/user/search/

If you have any questions or need assistance, please don't hesitate to contact us.

CONTACT US:
Phone: +233 240687599
Email: appiahjoseph020458@gmail.com
Location: Koforidua Technical University Campus

Best regards,
Landlords & Tenant Team

---
This is an automated message. Please do not reply to this email.
        ";
    }
    
    /**
     * Get last error message
     */
    public function getLastError() {
        return $this->mail->ErrorInfo;
    }
}
