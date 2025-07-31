<?php
// =====================
// Environment Settings
// =====================
define('ENVIRONMENT', 'development'); // 'production', 'staging', 'development'
define('SITE_URL', 'https://sv4.byethost4.org'); // MUST match actual domain for SSL certificate validation
define('ADMIN_EMAIL', 'admin@yourdomain.com');
define('SUPPORT_EMAIL', 'support@yourdomain.com');

// =====================
// Database Configuration
// =====================
define('DB_HOST', 'localhost');
define('DB_NAME', 'online_accommodations_system');
define('DB_USER', 'db_user');
define('DB_PASS', 'secure_password');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', '3306');

// =====================
// File System Paths
// =====================
define('BASE_PATH', dirname(__DIR__));
define('LOG_PATH', BASE_PATH . '/logs/');
define('CACHE_PATH', BASE_PATH . '/cache/');
define('UPLOAD_PATH', BASE_PATH . '/assets/uploads/');
define('TEMPLATE_PATH', BASE_PATH . '/views/');

// =====================
// Security Constants
// =====================
define('CSRF_TOKEN_NAME', 'csrf_token');
define('AUTH_TOKEN_NAME', 'auth_token');
define('PASSWORD_COST', 12);
define('SESSION_TIMEOUT', 1800);
define('REMEMBER_ME_EXPIRE', 2592000); // 30 days
define('API_KEY_LENGTH', 32);

// =====================
// Payment Configuration
// =====================
define('PAYSTACK_SECRET_KEY', 'sk_test_9c3c7da0284defbf21404dd3faa9cc15ed571d8e');
define('PAYSTACK_PUBLIC_KEY', 'pk_test_db73c7228ff880b4a3d49593023b91a6a5b923c6');
define('PAYSTACK_CURRENCY', 'GHS');
define('PAYSTACK_MIN_AMOUNT', 100); // Minimum amount in smallest currency unit

// =====================
// Email Configuration
// =====================
define('SMTP_HOST', 'smtp.yourdomain.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'email_password');
define('SMTP_SECURE', 'tls');
define('MAIL_FROM', 'noreply@yourdomain.com');
define('MAIL_FROM_NAME', 'UniHomes System');

// =====================
// System Limits
// =====================
define('MAX_FILE_UPLOAD_SIZE', 5242880); // 5MB
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('API_RATE_LIMIT', 100); // Requests per minute
define('PASSWORD_RESET_EXPIRE', 3600); // 1 hour

// =====================
// API Keys
// =====================
define('GOOGLE_MAPS_API_KEY', 'your_google_maps_key');
define('RECAPTCHA_SITE_KEY', 'your_recaptcha_key');
define('RECAPTCHA_SECRET_KEY', 'your_recaptcha_secret');
define('SMS_API_KEY', 'your_sms_api_key');

// =====================
// Feature Flags
// =====================
define('ENABLE_MAINTENANCE', false);
define('ENABLE_SMS_NOTIFICATIONS', false);
define('ENABLE_EMAIL_VERIFICATION', true);
define('ENABLE_2FA', false);
define('DEBUG_MODE', ENVIRONMENT === 'development');