<?php
/**
 * Enhanced Email Testing & Debugging Script
 * This will show you exactly what's happening with your email system
 */

// Force error reporting and display
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start output buffering to catch any early errors
ob_start();

// Track execution time
$start_time = microtime(true);

?>
<!DOCTYPE html>
<html>
<head>
    <title>UniHomes Email System Diagnostics</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .test-section { 
            margin: 20px 0; 
            padding: 15px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            background: #fafafa; 
        }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .info { color: #17a2b8; font-weight: bold; }
        .code { 
            background: #f8f9fa; 
            padding: 10px; 
            border-left: 4px solid #007bff; 
            margin: 10px 0; 
            font-family: monospace; 
            white-space: pre-wrap; 
        }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, textarea { 
            width: 100%; 
            max-width: 400px; 
            padding: 8px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
        }
        button { 
            background: #007bff; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px; 
        }
        button:hover { background: #0056b3; }
        .progress { 
            background: #e9ecef; 
            border-radius: 4px; 
            padding: 3px; 
            margin: 10px 0; 
        }
        .progress-bar { 
            background: #007bff; 
            height: 20px; 
            border-radius: 2px; 
            transition: width 0.3s; 
        }
        .debug-output { 
            background: #000; 
            color: #0f0; 
            padding: 15px; 
            border-radius: 5px; 
            font-family: monospace; 
            font-size: 12px; 
            max-height: 300px; 
            overflow-y: auto; 
            margin: 10px 0; 
        }
    </style>
</head>
<body>

<div class="container">
    <h1>🔧 UniHomes Email System Diagnostics</h1>
    <p><strong>Current Time:</strong> <?= date('Y-m-d H:i:s') ?></p>
    <p><strong>PHP Version:</strong> <?= PHP_VERSION ?></p>
    <p><strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></p>
    
    <hr>

<?php
// Initialize test results
$test_results = [];
$overall_status = true;

// Helper function to log test results
function logTest($test_name, $success, $message, $details = null) {
    global $test_results;
    $test_results[] = [
        'name' => $test_name,
        'success' => $success,
        'message' => $message,
        'details' => $details,
        'time' => date('H:i:s')
    ];
    
    $icon = $success ? '✅' : '❌';
    $class = $success ? 'success' : 'error';
    
    echo "<div class='test-section'>";
    echo "<h3>{$icon} {$test_name}</h3>";
    echo "<p class='{$class}'>{$message}</p>";
    
    if ($details) {
        echo "<div class='code'>{$details}</div>";
    }
    echo "</div>";
    
    // Flush output immediately
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

// Test 1: System Information
echo "<h2>📊 System Information</h2>";
$extensions = get_loaded_extensions();
$required_extensions = ['openssl', 'curl', 'mbstring', 'pdo', 'pdo_mysql'];
$missing_extensions = array_diff($required_extensions, $extensions);

if (empty($missing_extensions)) {
    logTest("PHP Extensions", true, "All required extensions are loaded", 
        "Required: " . implode(', ', $required_extensions));
} else {
    logTest("PHP Extensions", false, "Missing extensions: " . implode(', ', $missing_extensions),
        "You need to enable these extensions in php.ini");
    $overall_status = false;
}

// Test 2: File Loading
echo "<h2>📁 File Loading Test</h2>";

$required_files = [
    'config/database.php' => 'Database configuration',
    'config/email.php' => 'Email configuration', 
    'includes/EmailService.php' => 'Email service class',
    'vendor/autoload.php' => 'Composer autoloader'
];

foreach ($required_files as $file => $description) {
    if (file_exists($file)) {
        try {
            require_once $file;
            logTest("Load {$description}", true, "File loaded successfully: {$file}");
        } catch (Exception $e) {
            logTest("Load {$description}", false, "Error loading {$file}: " . $e->getMessage());
            $overall_status = false;
        }
    } else {
        logTest("Load {$description}", false, "File not found: {$file}");
        $overall_status = false;
    }
}

// Test 3: Class Loading
echo "<h2>🏗️ Class Loading Test</h2>";

$required_classes = [
    'Database' => 'Database connection class',
    'EmailHelper' => 'Email helper functions',
    'EmailService' => 'Email service class',
    'PHPMailer\PHPMailer\PHPMailer' => 'PHPMailer library'
];

foreach ($required_classes as $class => $description) {
    if (class_exists($class)) {
        logTest("Class {$description}", true, "Class '{$class}' is available");
    } else {
        logTest("Class {$description}", false, "Class '{$class}' not found");
        $overall_status = false;
    }
}

// Test 4: Configuration Test
echo "<h2>⚙️ Configuration Test</h2>";

$email_constants = [
    'SMTP_HOST' => 'SMTP server hostname',
    'SMTP_PORT' => 'SMTP server port',
    'SMTP_USERNAME' => 'SMTP username',
    'SMTP_PASSWORD' => 'SMTP password',
    'DEFAULT_ADMIN_EMAIL' => 'Default admin email'
];

foreach ($email_constants as $constant => $description) {
    if (defined($constant)) {
        $value = constant($constant);
        $display_value = ($constant === 'SMTP_PASSWORD') ? str_repeat('*', strlen($value)) : $value;
        logTest("Config {$description}", true, "✓ {$constant}: {$display_value}");
    } else {
        logTest("Config {$description}", false, "✗ {$constant}: Not defined");
        $overall_status = false;
    }
}

// Test 5: Database Connection
echo "<h2>🗄️ Database Connection Test</h2>";

try {
    if (class_exists('Database')) {
        $pdo = Database::getInstance();
        logTest("Database Connection", true, "Successfully connected to database");
        
        // Test admin email retrieval
        if (class_exists('EmailHelper')) {
            $admin_email = EmailHelper::getAdminEmail();
            logTest("Admin Email Retrieval", true, "Admin email: {$admin_email}");
        } else {
            logTest("Admin Email Retrieval", false, "EmailHelper class not available");
        }
    } else {
        logTest("Database Connection", false, "Database class not available");
        $overall_status = false;
    }
} catch (Exception $e) {
    logTest("Database Connection", false, "Database connection failed: " . $e->getMessage());
    $overall_status = false;
}

// Test 6: PHPMailer and SMTP Test
echo "<h2>📧 Email System Test</h2>";

if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        logTest("PHPMailer Initialization", true, "PHPMailer object created successfully");
        
        // Test SMTP configuration
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->Timeout = 10;
            
            logTest("SMTP Configuration", true, "SMTP settings configured");
            
            // Test SMTP connection
            if ($mail->smtpConnect()) {
                logTest("SMTP Connection", true, "Successfully connected to SMTP server");
                $mail->smtpClose();
            } else {
                logTest("SMTP Connection", false, "Failed to connect to SMTP server: " . $mail->ErrorInfo);
                $overall_status = false;
            }
            
        } catch (Exception $e) {
            logTest("SMTP Connection", false, "SMTP connection error: " . $e->getMessage());
            $overall_status = false;
        }
        
    } catch (Exception $e) {
        logTest("PHPMailer Test", false, "PHPMailer error: " . $e->getMessage());
        $overall_status = false;
    }
} else {
    logTest("PHPMailer Test", false, "PHPMailer class not available");
    $overall_status = false;
}

