<?php
// bookings/view.php - Booking Details Page
session_start();
require_once '../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

$booking_id = $_GET['id'] ?? 0;
$current_user_id = $_SESSION['user_id'];
$current_user_status = $_SESSION['status'];

$pdo = Database::getInstance();

// Get booking details
$stmt = $pdo->prepare("
    SELECT 
        b.*,
        p.property_name, p.owner_id, p.location, p.price,
        u.username AS student_name, u.email AS student_email, u.phone_number AS student_phone,
        pr.room_number, pr.capacity, pr.gender AS room_gender,
        o.username AS owner_name, o.email AS owner_email, o.phone_number AS owner_phone,
        py.amount AS payment_amount, py.status AS payment_status, py.created_at AS payment_date,
        py.transaction_id
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    JOIN users o ON p.owner_id = o.id
    LEFT JOIN payments py ON b.id = py.booking_id
    WHERE b.id = ?
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    die("<h1>Booking Not Found</h1><p>The requested booking does not exist.</p>");
}

// Check if current user has permission to view this booking
$is_owner = ($current_user_id == $booking['owner_id']);
$is_student = ($current_user_id == $booking['user_id']);
$is_admin = ($current_user_status == 'admin');

if (!$is_owner && !$is_student && !$is_admin) {
    die("<h1>Access Denied</h1><p>You don't have permission to view this booking.</p>");
}

// Process status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $new_status = '';
    
    if ($action === 'confirm' && $is_owner) {
        $new_status = 'confirmed';
    } elseif ($action === 'cancel') {
        $new_status = 'cancelled';
    } elseif ($action === 'mark_paid' && $is_admin) {
        $new_status = 'paid';
    }
    
    if ($new_status) {
        $update_stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $update_stmt->execute([$new_status, $booking_id]);
        
        // Reload booking data
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        // Send notification to the other party
        $recipient_id = $is_owner ? $booking['user_id'] : $booking['owner_id'];
        $message = "Booking #{$booking_id} status changed to " . ucfirst($new_status);
        
        $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, created_at) VALUES (?, ?, 'booking_update', NOW())");
        $notif_stmt->execute([$recipient_id, $message]);
    }
}

// Calculate duration and total cost
$start_date = new DateTime($booking['start_date']);
$end_date = new DateTime($booking['end_date']);
$interval = $start_date->diff($end_date);
$duration_months = $interval->m + ($interval->y * 12);
$total_cost = $duration_months * ( $booking['price'] / 12 );

// Get profile picture path function
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../../' . ltrim($path, '/');
}

