<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Track execution time
$start_time = microtime(true);

// Database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/email.php';

// Load required files with proper error handling
$required_files = [
    'includes/Services.php' => 'Email Service',
    'EmailHelper.php' => 'Email Helper',
    'vendor/autoload.php' => 'Composer Autoloader'
];

foreach ($required_files as $file => $description) {
    if (!file_exists($file)) {
        die("<div class='alert alert-danger'>Error: Missing required file ($description) - $file</div>");
    }
    require_once $file;
}

// Initialize variables
$contact_errors = [];
$contact_data = [];
$message_sent = false;
$email_debug_info = [];
$system_tests = [];
$overall_status = true;

// Process contact form if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    // Sanitize inputs
    $name = trim(htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $subject = trim(htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8'));
    $message = trim(htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8'));

    // Store form data for repopulation
    $contact_data = [
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'message' => $message
    ];

    // Validate inputs
    $validationPass = true;
    
    if (empty($name)) {
        $contact_errors[] = "Name is required";
        $validationPass = false;
    }

    if (empty($email)) {
        $contact_errors[] = "Email is required";
        $validationPass = false;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contact_errors[] = "Invalid email format";
        $validationPass = false;
    }

    if (empty($message)) {
        $contact_errors[] = "Message is required";
        $validationPass = false;
    }

    // Check rate limiting
    if (!EmailHelper::checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        $contact_errors[] = "Too many messages sent from this IP address. Please try again later.";
        $validationPass = false;
    }

    // If validation passes, process the form
    if ($validationPass) {
        try {
            // First save to database
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("INSERT INTO contact_messages 
                                  (name, email, subject, message, ip_address, user_agent) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $email,
                $subject,
                $message,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            // Initialize email service with debug mode (set to true for troubleshooting)
            $emailService = new EmailService(true); // Debug mode enabled
            
            // Debug output
            ob_start();
            
            // Send email to admin
            $adminEmailResult = $emailService->sendContactFormEmail($name, $email, $subject, $message);
            
            // Send confirmation email to user
            $confirmationEmailResult = $emailService->sendConfirmationEmail($name, $email, $message);
            
            $debug_output = ob_get_clean();
            
            // Check results and set appropriate messages
            if ($adminEmailResult['success'] && $confirmationEmailResult['success']) {
                $message_sent = true;
                $contact_data = [];
                error_log("Contact form: Both emails sent successfully for {$email}");
            } elseif ($adminEmailResult['success']) {
                $message_sent = true;
                $contact_data = [];
                $contact_errors[] = "Your message was received! We couldn't send a confirmation email, but we'll get back to you soon.";
                error_log("Contact form: Admin email sent but confirmation failed for {$email}: " . $confirmationEmailResult['message']);
            } else {
                $contact_errors[] = "Your message was saved but we're experiencing email delivery issues. We'll still get back to you soon.";
                error_log("Contact form: Admin email failed for {$email}: " . $adminEmailResult['message']);
                
                // Store debug info for troubleshooting
                $email_debug_info = [
                    'debug_output' => $debug_output,
                    'error' => $adminEmailResult['message']
                ];
            }

        } catch (PDOException $e) {
            error_log("Database error in contact form: " . $e->getMessage());
            $contact_errors[] = "An error occurred while saving your message. Please try again later.";
        } catch (Exception $e) {
            error_log("General error in contact form: " . $e->getMessage());
            $contact_errors[] = "An unexpected error occurred. Please try again later or contact us directly.";
        }
    }
}

// Run system diagnostics
function runSystemTest($test_name, $check_function, $success_msg, $fail_msg) {
    global $system_tests, $overall_status;
    
    try {
        $result = $check_function();
        $system_tests[] = [
            'name' => $test_name,
            'status' => $result,
            'message' => $result ? $success_msg : $fail_msg,
            'timestamp' => date('H:i:s')
        ];
        
        if (!$result) {
            $overall_status = false;
        }
    } catch (Exception $e) {
        $system_tests[] = [
            'name' => $test_name,
            'status' => false,
            'message' => "Test failed with exception: " . $e->getMessage(),
            'timestamp' => date('H:i:s')
        ];
        $overall_status = false;
    }
}

// Test 1: PHP Extensions
runSystemTest(
    'PHP Extensions',
    function() {
        $required = ['openssl', 'curl', 'mbstring', 'pdo', 'pdo_mysql'];
        $missing = array_diff($required, get_loaded_extensions());
        return empty($missing);
    },
    'All required extensions are loaded',
    'Missing some required PHP extensions'
);

// Test 2: Database Connection
runSystemTest(
    'Database Connection',
    function() {
        $pdo = Database::getInstance();
        return $pdo !== null;
    },
    'Successfully connected to database',
    'Failed to connect to database'
);

// Test 3: Email Configuration
runSystemTest(
    'Email Configuration',
    function() {
        return defined('SMTP_HOST') && defined('SMTP_PORT') && 
               defined('SMTP_USERNAME') && defined('SMTP_PASSWORD');
    },
    'Email configuration is valid',
    'Missing required email configuration'
);

// Test 4: PHPMailer Availability
runSystemTest(
    'PHPMailer Library',
    function() {
        return class_exists('PHPMailer\PHPMailer\PHPMailer');
    },
    'PHPMailer is available',
    'PHPMailer not found - run composer install'
);

// Calculate execution time
$execution_time = round((microtime(true) - $start_time) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Accommodation System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            line-height: 1.6;
            color: var(--dark-color);
            background-color: var(--light-color);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header Styles */
        header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .logo span {
            color: var(--primary-color);
        }

        .logo img {
            margin-right: 10px;
        }

        nav ul {
            display: flex;
            list-style: none;
        }

        nav ul li {
            margin-left: 1.5rem;
        }

        nav ul li a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        nav ul li a:hover {
            color: var(--primary-color);
        }

        .auth-buttons a {
            margin-left: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .login-btn {
            color: white;
            border: 1px solid var(--primary-color);
        }

        .login-btn:hover {
            background-color: var(--primary-color);
        }

        .register-btn {
            background-color: var(--primary-color);
            color: white;
        }

        .register-btn:hover {
            background-color: #2980b9;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(44, 62, 80, 0.8), rgba(44, 62, 80, 0.8)), url('assets/images/ktu.jpg');
            background-size: cover;
            background-position: center;
            height: 100vh;
            display: flex;
            align-items: center;
            text-align: center;
            color: white;
            margin-top: 60px;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }

        .search-bar {
            display: flex;
            max-width: 600px;
            margin: 0 auto;
        }

        .search-bar input {
            flex: 1;
            padding: 1rem;
            border: none;
            border-radius: 5px 0 0 5px;
            font-size: 1rem;
        }

        .search-bar button {
            padding: 0 1.5rem;
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-bar button:hover {
            background-color: #c0392b;
        }

        /* Features Section */
        .features {
            padding: 5rem 0;
            background-color: white;
        }

        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title h2 {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .section-title p {
            color: #666;
            max-width: 700px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background-color: var(--light-color);
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .feature-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
        }

        /* About Section */
        .about {
            padding: 5rem 0;
            background-color: var(--light-color);
        }

        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }

        .about-image img {
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .about-text h2 {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        .about-text p {
            margin-bottom: 1.5rem;
            color: #555;
        }

        .about-text ul {
            margin-bottom: 1.5rem;
            list-style: none;
        }

        .about-text ul li {
            margin-bottom: 0.5rem;
            position: relative;
            padding-left: 1.5rem;
        }

        .about-text ul li::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 0;
            color: var(--success-color);
        }

        /* Contact Section */
        .contact {
            padding: 5rem 0;
            background-color: white;
        }

        .contact-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
        }

        .contact-info h3 {
            font-size: 1.8rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        .contact-info p {
            margin-bottom: 2rem;
            color: #555;
        }

        .contact-details {
            margin-bottom: 2rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .contact-icon {
            width: 40px;
            height: 40px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }

        .contact-form input,
        .contact-form textarea {
            width: 100%;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .contact-form input:focus,
        .contact-form textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .contact-form textarea {
            height: 150px;
            resize: vertical;
        }

        .contact-form button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.3s;
        }

        .contact-form button:hover {
            background-color: #2980b9;
        }

        /* Footer */
        footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 3rem 0 1rem;
        }

        .footer-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-col h3 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .footer-col h3::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 50px;
            height: 2px;
            background-color: var(--primary-color);
        }

        .footer-col p {
            margin-bottom: 1rem;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
            list-style: none;
        }

        .footer-links a {
            color: #ddd;
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-links a:hover {
            color: var(--primary-color);
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            color: white;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Alert Styles */
        .alert {
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Debug Panel Styles */
        .debug-panel {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
        }
        
        .debug-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .debug-content {
            font-family: monospace;
            white-space: pre-wrap;
            background: #000;
            color: #0f0;
            padding: 10px;
            border-radius: 5px;
            max-height: 300px;
            overflow-y: auto;
        }

        /* System Test Styles */
        .system-test-section {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fafafa;
        }
        
        .test-success {
            color: var(--success-color);
            font-weight: bold;
        }
        
        .test-failure {
            color: var(--accent-color);
            font-weight: bold;
        }
        
        .test-icon {
            margin-right: 5px;
        }

        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .about-content,
            .contact-container {
                grid-template-columns: 1fr;
            }

            .about-image {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            nav ul {
                display: none;
                position: absolute;
                top: 60px;
                left: 0;
                width: 100%;
                background-color: var(--secondary-color);
                flex-direction: column;
                padding: 1rem 0;
                box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
            }

            nav ul.show {
                display: flex;
            }

            nav ul li {
                margin: 0;
                text-align: center;
                padding: 0.5rem 0;
            }

            .mobile-menu-btn {
                display: block;
            }

            .auth-buttons {
                display: none;
            }

            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1rem;
            }
        }

        @media (max-width: 576px) {
            .hero h1 {
                font-size: 2rem;
            }

            .search-bar {
                flex-direction: column;
            }

            .search-bar input {
                border-radius: 5px;
                margin-bottom: 0.5rem;
            }

            .search-bar button {
                border-radius: 5px;
                padding: 1rem;
            }

            .section-title h2 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="index.php" class="logo">
                <img src="assets/images/landlords-logo.png" alt="Logo" width="100" height="80" class="me-2">
                Landlords<span>&Tenants</span>
            </a>
            
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="fas fa-bars"></i>
            </button>
            
            <nav>
                <ul id="mainMenu">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#about">About</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </nav>
            
            <div class="auth-buttons">
                <a href="auth/login.php" class="login-btn">Login</a>
                <a href="auth/register.php" class="register-btn">Register</a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container hero-content">
            <h1>Find Your Perfect Accommodation</h1>
            <p>Discover comfortable, affordable housing options near your campus with our trusted platform</p>
            
            <form class="search-bar" action="search.php" method="GET">
                <input type="text" name="search" placeholder="Search by location, price, or amenities...">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="container">
            <div class="section-title">
                <h2>Why Choose Landlords&Tenants</h2>
                <p>Our platform offers the best solutions for tenants and property owners alike</p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <h3>Interactive Maps</h3>
                    <p>Find properties near your campus with our easy-to-use map interface</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3>Secure Payments</h3>
                    <p>Safe and reliable payment processing with multiple options</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <h3>Live Chat</h3>
                    <p>Communicate directly with property owners in real-time</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>Verified Reviews</h3>
                    <p>Read authentic reviews from fellow Tenants</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <h3>Roommate Matching</h3>
                    <p>Our AI helps you find compatible roommates</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3>Instant Notifications</h3>
                    <p>Get alerts for new listings and booking updates</p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about" id="about">
        <div class="container">
            <div class="about-content">
                <div class="about-text">
                    <h2>About Landlords&Tenants</h2>
                    <p>Landlords&Tenant is the leading platform connecting tenants with quality accommodation near universities across the country. Founded in 2023, we've helped thousands of tenants find their perfect home away from home.</p>
                    
                    <p>Our mission is to simplify the accommodation search process while ensuring safety, affordability, and convenience for both tenants and property owners.</p>
                    
                    <ul>
                        <li>Trusted by over 50 universities nationwide</li>
                        <li>5000+ verified properties listed</li>
                        <li>24/7 customer support</li>
                        <li>Secure payment system</li>
                    </ul>
                </div>
                
                <div class="about-image">
                    <img src="assets/images/ELITE-HOSTEL.jpeg" alt="Tenants enjoying their accommodation">
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="contact" id="contact">
        <div class="container">
            <div class="section-title">
                <h2>Contact Us</h2>
                <p>Have questions or need assistance? Reach out to our team</p>
            </div>
            
            <div class="contact-container">
                <div class="contact-info">
                    <h3>Get in Touch</h3>
                    <p>We're here to help you with any questions about our platform or your accommodation needs.</p>
                    
                    <div class="contact-details">
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div>
                                <h4>Location</h4>
                                <p>Koforidua Technical University campus</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div>
                                <h4>Email</h4>
                                <p>appiahjoseph020458@gmail.com</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div>
                                <h4>Phone</h4>
                                <p>+233 240687599</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                
                <div class="contact-form">
                    <?php if ($message_sent): ?>
                        <div class="alert alert-success">
                            Thank you for your message! We'll get back to you soon.
                        </div>
                    <?php elseif (!empty($contact_errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($contact_errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form action="index.php#contact" method="POST">
                        <input type="text" name="name" placeholder="Your Name" required 
                               value="<?= htmlspecialchars($contact_data['name'] ?? '') ?>">
                        <input type="email" name="email" placeholder="Your Email" required
                               value="<?= htmlspecialchars($contact_data['email'] ?? '') ?>">
                        <input type="text" name="subject" placeholder="Subject"
                               value="<?= htmlspecialchars($contact_data['subject'] ?? '') ?>">
                        <textarea name="message" placeholder="Your Message" required><?= 
                            htmlspecialchars($contact_data['message'] ?? '') 
                        ?></textarea>
                        <button type="submit" name="submit_contact">Send Message</button>
                    </form>
                    
                    <!-- Debug Panel (visible only if debug info exists) -->
                    <?php if (!empty($email_debug_info)): ?>
                        <div class="debug-panel mt-4">
                            <div class="debug-title">Email System Debug Information:</div>
                            <div class="debug-content">
                                <?= htmlspecialchars($email_debug_info['debug_output'] ?? 'No debug output') ?>
                                
                                <?php if (isset($email_debug_info['error'])): ?>
                                    \n\nERROR: <?= htmlspecialchars($email_debug_info['error']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- System Tests Section (visible in development) -->
                    <?php if (isset($_GET['debug'])): ?>
                        <div class="system-test-section mt-4">
                            <h3>System Diagnostics</h3>
                            <p><strong>Execution Time:</strong> <?= $execution_time ?>ms</p>
                            
                            <?php foreach ($system_tests as $test): ?>
                                <div class="<?= $test['status'] ? 'test-success' : 'test-failure' ?>">
                                    <i class="fas fa-<?= $test['status'] ? 'check-circle' : 'times-circle' ?> test-icon"></i>
                                    <?= htmlspecialchars($test['name']) ?>: 
                                    <?= htmlspecialchars($test['message']) ?>
                                    <small>(<?= $test['timestamp'] ?>)</small>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="mt-3">
                                <strong>Overall System Status:</strong>
                                <span class="<?= $overall_status ? 'test-success' : 'test-failure' ?>">
                                    <?= $overall_status ? 'READY' : 'ISSUES DETECTED' ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-container">
                <div class="footer-col">
                    <h3>Landlords&Tenant</h3>
                    <p>Your trusted partner for  accommodation solutions.</p>
                    <p>Helping tenants find their perfect home since 2023.</p>
                </div>
                
                <div class="footer-col">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="#contact">Contact</a></li>
                        <li><a href="auth/login.php">Login</a></li>
                        <li><a href="auth/register.php">Register</a></li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h3>Services</h3>
                    <ul class="footer-links">
                        <li><a href="student/search.php">Find Accommodation</a></li>
                        <li><a href="owner/properties/add.php">List Your Property</a></li>
                        <li><a href="#">Payment Options</a></li>
                        <li><a href="#">Safety Guidelines</a></li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h3>Newsletter</h3>
                    <p>Subscribe to get updates on new properties and offers.</p>
                    <form class="newsletter-form">
                        <input type="email" placeholder="Your Email" required>
                        <button type="submit">Subscribe</button>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2025 Landlords&Tenants. All Rights Reserved. | <a href="#">Privacy Policy</a> | <a href="#">Terms of Service</a></p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('mainMenu').classList.toggle('show');
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 80,
                        behavior: 'smooth'
                    });
                    
                    // Close mobile menu if open
                    document.getElementById('mainMenu').classList.remove('show');
                }
            });
        });

        // Sticky header on scroll
        window.addEventListener('scroll', function() {
            const header = document.querySelector('header');
            if (window.scrollY > 100) {
                header.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.2)';
            } else {
                header.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.1)';
            }
        });

        // Clear form errors when user starts typing
        document.querySelectorAll('.contact-form input, .contact-form textarea').forEach(input => {
            input.addEventListener('input', function() {
                const alert = this.closest('.contact-form').querySelector('.alert-danger');
                if (alert) {
                    alert.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>