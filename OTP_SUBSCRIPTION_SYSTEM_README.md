# OTP Verification and Subscription System

This document explains the implementation of the OTP verification and subscription system for the Online Accommodation System.

## Overview

The system implements a multi-step registration process with:
1. **OTP Verification** - Phone number verification using Arkesel SMS API
2. **Subscription Management** - Student subscription system with Paystack payment integration
3. **User Role Management** - Different requirements for students, property owners, and admins

## System Components

### 1. Database Tables

#### Subscription Plans Table
```sql
CREATE TABLE subscription_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_name VARCHAR(100) NOT NULL,
    duration_months INT NOT NULL DEFAULT 8,
    price DECIMAL(10,2) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### User Subscriptions Table
```sql
CREATE TABLE user_subscriptions (
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
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);
```

#### OTP Verifications Table
```sql
CREATE TABLE otp_verifications (
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### Subscription Payment Logs Table
```sql
CREATE TABLE subscription_payment_logs (
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
    FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL
);
```

### 2. Service Classes

#### OTPService (`includes/OTPService.php`)
Handles SMS OTP functionality using Arkesel API:
- **sendOTP()** - Generates and sends OTP via SMS
- **verifyOTP()** - Verifies OTP code
- **cleanPhoneNumber()** - Formats phone numbers for Ghana (+233)
- **isValidPhoneNumber()** - Validates phone number format
- **cleanupExpiredOTPs()** - Removes expired OTP records

**Key Features:**
- Rate limiting (2-minute cooldown between requests)
- Maximum 3 verification attempts per OTP
- 10-minute OTP expiration
- Ghana phone number format validation
- Automatic phone number formatting

#### SubscriptionService (`includes/SubscriptionService.php`)
Manages subscription and payment functionality:
- **userNeedsSubscription()** - Checks if user requires subscription (students only)
- **getUserSubscriptionStatus()** - Gets current subscription status
- **createSubscriptionPayment()** - Creates subscription payment record
- **verifyPaystackPayment()** - Verifies payment with Paystack API
- **processSuccessfulPayment()** - Processes successful payments
- **updateExpiredSubscriptions()** - Updates expired subscriptions

### 3. API Endpoints

#### OTP API (`auth/api/otp.php`)
Handles AJAX requests for OTP operations:
- **POST /auth/api/otp.php** with `action=send` - Send OTP
- **POST /auth/api/otp.php** with `action=verify` - Verify OTP
- **POST /auth/api/otp.php** with `action=resend` - Resend OTP

#### Subscription API (`auth/api/subscription.php`)
Handles subscription-related operations:
- **POST /auth/api/subscription.php** with `action=create_payment` - Create payment
- **POST /auth/api/subscription.php** with `action=verify_payment` - Verify payment
- **POST /auth/api/subscription.php** with `action=get_status` - Get subscription status
- **POST /auth/api/subscription.php** with `action=get_plan` - Get subscription plan
- **POST /auth/api/subscription.php** with `action=get_paystack_key` - Get Paystack public key

## Registration Flow

### Step 1: User Details Form
1. User fills in registration form (username, email, password, phone, location, account type, gender)
2. Form validation occurs on client and server side
3. User clicks "Send OTP" button

### Step 2: OTP Verification
1. System sends OTP to user's phone number via Arkesel SMS API
2. User enters received OTP code
3. System verifies OTP code
4. On successful verification, "Verify" button changes to blue "Submit" button
5. User clicks "Submit" to complete registration

### Step 3: Subscription (Students Only)
1. If user is a student, system redirects to subscription page
2. Displays subscription plan details (8 months, price)
3. User clicks "Pay with Paystack" button
4. Paystack payment modal opens
5. On successful payment, system verifies with Paystack API
6. Registration completes and user is redirected to login

### Step 4: Completion
- Property owners and admins: Direct to login after OTP verification
- Students: Complete subscription payment before accessing login

## User Role Requirements

### Students
- ✅ Phone number verification (OTP required)
- ✅ Subscription payment required (8 months validity)
- ✅ Subscription renewal when expired

### Property Owners
- ✅ Phone number verification (OTP required)
- ❌ No subscription required
- ✅ Email verification via phone OTP

### Admins
- ✅ Phone number verification (OTP required)
- ❌ No subscription required
- ✅ Email verification via phone OTP

## Login Flow with Subscription Check

### For Students
1. User enters email and password
2. System validates credentials
3. **Subscription Check:**
   - If no subscription: Show subscription payment button
   - If subscription expired: Show renewal payment button
   - If subscription active: Allow login
4. Payment processed via Paystack if needed
5. Login completes on successful payment/active subscription

### For Property Owners & Admins
1. User enters email and password
2. System validates credentials
3. Login completes (no subscription check)

## Payment Integration

### Paystack Configuration
- **Public Key:** `pk_test_db73c7228ff880b4a3d49593023b91a6a5b923c6`
- **Secret Key:** `sk_test_9c3c7da0284defbf21404dd3faa9cc15ed571d8e`
- **Currency:** GHS (Ghana Ce
