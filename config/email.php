<?php
/**
 * Email Configuration File
 * Contains email settings and helper functions
 */

// Email Configuration Constants
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465); // Use 465 for SSL
define('SMTP_USERNAME', 'godwinaboade5432109876@gmail.com');
define('SMTP_PASSWORD', 'nblu ewzo rpqe jfdu'); // App password
define('SMTP_ENCRYPTION', 'ssl'); // SSL encryption
define('SMTP_DEBUG', 0); // Set to 2 for detailed debugging, 0 for production

// Fallback SMTP settings (TLS on port 587)
define('SMTP_HOST_FALLBACK', 'smtp.gmail.com');
define('SMTP_PORT_FALLBACK', 587);
define('SMTP_ENCRYPTION_FALLBACK', 'tls');

// Default email addresses
define('DEFAULT_FROM_EMAIL', 'noreply@landlordsandtenant.com');
define('DEFAULT_FROM_NAME', 'Landlords&Tenant System');
define('DEFAULT_ADMIN_EMAIL', 'godwinaboade5432109876@gmail.com');

// Email sending limits
define('EMAIL_RATE_LIMIT', 100); // Max emails per hour per IP
define('EMAIL_TIMEOUT', 30); // SMTP timeout in seconds

?>
