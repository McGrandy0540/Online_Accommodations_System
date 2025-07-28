<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];
$booking_id = (int)$_GET['id'];

// Verify booking belongs to owner
$stmt = $pdo->prepare("
    SELECT b.*, p.property_name, u.username as student_name, u.email as student_email 
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    WHERE b.id = ? AND p.owner_id = ? AND b.status = 'pending'
");
$stmt->execute([$booking_id, $owner_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['error'] = "Booking not found or already processed";
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reject Booking | UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-color);
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Top Navigation Bar */
        .top-nav {
            background: var(--secondary-color);
            color: white;
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            z-index: 1000;
            transition: all var(--transition-speed);
            box-shadow: var(--box-shadow);
        }

        .top-nav-collapsed {
            left: var(--sidebar-collapsed-width);
        }

        .top-nav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.75rem;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        /* Sidebar Styles */
        .sidebar {
            background: var(--secondary-color);
            color: white;
            width: var(--sidebar-width);
            min-height: 100vh;
            transition: all var(--transition-speed);
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
        }

        .sidebar-header {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: var(--header-height);
            display: flex;
            align-items: center;
        }

        .sidebar-menu {
            padding: 1rem 0;
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }

        .sidebar-menu a {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 0;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all var(--transition-speed);
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            color: white;
            background: rgba(0, 0, 0, 0.2);
            border-left: 3px solid var(--primary-color);
        }

        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 1.5rem;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            flex: 1;
            padding: 2rem;
            transition: all var(--transition-speed);
        }

        /* Rejection Card */
        .rejection-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .rejection-card .card-header {
            background: linear-gradient(135deg, var(--accent-color), #c0392b);
            color: white;
            padding: 1.5rem;
            border-bottom: none;
        }

        .rejection-details {
            padding: 1.5rem;
        }

        .detail-row {
            display: flex;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .detail-label {
            flex: 0 0 200px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .detail-value {
            flex: 1;
        }

        /* Reason Selection */
        .reason-options {
            margin: 1.5rem 0;
        }

        .reason-option {
            display: block;
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all var(--transition-speed);
        }

        .reason-option:hover {
            border-color: var(--accent-color);
            background-color: rgba(231, 76, 60, 0.05);
        }

        .reason-option input[type="radio"] {
            margin-right: 0.75rem;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-header span, .sidebar-menu a span {
                display: none;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 0.75rem;
            }
            
            .sidebar-menu a i {
                margin-right: 0;
                font-size: 1.25rem;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }

            .top-nav {
                left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .detail-row {
                flex-direction: column;
            }
            
            .detail-label {
                margin-bottom: 0.5rem;
            }

            .main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-right: 1rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .top-nav {
                left: 0;
            }
            
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }
            
            .sidebar-overlay-open {
                display: block;
            }
        }

        /* Loading Animation */
        .loading-spinner {
            display: none;
            width: 40px;
            height: 40px;
            margin: 0 auto;
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Custom Radio Button */
        .custom-radio {
            position: relative;
            padding-left: 2rem;
            cursor: pointer;
        }

        .custom-radio input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .radio-checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 1.25rem;
            width: 1.25rem;
            background-color: #eee;
            border-radius: 50%;
            border: 2px solid #ccc;
        }

        .custom-radio:hover input ~ .radio-checkmark {
            border-color: var(--accent-color);
        }

        .custom-radio input:checked ~ .radio-checkmark {
            background-color: white;
            border-color: var(--accent-color);
        }

        .radio-checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .custom-radio input:checked ~ .radio-checkmark:after {
            display: block;
        }

        .custom-radio .radio-checkmark:after {
            top: 3px;
            left: 3px;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background: var(--accent-color);
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>UniHomes</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="../dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="index.php" class="active">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="../payments/index.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="../chat/index.php">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../maintenance/index.php">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="../virtual-tours/index.php
            ">
                <i class="fas fa-vr-cardboard"></i>
                <span>Virtual Tours</span>
            </a>
            <a href="../settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav" id="topNav">
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-times-circle me-2"></i>Reject Booking</h5>
        
        <div class="top-nav-right">
            <div class="dropdown">
                <div class="user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <i class="fas fa-chevron-down ms-2 d-none d-md-inline"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="rejection-card card">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-times-circle me-2"></i>Reject Booking Request</h3>
                        </div>
                        
                        <div class="rejection-details">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Rejecting this booking will notify the student and affect their credit score.
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Property:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['property_name']) ?></div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Student:</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($booking['student_name']) ?><br>
                                    <?= htmlspecialchars($booking['student_email']) ?>
                                </div>
                            </div>
                            
                            <div class="detail-row">
                                <div class="detail-label">Booking Dates:</div>
                                <div class="detail-value">
                                    <?= date('M j, Y', strtotime($booking['start_date'])) ?> to <?= date('M j, Y', strtotime($booking['end_date'])) ?><br>
                                    <?= $booking['duration_months'] ?> months
                                </div>
                            </div>
                            
                            <?php if ($booking['special_requests']): ?>
                            <div class="detail-row">
                                <div class="detail-label">Special Requests:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['special_requests']) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <form method="post" action="process_rejection.php" id="rejectionForm">
                                <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                                
                                <h5 class="mt-4 mb-3"><i class="fas fa-comment-dots me-2"></i>Reason for Rejection</h5>
                                
                                <div class="reason-options">
                                    <label class="reason-option custom-radio">
                                        <input type="radio" name="reason" value="Property no longer available" required>
                                        <span class="radio-checkmark"></span>
                                        Property no longer available
                                    </label>
                                    
                                    <label class="reason-option custom-radio">
                                        <input type="radio" name="reason" value="Dates not available">
                                        <span class="radio-checkmark"></span>
                                        Dates not available
                                    </label>
                                    
                                    <label class="reason-option custom-radio">
                                        <input type="radio" name="reason" value="Special requests cannot be accommodated">
                                        <span class="radio-checkmark"></span>
                                        Special requests cannot be accommodated
                                    </label>
                                    
                                    <label class="reason-option custom-radio">
                                        <input type="radio" name="reason" value="Other" id="otherReasonRadio">
                                        <span class="radio-checkmark"></span>
                                        Other (please specify)
                                    </label>
                                    
                                    <div class="mt-3" id="customReasonContainer" style="display: none;">
                                        <label for="customReason" class="form-label">Specify Reason</label>
                                        <textarea class="form-control" id="customReason" name="custom_reason" rows="3" 
                                                  placeholder="Please provide details for rejecting this booking..."></textarea>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    This reason will be shared with the student and may affect their credit score.
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Bookings
                                    </a>
                                    <button type="submit" class="btn btn-danger" id="rejectButton">
                                        <i class="fas fa-times-circle me-2"></i>Confirm Rejection
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Mobile menu toggle
    document.getElementById('mobileMenuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
        document.getElementById('sidebarOverlay').classList.toggle('sidebar-overlay-open');
    });

    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('sidebar-open');
        this.classList.remove('sidebar-overlay-open');
    });

    // Show custom reason field when "Other" is selected
    document.getElementById('otherReasonRadio').addEventListener('change', function() {
        document.getElementById('customReasonContainer').style.display = this.checked ? 'block' : 'none';
    });

    // Form submission with loading state
    document.getElementById('rejectionForm').addEventListener('submit', function(e) {
        const rejectButton = document.getElementById('rejectButton');
        rejectButton.disabled = true;
        rejectButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
        
        // If "Other" is selected but no reason provided
        if (document.getElementById('otherReasonRadio').checked && 
            document.getElementById('customReason').value.trim() === '') {
            e.preventDefault();
            alert('Please specify the reason for rejection');
            rejectButton.disabled = false;
            rejectButton.innerHTML = '<i class="fas fa-times-circle me-2"></i>Confirm Rejection';
            return;
        }
        
        // Show confirmation dialog
        if (!confirm('Are you sure you want to reject this booking? This action cannot be undone.')) {
            e.preventDefault();
            rejectButton.disabled = false;
            rejectButton.innerHTML = '<i class="fas fa-times-circle me-2"></i>Confirm Rejection';
        }
    });

    // Combine custom reason with "Other" selection
    document.getElementById('rejectionForm').addEventListener('submit', function(e) {
        if (document.getElementById('otherReasonRadio').checked) {
            const customReason = document.getElementById('customReason').value.trim();
            if (customReason) {
                // Create a hidden input with the combined value
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'reason';
                hiddenInput.value = 'Other: ' + customReason;
                this.appendChild(hiddenInput);
                
                // Remove the original radio and textarea from submission
                document.getElementById('otherReasonRadio').disabled = true;
                document.getElementById('customReason').disabled = true;
            }
        }
    });
    </script>
</body>
</html>