// Get current user data
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$current_user_id]);
$current_user = $user_stmt->fetch();
$profile_pic_path = getProfilePicturePath($current_user['profile_picture'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | Landlords&Tenant</title>
    
    <!-- Bootstrap 5 CSS -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
            --danger-color: #dc3545;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            padding-top: var(--header-height);
        }
        
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background-color: white;
            box-shadow: var(--box-shadow);
            z-index: 1000;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
            padding: 0 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .logo img {
            height: 40px;
            margin-right: 10px;
        }



          .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            cursor: pointer;
        }

        .user-profile img, .user-profile .avatar-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-profile .avatar-placeholder {
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        



        .booking-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .bg-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .bg-confirmed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .bg-paid {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .bg-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        
               .profile-avatar-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--card-shadow);
        }

        .profile-avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            border: 3px solid white;
            box-shadow: var(--card-shadow);
        }

        .profile-info {
            margin-bottom: 1.5rem;
        }

        .profile-info h4 {
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .profile-info p {
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .profile-info .location {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-color);
            font-size: 0.95rem;
        }

        .profile-info .location i {
            margin-right: 0.5rem;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
            list-style: none;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 10px;
            width: 2px;
            background-color: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            top: 5px;
            left: -28px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: var(--primary-color);
            z-index: 1;
        }
        
        .btn-action {
            min-width: 120px;
            margin: 5px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #6c757d;
            min-width: 150px;
        }
        
        .detail-value {
            font-weight: 500;
        }
        
        .divider {
            border-top: 1px dashed #dee2e6;
            margin: 1.5rem 0;
        }
        
        @media (max-width: 768px) {
            .booking-header {
                padding: 1.5rem;
            }
            
            .profile-avatar, .profile-avatar-placeholder {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .btn-action {
                width: 100%;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../dashboard.php" class="logo">
                <img src="../../assets/images/logo-removebg-preview.png" alt="UniHomes Logo">
                <span>Landlords&Tenant</span>
            </a>
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($owner['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($current_user['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                       <li>
                         <form action="logout.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                          <button type="submit" class="dropdown-item">
                           <i class="fas fa-sign-out-alt "></i> Logout
                          </button>
                         </form>
                      </li>
                    </ul>
                </div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="container py-4">
        <!-- Booking Header -->
        <div class="booking-header">
            <div class="row align-items-center">
                <div class="col-md-8 d-flex align-items-center">
                    <div>
                        <h2>Booking #<?= $booking_id ?></h2>
                        <p class="mb-0"><?= $booking['property_name'] ?> - <?= $booking['location'] ?></p>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="status-badge bg-<?= 
                        $booking['status'] === 'pending' ? 'pending' : 
                        ($booking['status'] === 'confirmed' ? 'confirmed' : 
                        ($booking['status'] === 'paid' ? 'paid' : 'cancelled'))
                    ?>">
                        <?= ucfirst($booking['status']) ?>
                    </span>
                    <div class="mt-2">
                        <span class="text-white">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <?= date('M j, Y', strtotime($booking['booking_date'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Left Column: Booking Details -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Booking Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="d-flex">
                                    <span class="detail-label">Property:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['property_name']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['location']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Room:</span>
                                    <span class="detail-value">
                                        <?= $booking['room_number'] ? 'Room '.htmlspecialchars($booking['room_number']) : 'Not assigned' ?>
                                        <?= $booking['room_number'] ? '('.$booking['capacity'].' students capacity)' : '' ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex">
                                    <span class="detail-label">Duration:</span>
                                    <span class="detail-value"><?= $duration_months ?> months</span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Check-in:</span>
                                    <span class="detail-value"><?= date('M j, Y', strtotime($booking['start_date'])) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Check-out:</span>
                                    <span class="detail-value"><?= date('M j, Y', strtotime($booking['end_date'])) ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-user-graduate me-2"></i>Student Information</h6>
                                <div class="d-flex">
                                    <span class="detail-label">Name:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['student_name']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['student_email']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['student_phone']) ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-home me-2"></i>Owner Information</h6>
                                <div class="d-flex">
                                    <span class="detail-label">Name:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['owner_name']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['owner_email']) ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value"><?= htmlspecialchars($booking['owner_phone']) ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="mb-3">
                            <h6><i class="fas fa-sticky-note me-2"></i>Special Requests</h6>
                            <p class="mt-2"><?= $booking['special_requests'] ? htmlspecialchars($booking['special_requests']) : 'No special requests' ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Information -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Payment Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="d-flex">
                                    <span class="detail-label">Monthly Rate:</span>
                                    <span class="detail-value">GHS <?= number_format($booking['price'] / 12, 2)  ?></span>
                                </div>
                                <div class="d-flex">
                                    <span class="detail-label">Duration:</span>
                                    <span class="detail-value"><?= $duration_months ?> months</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex">
                                    <span class="detail-label">Total Cost:</span>
                                    <span class="detail-value fw-bold">GHS <?= number_format($total_cost, 2) ?></span>
                                </div>
                                <?php if ($booking['payment_status']): ?>
                                <div class="d-flex">
                                    <span class="detail-label">Payment Status:</span>
                                    <span class="detail-value text-<?= $booking['payment_status'] === 'completed' ? 'success' : 'warning' ?>">
                                        <?= ucfirst($booking['payment_status']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($booking['payment_status'] === 'completed'): ?>
                        <div class="alert alert-success">
                            <div class="d-flex">
                                <i class="fas fa-check-circle fa-2x me-3 mt-1 text-success"></i>
                                <div>
                                    <h6 class="mb-1">Payment Received</h6>
                                    <p class="mb-0">Transaction ID: <?= $booking['transaction_id'] ?></p>
                                    <p class="mb-0">Amount: GHS <?= number_format($booking['payment_amount'], 2) ?></p>
                                    <p class="mb-0">Date: <?= date('M j, Y H:i', strtotime($booking['payment_date'])) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <div class="d-flex">
                                <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1 text-warning"></i>
                                <div>
                                    <h6 class="mb-1">Payment Pending</h6>
                                    <p class="mb-0">This booking has not been paid yet.</p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Actions and Timeline -->
            <div class="col-lg-4">
                <!-- Booking Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Booking Actions</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="d-grid gap-2">
                                <?php if ($is_owner && $booking['status'] === 'pending'): ?>
                                    <button type="submit" name="action" value="confirm" class="btn btn-success btn-action">
                                        <i class="fas fa-check-circle me-2"></i>Confirm Booking
                                    </button>
                                    <button type="submit" name="action" value="cancel" class="btn btn-danger btn-action">
                                        <i class="fas fa-times-circle me-2"></i>Cancel Booking
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($is_student && $booking['status'] === 'pending'): ?>
                                    <button type="submit" name="action" value="cancel" class="btn btn-danger btn-action">
                                        <i class="fas fa-times-circle me-2"></i>Cancel Booking
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($is_admin && $booking['status'] === 'confirmed' && $booking['payment_status'] !== 'completed'): ?>
                                    <button type="submit" name="action" value="mark_paid" class="btn btn-primary btn-action">
                                        <i class="fas fa-money-bill-wave me-2"></i>Mark as Paid
                                    </button>
                                <?php endif; ?>
                            
                                
                                <a href="../view.php?id=<?= $booking['property_id'] ?>" class="btn btn-outline-secondary btn-action">
                                    <i class="fas fa-home me-2"></i>View Property
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Booking Timeline -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Booking Timeline</h5>
                    </div>
                    <div class="card-body">
                        <ul class="timeline">
                            <li class="timeline-item">
                                <div class="fw-bold">Booking Created</div>
                                <div class="text-muted small"><?= date('M j, Y H:i', strtotime($booking['booking_date'])) ?></div>
                                <p>Booking request submitted</p>
                            </li>
                            
                            <?php if ($booking['status'] === 'confirmed'): ?>
                            <li class="timeline-item">
                                <div class="fw-bold">Booking Confirmed</div>
                                <div class="text-muted small"><?= date('M j, Y H:i', strtotime($booking['updated_at'])) ?></div>
                                <p>Owner confirmed the booking</p>
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($booking['payment_status'] === 'completed'): ?>
                            <li class="timeline-item">
                                <div class="fw-bold">Payment Completed</div>
                                <div class="text-muted small"><?= date('M j, Y H:i', strtotime($booking['payment_date'])) ?></div>
                                <p>Payment received successfully</p>
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] === 'cancelled'): ?>
                            <li class="timeline-item">
                                <div class="fw-bold">Booking Cancelled</div>
                                <div class="text-muted small"><?= date('M j, Y H:i', strtotime($booking['updated_at'])) ?></div>
                                <p>Booking was cancelled</p>
                            </li>
                            <?php endif; ?>
                            
                            <li class="timeline-item">
                                <div class="fw-bold">Check-in Date</div>
                                <div class="text-muted small"><?= date('M j, Y', strtotime($booking['start_date'])) ?></div>
                                <p>Scheduled arrival date</p>
                            </li>
                            
                            <li class="timeline-item">
                                <div class="fw-bold">Check-out Date</div>
                                <div class="text-muted small"><?= date('M j, Y', strtotime($booking['end_date'])) ?></div>
                                <p>Scheduled departure date</p>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Cancellation Policy -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Cancellation Policy</h5>
                    </div>
                    <div class="card-body">
                        <p class="small">Cancellation policy for this property:</p>
                        <ul class="small">
                            <li><strong>Flexible:</strong> Full refund if canceled at least 14 days before check-in</li>
                            <li><strong>Moderate:</strong> Full refund if canceled at least 30 days before check-in</li>
                            <li><strong>Strict:</strong> 50% refund if canceled at least 30 days before check-in</li>
                        </ul>
                        <p class="small mb-0">This property has a <strong><?= ucfirst($booking['cancellation_policy']) ?></strong> cancellation policy.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Handle action buttons
        document.querySelectorAll('button[name="action"]').forEach(button => {
            button.addEventListener('click', function() {
                const action = this.value;
                
                if (action === 'cancel') {
                    if (!confirm('Are you sure you want to cancel this booking?')) {
                        return false;
                    }
                }
                
                if (action === 'confirm') {
                    if (!confirm('Confirm this booking? This will notify the student.')) {
                        return false;
                    }
                }
                
                return true;
            });
        });
    </script>
</body>
</html>