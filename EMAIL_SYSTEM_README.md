# UniHomes Email System - Setup and Troubleshooting Guide

## Overview
The UniHomes contact form email system has been completely rewritten with improved error handling, debugging capabilities, and better email delivery reliability.

## Files Created/Modified

### New Files:
1. `config/email.php` - Email configuration and helper functions
2. `includes/EmailService.php` - Main email service class
3. `test_email.php` - Email testing script
4. `EMAIL_SYSTEM_README.md` - This documentation

### Modified Files:
1. `index.php` - Updated to use the new email system

## Features

### ✅ Improvements Made:
- **Enhanced SMTP Configuration**: Better Gmail compatibility with proper SSL/TLS settings
- **Professional Email Templates**: HTML and plain text versions for both admin and confirmation emails
- **Rate Limiting**: Prevents spam by limiting emails per IP address per hour
- **Comprehensive Error Handling**: Detailed logging and user-friendly error messages
- **Admin Email Management**: Automatically retrieves admin email from database with fallback
- **Email Validation**: Proper input sanitization and validation
- **Debug Mode**: Detailed debugging information for troubleshooting
- **Fallback Mechanisms**: Graceful handling when emails fail to send

### 📧 Email Types:
1. **Admin Notification Email**: Sent to admin when contact form is submitted
2. **User Confirmation Email**: Sent to user confirming message receipt

## Configuration

### Gmail Setup Requirements:
1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate App Password**:
   - Go to Google Account settings
   - Security → 2-Step Verification → App passwords
   - Generate password for "Mail"
   - Use this password in `config/email.php`

### Email Configuration (config/email.php):
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password'); // 16-character app password
define('SMTP_ENCRYPTION', 'tls');
define('DEFAULT_ADMIN_EMAIL', 'admin@yourdomain.com');
```

## Testing the Email System

### Step 1: Run the Email Test Script
1. Navigate to: `http://your-domain.com/test_email.php`
2. Check all test results:
   - File Loading Test
   - Configuration Test
   - Database and Admin Email Test
   - SMTP Connection Test

### Step 2: Send Test Email
1. Fill out the test form on the same page
2. Use a valid email address you can check
3. Click "Send Test Email"
4. Check both admin and user email addresses for received emails

### Step 3: Test Contact Form
1. Go to your main website: `http://your-domain.com/index.php#contact`
2. Fill out the contact form
3. Submit and check for success message
4. Verify emails are received

## Troubleshooting

### Common Issues and Solutions:

#### 1. SMTP Connection Failed
**Symptoms**: "SMTP connection failed" error
**Solutions**:
- Verify Gmail app password is correct (16 characters, no spaces)
- Ensure 2FA is enabled on Gmail account
- Check if "Less secure app access" is disabled (should be disabled, use app password instead)
- Try changing port from 587 to 465 and encryption from 'tls' to 'ssl'

#### 2. Authentication Failed
**Symptoms**: "SMTP authentication failed" error
**Solutions**:
- Double-check Gmail username and app password
- Regenerate app password in Gmail settings
- Ensure no extra spaces in credentials

#### 3. Timeout Issues
**Symptoms**: Connection timeout errors
**Solutions**:
- Check server firewall allows outbound connections on port 587
- Increase timeout value in `config/email.php`
- Contact hosting provider about SMTP restrictions

#### 4. Emails Not Received
**Symptoms**: No error but emails don't arrive
**Solutions**:
- Check spam/junk folders
- Verify email addresses are correct
- Check email server logs
- Enable debug mode to see detailed SMTP communication

#### 5. Rate Limiting
**Symptoms**: "Too many messages sent" error
**Solutions**:
- Wait an hour before testing again
- Adjust `EMAIL_RATE_LIMIT` in `config/email.php`
- Clear rate limit by deleting recent entries from `contact_messages` table

### Debug Mode
To enable detailed debugging:
1. In `index.php`, change: `$emailService = new EmailService(true);`
2. Check error logs for detailed SMTP communication
3. Remember to disable debug mode in production

### Alternative SMTP Settings
If Gmail doesn't work, try these settings:

#### Gmail Alternative (SSL):
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');
```

#### Other Email Providers:
- **Outlook/Hotmail**: smtp-mail.outlook.com, port 587, TLS
- **Yahoo**: smtp.mail.yahoo.com, port 587, TLS
- **Custom SMTP**: Contact your hosting provider for settings

## Security Considerations

### Production Checklist:
- [ ] Delete `test_email.php` after testing
- [ ] Disable debug mode in production
- [ ] Use environment variables for sensitive credentials
- [ ] Enable HTTPS for contact form
- [ ] Implement CSRF protection
- [ ] Monitor email logs for suspicious activity

### Environment Variables (Recommended):
Instead of hardcoding credentials, use environment variables:
```php
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? 'fallback@gmail.com');
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? 'fallback-password');
```

## Monitoring and Maintenance

### Log Files:
- Check PHP error logs for email-related errors
- Monitor `contact_messages` table for form submissions
- Review email delivery success rates

### Regular Maintenance:
- Clean up old contact messages periodically
- Monitor email sending limits
- Update app passwords if they expire
- Test email system monthly

## Support

### If Issues Persist:
1. Check server PHP error logs
2. Verify all file permissions are correct
3. Ensure all required PHP extensions are installed (openssl, curl)
4. Contact hosting provider about SMTP restrictions
5. Consider using a dedicated email service (SendGrid, Mailgun, etc.)

### Contact Information:
- Developer: Appiah Joseph
- Email: appiahjoseph020458@gmail.com
- System: UniHomes Accommodation Platform

---

**Note**: Always test email functionality in a development environment before deploying to production.
