<?php
session_start();
require_once __DIR__. '../../../config/database.php';

// Redirect if not authenticated or not a student
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$pdo = Database::getInstance();
$student_id = $_SESSION['user_id'];

// Get property and room details
$property_id = $_GET['property_id'] ?? null;
$room_id = $_GET['room_id'] ?? null;

// Validate property and room
if (!$property_id || !$room_id) {
    $_SESSION['error'] = "Invalid property or room selection.";
    header("Location: index.php");
    exit();
}

// Fetch property details
$property_stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name, 
        (SELECT AVG(rating) FROM reviews WHERE property_id = p.id) AS average_rating
    FROM property p
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ? AND p.approved = 1 AND p.deleted = 0
");
$property_stmt->execute([$property_id]);
$property = $property_stmt->fetch();

if (!$property) {
    $_SESSION['error'] = "Property not found or not available.";
    header("Location: index.php");
    exit();
}

// CORRECTED: Fetch room details with proper NULL checks for cancelled_at
$room_stmt = $pdo->prepare("
    SELECT pr.*, 
           -- Count active confirmed bookings (excluding cancelled)
           (SELECT COUNT(*) 
            FROM bookings b 
            WHERE b.room_id = pr.id 
            AND b.status IN ('confirmed', 'paid', 'cash_approved')
            AND (b.cancelled_at IS NULL )
           ) AS confirmed_bookings,
           -- Count pending bookings
           (SELECT COUNT(*) 
            FROM bookings b 
            WHERE b.room_id = pr.id 
            AND b.status IN ('pending', 'pending_payment')
            AND (b.cancelled_at IS NULL)
           ) AS pending_bookings
    FROM property_rooms pr 
    WHERE pr.id = ? AND pr.property_id = ? 
    AND pr.levy_payment_status = 'approved'
    AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
");
$room_stmt->execute([$room_id, $property_id]);
$room = $room_stmt->fetch();

// CORRECTED: Simplified availability validation
if (!$room) {
    $_SESSION['error'] = "The selected room is not available for booking.";
    header("Location: ../properties/index.php");
    exit();
}

// Calculate actual available spots
$actual_available = max(0, $room['capacity'] - $room['confirmed_bookings'] - $room['pending_bookings']);

if ($actual_available <= 0) {
    $_SESSION['error'] = "The selected room is no longer available for booking.";
    header("Location: ../properties/index.php");
    exit();
}

// Fetch property images
$image_stmt = $pdo->prepare("SELECT * FROM property_images WHERE property_id = ?");
$image_stmt->execute([$property_id]);
$images = $image_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $start_date = $_POST['start_date'];
    $duration_months = (int)$_POST['duration_months'];
    $special_requests = $_POST['special_requests'] ?? '';
    
    // Validate inputs
    $errors = [];
    if (empty($start_date)) {
        $errors[] = "Start date is required.";
    } elseif (strtotime($start_date) < strtotime('today')) {
        $errors[] = "Start date cannot be in the past.";
    }
    
    if ($duration_months < 1 || $duration_months > 24) {
        $errors[] = "Duration must be between 1 and 24 months.";
    }
    
    // CORRECTED: Double-check room availability before creating booking with proper NULL checks
    $availability_check = $pdo->prepare("
        SELECT 
            pr.capacity,
            (SELECT COUNT(*) 
             FROM bookings b 
             WHERE b.room_id = pr.id 
             AND b.status IN ('confirmed', 'paid', 'cash_approved')
             AND (b.cancelled_at IS NULL )
            ) AS confirmed_count,
            (SELECT COUNT(*) 
             FROM bookings b 
             WHERE b.room_id = pr.id 
             AND b.status IN ('pending', 'pending_payment')
             AND (b.cancelled_at IS NULL )
            ) AS pending_count
        FROM property_rooms pr 
        WHERE pr.id = ? AND pr.levy_payment_status = 'approved'
    ");
    $availability_check->execute([$room_id]);
    $availability = $availability_check->fetch();
    
    if ($availability) {
        $current_available = max(0, $availability['capacity'] - $availability['confirmed_count'] - $availability['pending_count']);
        if ($current_available <= 0) {
            $errors[] = "Sorry, this room was just booked by someone else. Please select another room.";
        }
    } else {
        $errors[] = "Room availability could not be verified. Please try again.";
    }
    
    if (empty($errors)) {
        // Calculate end date
        $end_date = date('Y-m-d', strtotime("+$duration_months months", strtotime($start_date)));
        
        // Create booking
        try {
            $pdo->beginTransaction();
            
            // Insert booking
            $booking_stmt = $pdo->prepare("
                INSERT INTO bookings (
                    user_id, property_id, room_id, 
                    start_date, end_date, duration_months, 
                    special_requests, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $booking_stmt->execute([
                $student_id, $property_id, $room_id,
                $start_date, $end_date, $duration_months,
                $special_requests
            ]);
            $booking_id = $pdo->lastInsertId();
            
            // CORRECTED: Update room current_occupancy based on confirmed bookings with proper NULL checks
            $update_occupancy_stmt = $pdo->prepare("
                UPDATE property_rooms 
                SET current_occupancy = (
                    SELECT COUNT(*) 
                    FROM bookings 
                    WHERE room_id = ? 
                    AND status IN ('confirmed', 'paid', 'cash_approved')
                    AND (cancelled_at IS NULL)
                ),
                updated_at = NOW()
                WHERE id = ?
            ");
            $update_occupancy_stmt->execute([$room_id, $room_id]);
            
            // Update room status based on occupancy
            $update_status_stmt = $pdo->prepare("
                UPDATE property_rooms 
                SET status = CASE 
                    WHEN current_occupancy = 0 THEN 'available'
                    WHEN current_occupancy >= capacity THEN 'occupied'
                    ELSE 'occupied'
                END
                WHERE id = ?
            ");
            $update_status_stmt->execute([$room_id]);
            
            $pdo->commit();
            
            // Redirect to booking confirmation
            $_SESSION['success'] = "Booking created successfully! Your booking is pending approval.";
            header("Location: details.php?id=$booking_id");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Failed to create booking: " . $e->getMessage();
            error_log("Booking error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Accommodation | Landlords&Tenant</title>
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
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark-color);
        }

        .header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 15px;
        }

        .booking-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .booking-container {
                grid-template-columns: 1fr;
            }
        }

        .property-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .property-image {
            height: 300px;
            position: relative;
            overflow: hidden;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .property-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            z-index: 10;
        }

        .levy-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--success-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            z-index: 10;
        }

        .property-content {
            padding: 1.5rem;
        }

        .property-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .property-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
            font-size: 1.25rem;
        }

        .property-location {
            display: flex;
            align-items: center;
            color: #6c757d;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .property-location i {
            margin-right: 0.5rem;
        }

        .property-features {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .property-feature {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .room-details {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .room-title {
            font-weight: 600;
            color: var(--secondary-color);
            font-size: 1.1rem;
        }

        .booking-form {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
        }

        .booking-form h2 {
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eee;
        }

        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem;
            border: 1px solid #ddd;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 1rem;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .alert {
            border-radius: var(--border-radius);
        }

        .price-summary {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .price-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }

        .price-total {
            font-weight: 700;
            font-size: 1.25rem;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 2px solid #ddd;
        }

        .carousel {
            height: 100%;
        }

        .carousel-inner, .carousel-item, .carousel-item img {
            height: 100%;
            width: 100%;
            object-fit: cover;
        }

        .carousel-control-prev, .carousel-control-next {
            background-color: rgba(0,0,0,0.3);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
        }

        .gender-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .male-badge {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .female-badge {
            background-color: #f8d7da;
            color: #721c24;
        }

        .availability-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .available {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .limited {
            background-color: #fff3cd;
            color: #856404;
        }

        .full {
            background-color: #f8d7da;
            color: #721c24;
        }

        .progress-container {
            margin: 0.75rem 0;
        }

        .progress {
            height: 8px;
            border-radius: 4px;
        }

        .booking-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }

        .booking-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #ddd;
            z-index: 0;
        }

        .step {
            text-align: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 700;
            color: #6c757d;
        }

        .step.active .step-number {
            background: var(--primary-color);
            color: white;
        }

        .step-text {
            font-size: 0.9rem;
            font-weight: 500;
            color: #6c757d;
        }

        .step.active .step-text {
            color: var(--primary-color);
        }

        .cancelled-spots-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: var(--border-radius);
            padding: 0.75rem;
            margin: 0.5rem 0;
            font-size: 0.85rem;
        }

        .cancelled-spots-info i {
            color: var(--info-color);
            margin-right: 0.5rem;
        }

        @media (max-width: 576px) {
            .booking-steps {
                flex-direction: column;
                gap: 1.5rem;
            }
            
            .booking-steps::before {
                display: none;
            }
            
            .step {
                display: flex;
                align-items: center;
                gap: 1rem;
            }
            
            .step-number {
                margin: 0;
            }
            
            .step::after {
                content: '';
                position: absolute;
                left: 20px;
                top: 40px;
                bottom: -1.5rem;
                width: 2px;
                background: #ddd;
                z-index: 0;
            }
            
            .step:last-child::after {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="../search/index.php" class="text-white">
                    <i class="fas fa-arrow-left me-2"></i> Back to Find Accommodation
                </a>
                <h1 class="h4 mb-0">Book Accommodation</h1>
                <div></div> <!-- For alignment -->
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-container">
        <!-- Booking Steps -->
        <div class="booking-steps">
            <div class="step active">
                <div class="step-number">1</div>
                <div class="step-text">Room Details</div>
            </div>
            <div class="step active">
                <div class="step-number">2</div>
                <div class="step-text">Booking Information</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-text">Confirmation</div>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="booking-container">
            <!-- Property Details -->
            <div class="property-card">
                <div class="property-image">
                    <?php if (!empty($images)): ?>
                        <div id="propertyCarousel" class="carousel slide" data-bs-ride="carousel">
                            <div class="carousel-inner">
                                <?php foreach ($images as $index => $image): ?>
                                    <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                        <img src="../../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                             class="d-block w-100" 
                                             alt="Property image">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($images) > 1): ?>
                                <button class="carousel-control-prev" type="button" 
                                        data-bs-target="#propertyCarousel" 
                                        data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon"></span>
                                </button>
                                <button class="carousel-control-next" type="button" 
                                        data-bs-target="#propertyCarousel" 
                                        data-bs-slide="next">
                                    <span class="carousel-control-next-icon"></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <img src="../../assets/images/default-property.jpg" 
                             alt="Default property image">
                    <?php endif; ?>
                    
                    <span class="property-badge">
                        <?= htmlspecialchars($property['status']) ?>
                    </span>
                    <span class="levy-badge" title="Levy payment approved">
                        <i class="fas fa-check-circle"></i> Levy Paid
                    </span>
                </div>
                
                <div class="property-content">
                    <div class="property-price">
                        GHS <?= number_format($property['price'], 2) ?> 
                        <span class="text-muted" style="font-size: 1rem;">/year (per tenant)</span>
                    </div>
                    
                    <h3 class="property-title">
                        <?= htmlspecialchars($property['property_name']) ?>
                    </h3>
                    
                    <div class="property-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= htmlspecialchars($property['location']) ?>
                    </div>
                    
                    <div class="property-features">
                        <div class="property-feature">
                            <i class="fas fa-bed"></i>
                            <?= $property['bedrooms'] ?? 0 ?> beds
                        </div>
                        <div class="property-feature">
                            <i class="fas fa-bath"></i>
                            <?= $property['bathrooms'] ?? 0 ?> baths
                        </div>
                        <div class="property-feature">
                            <i class="fas fa-ruler-combined"></i>
                            <?= $property['area_sqft'] ? number_format($property['area_sqft']) : 'N/A' ?> sqft
                        </div>
                        <div class="property-feature">
                            <i class="fas fa-home"></i>
                            <?= htmlspecialchars($property['category_name']) ?>
                        </div>
                    </div>
                    
                    <!-- Room Details -->
                    <div class="room-details">
                        <div class="room-header">
                            <h4 class="room-title">
                                Room <?= htmlspecialchars($room['room_number']) ?>
                                <?php 
                                    $gender_class = ($room['gender'] == 'male') ? 'male-badge' : 'female-badge';
                                    $gender_icon = ($room['gender'] == 'male') ? 'mars' : 'venus';
                                ?>
                                <span class="gender-badge <?= $gender_class ?>">
                                    <i class="fas fa-<?= $gender_icon ?>"></i> <?= ucfirst($room['gender']) ?>
                                </span>
                            </h4>
                            <?php
                            // Determine availability badge class
                            if ($actual_available == 0) {
                                $availability_class = "full";
                                $availability_text = "Fully Booked";
                            } elseif ($actual_available <= 2) {
                                $availability_class = "limited";
                                $availability_text = "Limited Availability";
                            } else {
                                $availability_class = "available";
                                $availability_text = "Available";
                            }
                            ?>
                            <span class="availability-badge <?= $availability_class ?>">
                                <?= $availability_text ?>
                            </span>
                        </div>
                        
                        <div class="progress-container">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Confirmed Occupants: <?= $room['confirmed_bookings'] ?>/<?= $room['capacity'] ?></small>
                                <small><?= number_format(($room['confirmed_bookings'] / $room['capacity']) * 100, 0) ?>%</small>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" 
                                    style="width: <?= ($room['confirmed_bookings'] / $room['capacity']) * 100 ?>%;" 
                                    aria-valuenow="<?= ($room['confirmed_bookings'] / $room['capacity']) * 100 ?>" 
                                    aria-valuemin="0" 
                                    aria-valuemax="100"></div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between mt-2">
                            <div class="fw-medium">
                                Capacity: <?= $room['capacity'] ?> tenants
                            </div>
                            <div class="fw-medium text-success">
                                Available Spots: <?= $actual_available ?>
                            </div>
                        </div>
                        
                        <?php if ($room['pending_bookings'] > 0): ?>
                            <div class="mt-2">
                                <small class="text-warning">
                                    <i class="fas fa-clock"></i> <?= $room['pending_bookings'] ?> pending booking(s) - these spots are temporarily reserved
                                </small>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Cancelled spots information -->
                        <div class="cancelled-spots-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> Cancelled bookings automatically free up spots for new bookings.
                        </div>
                    </div>
                    
                    <?php if ($property['description']): ?>
                        <div class="mt-3">
                            <h5>Description</h5>
                            <p><?= htmlspecialchars($property['description']) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($property['average_rating']): ?>
                        <div class="d-flex align-items-center mt-3">
                            <div class="text-warning me-2">
                                <?php
                                $fullStars = floor($property['average_rating']);
                                $halfStar = ceil($property['average_rating'] - $fullStars);
                                
                                for ($i = 0; $i < $fullStars; $i++) {
                                    echo '<i class="fas fa-star"></i>';
                                }
                                if ($halfStar) {
                                    echo '<i class="fas fa-star-half-alt"></i>';
                                }
                                for ($i = 0; $i < (5 - $fullStars - $halfStar); $i++) {
                                    echo '<i class="far fa-star"></i>';
                                }
                                ?>
                            </div>
                            <span class="text-muted">
                                (<?= number_format($property['average_rating'], 1) ?> average rating)
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Booking Form -->
            <div class="booking-form">
                <h2>Booking Information</h2>
                
                <form method="POST" id="bookingForm">
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Move-in Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               min="<?= date('Y-m-d') ?>" 
                               value="<?= date('Y-m-d', strtotime('+1 week')) ?>" required>
                        <div class="form-text">Select your preferred move-in date</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="duration_months" class="form-label">Duration (Months)</label>
                        <select class="form-select" id="duration_months" name="duration_months" required>
                            <option value="1" selected>1 Month</option>
                            <option value="3">3 Months</option>
                            <option value="6">6 Months</option>
                            <option value="9">9 Months</option>
                            <option value="12">12 Months</option>
                            <option value="24">24 Months</option>
                        </select>
                        <div class="form-text">Select how long you plan to stay</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="special_requests" class="form-label">Special Requests (Optional)</label>
                        <textarea class="form-control" id="special_requests" name="special_requests" 
                                  rows="3" placeholder="Any special requirements or requests..."><?= htmlspecialchars($_POST['special_requests'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="price-summary">
                        <h5>Price Summary</h5>
                        <div class="price-item">
                            <span>Room Price (per year)</span>
                            <span>GHS <?= number_format($property['price'], 2) ?></span>
                        </div>
                        <div class="price-item">
                            <span>Duration</span>
                            <span id="durationDisplay">1 Month</span>
                        </div>
                        <div class="price-item">
                            <span>Estimated Cost</span>
                            <span id="estimatedCost">GHS <?= number_format($property['price'] / 12, 2) ?></span>
                        </div>
                        <div class="price-item price-total">
                            <span>Total Payment Due</span>
                            <span id="totalPayment">GHS <?= number_format($property['price'] / 12, 2) ?></span>
                        </div>
                        <div class="form-text mt-2">
                            Note: This is an estimate. Final payment may include additional fees.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-check me-2"></i> Confirm Booking
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize carousel
        const carousel = new bootstrap.Carousel('#propertyCarousel');
        
        // Calculate and display price based on duration
        const pricePerYear = <?= $property['price'] ?>;
        const durationSelect = document.getElementById('duration_months');
        const durationDisplay = document.getElementById('durationDisplay');
        const estimatedCost = document.getElementById('estimatedCost');
        const totalPayment = document.getElementById('totalPayment');
        
        function updatePrice() {
            const months = parseInt(durationSelect.value);
            const cost = (pricePerYear / 12) * months;
            
            durationDisplay.textContent = `${months} Month${months > 1 ? 's' : ''}`;
            estimatedCost.textContent = `GHS ${cost.toFixed(2)}`;
            totalPayment.textContent = `GHS ${cost.toFixed(2)}`;
        }
        
        durationSelect.addEventListener('change', updatePrice);
        
        // Initialize price on page load
        updatePrice();
        
        // Set minimum date to today
        const today = new Date();
        const minDate = today.toISOString().split('T')[0];
        document.getElementById('start_date').min = minDate;
        
        // Form submission confirmation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('start_date').value;
            if (!startDate) {
                e.preventDefault();
                alert('Please select a move-in date');
                return;
            }
            
            // Optional: Add confirmation dialog
            if (!confirm('Are you sure you want to book this room? This action will reserve a spot for you.')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>