// Test 7: EmailService Test
echo "<h2>🔧 EmailService Test</h2>";

if (class_exists('EmailService')) {
    try {
        $emailService = new EmailService(true); // Enable debug mode
        logTest("EmailService Creation", true, "EmailService object created successfully");
        
        // Test email configuration
        $configTest = $emailService->testEmailConfiguration();
        if ($configTest['success']) {
            logTest("EmailService Configuration", true, $configTest['message']);
        } else {
            logTest("EmailService Configuration", false, $configTest['message']);
            $overall_status = false;
        }
        
    } catch (Exception $e) {
        logTest("EmailService Test", false, "EmailService error: " . $e->getMessage());
        $overall_status = false;
    }
} else {
    logTest("EmailService Test", false, "EmailService class not available");
    $overall_status = false;
}

// Overall Status
echo "<h2>📋 Overall System Status</h2>";
if ($overall_status) {
    echo "<div class='test-section'>";
    echo "<h3 class='success'>✅ System Status: READY</h3>";
    echo "<p class='success'>All tests passed! Your email system should be working.</p>";
    echo "</div>";
} else {
    echo "<div class='test-section'>";
    echo "<h3 class='error'>❌ System Status: ISSUES DETECTED</h3>";
    echo "<p class='error'>Some tests failed. Please fix the issues above before testing email sending.</p>";
    echo "</div>";
}

