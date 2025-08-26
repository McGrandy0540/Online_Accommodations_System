# SMS System - Notification-Triggered Flow

## Overview
The SMS system has been updated to implement a notification-triggered flow where SMS messages are sent to students only when they access their notification portal, rather than immediately when notifications are created.

## New Flow

### 1. Notification Creation
- When a notification is created (booking update, payment confirmation, announcement, etc.), it is stored in the database with `delivered = 0`
- **No SMS is sent immediately** - this is the key change from the previous system

### 2. Student Views Notifications
- When a student accesses their notification portal (`user/notification/index.php`), the system:
  - Checks for undelivered notifications for that specific user
  - Sends SMS for each undelivered notification (respecting user SMS preferences)
  - Marks notifications as `delivered = 1` after successful SMS sending
  - Processes up to 10 undelivered notifications per visit to avoid overwhelming the SMS API

### 3. SMS Delivery
- SMS messages are sent using the existing Infobip API integration
- Each SMS includes the notification type and message content
- SMS logs are maintained for tracking and debugging

## Key Benefits

1. **User-Controlled**: Students receive SMS only when they're actively engaging with the system
2. **Reduced API Calls**: No unnecessary SMS sending for inactive users
3. **Better User Experience**: SMS serves as a mobile reminder of notifications they've already received
4. **Cost Effective**: Reduces SMS costs by only sending to engaged users
5. **Respects Preferences**: Still honors user SMS notification preferences

## Technical Implementation

### Modified Files

1. **`includes/NotificationService.php`**
   - `createNotification()`: No longer sends SMS immediately
   - `sendNotificationSMS()`: Made public for use by notification portal
   - `processPendingSMSForUser()`: New method to process SMS for specific user

2. **`user/notification/index.php`**
   - Added call to `processPendingSMSForUser()` when page loads
   - Processes undelivered notifications and sends SMS

3. **`includes/SMSService.php`**
   - `processPendingSMS()`: Marked as deprecated
   - Added documentation about the new flow

### Database Schema
The existing `notifications` table uses the `delivered` column:
- `delivered = 0`: SMS not yet sent
- `delivered = 1`: SMS successfully sent

### SMS Preferences
The system still respects user SMS preferences:
- Global SMS notifications setting
- Specific notification type preferences (booking updates, payments, etc.)
- System alerts are always sent regardless of preferences

## Usage Examples

### Creating a Notification (No immediate SMS)
```php
$notificationService = new NotificationService();
$notificationId = $notificationService->createNotification(
    $userId, 
    "Your booking has been confirmed!", 
    'booking_update'
);
// SMS will be sent when student visits notification portal
```

### Processing SMS for User (Automatic)
```php
// This happens automatically when student visits notification portal
$notificationService = new NotificationService();
$results = $notificationService->processPendingSMSForUser($userId);
```

## Monitoring and Debugging

### SMS Logs
- All SMS attempts are logged in the `sms_logs` table
- Includes status (sent, failed, error), phone number, message, and notification ID
- Error messages are captured for failed attempts

### Error Logging
- SMS processing results are logged for debugging
- Check server error logs for SMS-related issues

### Testing
1. Create a test notification for a user
2. Verify notification appears in database with `delivered = 0`
3. Have the user visit their notification portal
4. Check that SMS is sent and `delivered` is updated to 1
5. Verify SMS log entry is created

## Migration Notes

### From Previous System
- Existing notifications with `delivered = 0` will be processed when users visit their portal
- No data migration required
- Old cron job SMS processing can be disabled

### Backward Compatibility
- The old `processPendingSMS()` method is kept but marked as deprecated
- Existing code calling this method will receive a deprecation notice

## Configuration

### SMS Settings
- SMS API credentials remain in `SMSService.php`
- User SMS preferences are stored in the `users` table
- Notification types that trigger SMS are defined in the notification processing logic

### Rate Limiting
- 0.1 second delay between SMS sends to avoid API rate limits
- Maximum 10 notifications processed per portal visit
- Additional visits will process remaining notifications

## Future Enhancements

1. **Real-time Notifications**: Could add WebSocket support for instant browser notifications
2. **SMS Scheduling**: Option to delay SMS sending by a few minutes
3. **Batch Processing**: Group multiple notifications into single SMS
4. **Delivery Reports**: Enhanced tracking of SMS delivery status
5. **User Dashboard**: Show SMS sending history to users

## Troubleshooting

### Common Issues
1. **SMS not sending**: Check user phone number format and SMS preferences
2. **Multiple SMS**: Ensure notifications are marked as delivered after sending
3. **API errors**: Check SMS service logs and API credentials
4. **Rate limiting**: Verify delays between SMS sends

### Debug Steps
1. Check notification creation in database
2. Verify user has valid phone number and SMS enabled
3. Check SMS logs for sending attempts
4. Review server error logs for detailed error messages
