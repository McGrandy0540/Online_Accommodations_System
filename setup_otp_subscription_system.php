<?php
/**
 * Setup script for OTP Verification and Subscription System
 * Run this script once to create the necessary database tables
 */

require_once 'config/database.php';

try {
    $db = Database::getInstance();
    
    echo "Setting up OTP Verification and Subscription System...\n\n";
    
    // Create subscription plans table
    echo "Creating subscription_plans table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS subscription_plans (
        id INT PRIMARY KEY AUTO_INCREMENT,
        plan_name VARCHAR(100) NOT NULL,
        duration_months INT NOT NULL DEFAULT 8,
        price DECIMAL(10,2) NOT NULL,
        description TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $db->exec($sql);
    echo "✓ subscription_plans table created\n";
    
    // Create user subscriptions table
    echo "Creating user_subscriptions table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS user_subscriptions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        plan_id INT NOT NULL,
        payment_reference VARCHAR(255) UNIQUE,
        amount_paid DECIMAL(10,2) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('active', 'expired', 'cancelled', 'pending') DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'paystack',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
        INDEX idx_user_status (user_id, status),
        INDEX idx_end_date (end_date)
    )";
    $db->exec($sql);
    echo "✓ user_subscriptions table created\n";
    
    // Create OTP verification table
    echo "Creating otp_verifications table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS otp_verifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        phone_number VARCHAR(20) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        purpose ENUM('registration', 'login', 'password_reset') NOT NULL,
        user_id INT NULL,
        is_verified BOOLEAN DEFAULT FALSE,
        attempts INT DEFAULT 0,
        max_attempts INT DEFAULT 3,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified_at TIMESTAMP NULL,
        INDEX idx_phone_purpose (phone_number, purpose),
        INDEX idx_expires (expires_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    echo "✓ otp_verifications table created\n";
    
    // Create subscription payment logs table
    echo "Creating subscription_payment_logs table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS subscription_payment_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        subscription_id INT,
        payment_reference VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(3) DEFAULT 'GHS',
        payment_status ENUM('pending', 'success', 'failed', 'cancelled') DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'paystack',
        paystack_response JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL,
        INDEX idx_reference (payment_reference),
        INDEX idx_user_status (user_id, payment_status)
    )";
    $db->exec($sql);
    echo "✓ subscription_payment_logs table created\n";
    
    // Insert default subscription plan
    echo "Inserting default subscription plan...\n";
    $sql = "INSERT INTO subscription_plans (plan_name, duration_months, price, description) 
            VALUES ('Student Subscription', 8, 50.00, '8-month access to accommodation platform for students')
            ON DUPLICATE KEY UPDATE plan_name = plan_name";
    $db->exec($sql);
    echo "✓ Default subscription plan inserted\n";
    
    // Add subscription-related columns to users table if they don't exist
    echo "Adding subscription columns to users table...\n";
    
    // Check if columns exist before adding them
    $columns = [
        'phone_verified' => 'BOOLEAN DEFAULT FALSE',
        'email_verified' => 'BOOLEAN DEFAULT FALSE',
        'subscription_status' => "ENUM('none', 'active', 'expired', 'pending') DEFAULT 'none'",
        'subscription_expires_at' => 'DATE NULL'
    ];
    
    foreach ($columns as $column => $definition) {
        try {
            $checkSql = "SELECT $column FROM users LIMIT 1";
            $db->query($checkSql);
            echo "✓ Column '$column' already exists\n";
        } catch (PDOException $e) {
            // Column doesn't exist, add it
            $alterSql = "ALTER TABLE users ADD COLUMN $column $definition";
            $db->exec($alterSql);
            echo "✓ Added column '$column' to users table\n";
        }
    }
    
    echo "\n🎉 OTP Verification and Subscription System setup completed successfully!\n\n";
    
    echo "Next steps:\n";
    echo "1. Update your Arkesel API key in includes/OTPService.php\n";
    echo "2. Update your Paystack keys in config/constants.php\n";
    echo "3. Test the registration process\n";
    echo "4. Test the subscription payment flow\n\n";
    
    // Display current configuration
    echo "Current Configuration:\n";
    echo "- Arkesel API Key: " . (defined('SMS_API_KEY') ? SMS_API_KEY : 'Not configured') . "\n";
    echo "- Paystack Public Key: " . (defined('PAYSTACK_PUBLIC_KEY') ? PAYSTACK_PUBLIC_KEY : 'Not configured') . "\n";
    echo "- Paystack Secret Key: " . (defined('PAYSTACK_SECRET_KEY') ? 'Configured' : 'Not configured') . "\n";
    echo "- Default subscription price: GH₵ 50.00\n";
    echo "- Default subscription duration: 8 months\n\n";
    
} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