// Email sending test (only if basic tests pass and form is submitted)
if ($overall_status && isset($_POST['send_test_email'])) {
    echo "<h2>📤 Email Sending Test</h2>";
    
    $test_name = trim($_POST['test_name'] ?? '');
    $test_email = trim($_POST['test_email'] ?? '');
    $test_subject = trim($_POST['test_subject'] ?? '');
    $test_message = trim($_POST['test_message'] ?? '');
    
    // Validate inputs
    $validation_errors = [];
    if (empty($test_name)) $validation_errors[] = "Name is required";
    if (empty($test_email)) $validation_errors[] = "Email is required";
    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email format";
    if (empty($test_message)) $validation_errors[] = "Message is required";
    
    if (!empty($validation_errors)) {
        logTest("Input Validation", false, "Validation errors: " . implode(', ', $validation_errors));
    } else {
        try {
            $emailService = new EmailService(true); // Enable debug mode
            
            echo "<div class='debug-output' id='debug-output'>";
            echo "Starting email sending test...\n";
            echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
            echo "From: {$test_name} <{$test_email}>\n";
            echo "Subject: {$test_subject}\n";
            echo "Message length: " . strlen($test_message) . " characters\n";
            echo "---\n";
            
            // Capture debug output
            ob_start();
            
            // Test admin email
            echo "Sending admin notification...\n";
            $adminResult = $emailService->sendContactFormEmail($test_name, $test_email, $test_subject, $test_message);
            
            $debug_output = ob_get_contents();
            ob_end_clean();
            
            echo $debug_output;
            
            if ($adminResult['success']) {
                echo "✅ Admin email sent successfully!\n";
                logTest("Admin Email", true, "Admin notification sent successfully");
            } else {
                echo "❌ Admin email failed: " . $adminResult['message'] . "\n";
                logTest("Admin Email", false, $adminResult['message']);
            }
            
            // Test confirmation email
            echo "\nSending user confirmation...\n";
            ob_start();
            
            $confirmResult = $emailService->sendConfirmationEmail($test_name, $test_email, $test_message);
            
            $debug_output = ob_get_contents();
            ob_end_clean();
            
            echo $debug_output;
            
            if ($confirmResult['success']) {
                echo "✅ Confirmation email sent successfully!\n";
                logTest("Confirmation Email", true, "User confirmation sent successfully");
            } else {
                echo "❌ Confirmation email failed: " . $confirmResult['message'] . "\n";
                logTest("Confirmation Email", false, $confirmResult['message']);
            }
            
            echo "\nEmail sending test completed.\n";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "</div>";
            logTest("Email Sending", false, "Email sending error: " . $e->getMessage());
        }
    }
}

// Calculate execution time
$execution_time = round((microtime(true) - $start_time) * 1000, 2);
echo "<p><strong>Total execution time:</strong> {$execution_time}ms</p>";

?>

<hr>

<?php if ($overall_status): ?>
<h2>🧪 Send Test Email</h2>
<div class="test-section">
    <p class="info">✨ System checks passed! You can now test email sending.</p>
    
    <form method="POST" action="">
        <div class="form-group">
            <label for="test_name">Your Name:</label>
            <input type="text" id="test_name" name="test_name" value="<?= htmlspecialchars($_POST['test_name'] ?? 'Test User') ?>" required>
        </div>
        
        <div class="form-group">
            <label for="test_email">Your Email (for confirmation):</label>
            <input type="email" id="test_email" name="test_email" value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>" placeholder="your-email@example.com" required>
        </div>
        
        <div class="form-group">
            <label for="test_subject">Subject:</label>
            <input type="text" id="test_subject" name="test_subject" value="<?= htmlspecialchars($_POST['test_subject'] ?? 'Email System Test') ?>">
        </div>
        
        <div class="form-group">
            <label for="test_message">Message:</label>
            <textarea id="test_message" name="test_message" rows="4" required><?= htmlspecialchars($_POST['test_message'] ?? 'This is a test email from the UniHomes contact form system. If you receive this, the email system is working correctly!') ?></textarea>
        </div>
        
        <button type="submit" name="send_test_email">🚀 Send Test Email</button>
    </form>
</div>
<?php else: ?>
<h2>⚠️ Cannot Test Email Sending</h2>
<div class="test-section">
    <p class="error">Please fix the issues identified above before testing email sending.</p>
</div>
<?php endif; ?>

<hr>

<h2>🔍 Troubleshooting Guide</h2>
<div class="test-section">
    <h4>Common Issues & Solutions:</h4>
    <ul>
        <li><strong>PHPMailer not found:</strong> Run <code>composer install</code> in your project directory</li>
        <li><strong>SMTP Authentication failed:</strong> Check your Gmail app password (not regular password)</li>
        <li><strong>Database connection failed:</strong> Verify your database credentials in config/database.php</li>
        <li><strong>Missing extensions:</strong> Enable required PHP extensions in php.ini</li>
        <li><strong>SMTP connection timeout:</strong> Check firewall settings for port 587</li>
        <li><strong>SSL/TLS errors:</strong> Try changing SMTP_ENCRYPTION to 'ssl' and port to 465</li>
    </ul>
    
    <h4>Gmail Setup Requirements:</h4>
    <ol>
        <li>Enable 2-Factor Authentication on your Gmail account</li>
        <li>Generate an App Password (16 characters, no spaces)</li>
        <li>Use the app password in SMTP_PASSWORD (not your regular Gmail password)</li>
        <li>Make sure "Less secure app access" is not needed (app passwords bypass this)</li>
    </ol>
</div>

<div class="test-section">
    <p class="warning"><strong>⚠️ Security Note:</strong> Delete this test file after debugging for security reasons.</p>
</div>

</div>

<script>
// Auto-scroll debug output
document.addEventListener('DOMContentLoaded', function() {
    const debugOutput = document.getElementById('debug-output');
    if (debugOutput) {
        debugOutput.scrollTop = debugOutput.scrollHeight;
    }
});
</script>

</body>
</html>
