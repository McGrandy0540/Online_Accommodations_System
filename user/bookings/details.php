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

// Get booking ID
$booking_id = $_GET['id'] ?? null;

if (!$booking_id) {
    $_SESSION['error'] = "Invalid booking ID.";
    header("Location: index.php");
    exit();
}

// Fetch booking details
$booking_stmt = $pdo->prepare("
    SELECT b.*, 
        p.property_name, p.location AS property_location, p.description, p.price AS property_price,
        pr.room_number, pr.capacity, pr.gender,
        u.username AS owner_name, u.email AS owner_email, u.phone_number AS owner_phone
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    JOIN property_rooms pr ON b.room_id = pr.id
    JOIN users u ON p.owner_id = u.id
    WHERE b.id = ? AND b.user_id = ?
");
$booking_stmt->execute([$booking_id, $student_id]);
$booking = $booking_stmt->fetch();

if (!$booking) {
    $_SESSION['error'] = "Booking not found or you don't have permission to view this booking.";
    header("Location: index.php");
    exit();
}

// Fetch property images
$image_stmt = $pdo->prepare("SELECT * FROM property_images WHERE property_id = ?");
$image_stmt->execute([$booking['property_id']]);
$images = $image_stmt->fetchAll();

// Format dates
$start_date = date('F j, Y', strtotime($booking['start_date']));
$end_date = date('F j, Y', strtotime($booking['end_date']));
$booking_date = date('F j, Y', strtotime($booking['booking_date']));

// Calculate total cost
$monthly_price = $booking['property_price'] / 12;
$total_cost = $monthly_price * $booking['duration_months'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation | Landlords&Tenant</title>
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

        .confirmation-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .confirmation-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .confirmation-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--success-color);
        }

        .confirmation-body {
            padding: 2rem;
        }

        .section-title {
            color: var(--secondary-color);
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .property-image {
            height: 250px;
            position: relative;
            overflow: hidden;
            border-radius: var(--border-radius);
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

        .detail-item {
            display: flex;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .detail-label {
            flex: 1;
            font-weight: 500;
            color: var(--secondary-color);
        }

        .detail-value {
            flex: 2;
            color: #495057;
        }

        .owner-card {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .contact-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
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

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-confirmed {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .status-paid {
            background-color: #cfe2ff;
            color: #084298;
        }

        .next-steps {
            background: #e8f4fd;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .step {
            display: flex;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .step:last-child {
            margin-bottom: 0;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .step-content {
            flex: 1;
        }

        .step-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--secondary-color);
        }

        .step-description {
            color: #6c757d;
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

        @media (max-width: 768px) {
            .confirmation-body {
                padding: 1.5rem;
            }
            
            .detail-item {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .contact-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="index.php" class="text-white">
                    <i class="fas fa-arrow-left me-2"></i> Back to Properties
                </a>
                <h1 class="h4 mb-0">Booking Confirmation</h1>
                <div></div> <!-- For alignment -->
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-container">
        <!-- Confirmation Card -->
        <div class="confirmation-card">
            <div class="confirmation-header">
                <div class="confirmation-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1>Booking Confirmed!</h1>
                <p>Your booking has been successfully created</p>
                <span class="status-badge status-<?= $booking['status'] ?>">
                    <?= ucfirst($booking['status']) ?>
                </span>
            </div>
            
            <div class="confirmation-body">
                <div class="row">
                    <div class="col-md-6">
                        <h3 class="section-title">Booking Details</h3>
                        
                        <div class="detail-item">
                            <div class="detail-label">Booking ID:</div>
                            <div class="detail-value">#<?= str_pad($booking['id'], 6, '0', STR_PAD_LEFT) ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Booking Date:</div>
                            <div class="detail-value"><?= $booking_date ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Check-in Date:</div>
                            <div class="detail-value"><?= $start_date ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Check-out Date:</div>
                            <div class="detail-value"><?= $end_date ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Duration:</div>
                            <div class="detail-value"><?= $booking['duration_months'] ?> Months</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Special Requests:</div>
                            <div class="detail-value">
                                <?= $booking['special_requests'] ? htmlspecialchars($booking['special_requests']) : 'None' ?>
                            </div>
                        </div>
                        
                        <h3 class="section-title mt-4">Property Owner</h3>
                        
                        <div class="owner-card">
                            <div class="detail-item">
                                <div class="detail-label">Name:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['owner_name']) ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Email:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['owner_email']) ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Phone:</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['owner_phone']) ?></div>
                            </div>
                            
                            <div class="contact-buttons">
                                <a href="mailto:<?= htmlspecialchars($booking['owner_email']) ?>" class="btn btn-outline">
                                    <i class="fas fa-envelope"></i> Send Email
                                </a>
                                <a href="tel:<?= htmlspecialchars($booking['owner_phone']) ?>" class="btn btn-outline">
                                    <i class="fas fa-phone"></i> Call Now
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h3 class="section-title">Property Details</h3>
                        
                        <div class="property-image mb-3">
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
                                <?= htmlspecialchars($booking['status']) ?>
                            </span>
                            <span class="levy-badge" title="Levy payment approved">
                                <i class="fas fa-check-circle"></i> Levy Paid
                            </span>
                        </div>
                        
                        <div class="property-price">
                            GHS <?= number_format($booking['property_price'], 2) ?> 
                            <span class="text-muted" style="font-size: 1rem;">/year (per student)</span>
                        </div>
                        
                        <h3 class="property-title">
                            <?= htmlspecialchars($booking['property_name']) ?>
                        </h3>
                        
                        <div class="property-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($booking['property_location']) ?>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Room Number:</div>
                            <div class="detail-value">
                                <?= htmlspecialchars($booking['room_number']) ?>
                                <?php 
                                    $gender_class = ($booking['gender'] == 'male') ? 'male-badge' : 'female-badge';
                                    $gender_icon = ($booking['gender'] == 'male') ? 'mars' : 'venus';
                                ?>
                                <span class="gender-badge <?= $gender_class ?>">
                                    <i class="fas fa-<?= $gender_icon ?>"></i> <?= ucfirst($booking['gender']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Room Capacity:</div>
                            <div class="detail-value"><?= $booking['capacity'] ?> students</div>
                        </div>
                        
                        <?php if ($booking['description']): ?>
                            <div class="mt-3">
                                <h5>Description</h5>
                                <p><?= htmlspecialchars($booking['description']) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="price-summary">
                            <h5>Payment Summary</h5>
                            <div class="price-item">
                                <span>Room Price (per year)</span>
                                <span>GHS <?= number_format($booking['property_price'], 2) ?></span>
                            </div>
                            <div class="price-item">
                                <span>Duration</span>
                                <span><?= $booking['duration_months'] ?> Months</span>
                            </div>
                            <div class="price-item">
                                <span>Monthly Price</span>
                                <span>GHS <?= number_format($monthly_price, 2) ?></span>
                            </div>
                            <div class="price-item price-total">
                                <span>Total Payment Due</span>
                                <span>GHS <?= number_format($total_cost, 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Next Steps -->
                <div class="next-steps">
                    <h3 class="section-title">Next Steps</h3>
                    
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <div class="step-title">Contact Property Owner</div>
                            <div class="step-description">
                                Reach out to the property owner to arrange key pickup and discuss move-in details. 
                                Their contact information is provided above.
                            </div>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <div class="step-title">Complete Payment</div>
                            <div class="step-description">
                                Make payment to the property owner using your preferred method. 
                                You can pay via mobile money, bank transfer, or cash upon move-in.
                            </div>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <div class="step-title">Prepare for Move-in</div>
                            <div class="step-description">
                                Plan your move-in day. Make sure to bring your identification and 
                                any necessary documents requested by the property owner.
                            </div>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <div class="step-title">Enjoy Your Stay</div>
                            <div class="step-description">
                                Once you've moved in, you can review your experience and provide feedback 
                                to help other students find great accommodation.
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="d-flex justify-content-center gap-3 mt-4">
                    <a href="../dashboard.php" class="btn btn-outline">
                        <i class="fas fa-home me-2"></i> Back to Dashboard
                    </a>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i> Find More Properties
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize carousel
        const carousel = new bootstrap.Carousel('#propertyCarousel');
    </script>
</body>
</html>