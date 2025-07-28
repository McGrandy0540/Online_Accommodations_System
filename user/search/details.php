<?php
session_start();
require_once __DIR__.'../../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$pdo = Database::getInstance();
$student_id = $_SESSION['user_id'];

// Get property ID from URL
$property_id = $_GET['id'] ?? null;

if (!$property_id) {
    header("Location: index.php");
    exit();
}

// Fetch property details
$stmt = $pdo->prepare("
    SELECT p.*, 
           c.name AS category_name,
           u.username AS owner_name, 
           u.email AS owner_email, 
           u.phone_number AS owner_phone,
           (SELECT AVG(rating) FROM reviews WHERE property_id = p.id) AS average_rating
    FROM property p
    JOIN categories c ON p.category_id = c.id
    JOIN users u ON p.owner_id = u.id
    WHERE p.id = ? AND p.approved = 1 AND p.deleted = 0
    AND EXISTS (
        SELECT 1 FROM property_rooms pr 
        WHERE pr.property_id = p.id 
        AND pr.levy_payment_status = 'approved'
    )
");
$stmt->execute([$property_id]);
$property = $stmt->fetch();

if (!$property) {
    header("Location: index.php");
    exit();
}

// Fetch property images
$image_stmt = $pdo->prepare("SELECT * FROM property_images WHERE property_id = ?");
$image_stmt->execute([$property_id]);
$images = $image_stmt->fetchAll();

// Fetch property features
$feature_stmt = $pdo->prepare("SELECT feature_name FROM property_features WHERE property_id = ?");
$feature_stmt->execute([$property_id]);
$features = $feature_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch available rooms
$room_stmt = $pdo->prepare("
    SELECT * FROM property_rooms 
    WHERE property_id = ? 
    AND status = 'available'
    AND levy_payment_status = 'approved'
");
$room_stmt->execute([$property_id]);
$rooms = $room_stmt->fetchAll();

// Fetch reviews
$review_stmt = $pdo->prepare("
    SELECT r.*, u.username AS reviewer_name, u.profile_picture
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.property_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$review_stmt->execute([$property_id]);
$reviews = $review_stmt->fetchAll();

// Calculate star ratings
$fullStars = floor($property['average_rating']);
$halfStar = ceil($property['average_rating'] - $fullStars);
$emptyStars = 5 - $fullStars - $halfStar;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($property['property_name']) ?> | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
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

        /* Header Styles */
        .property-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* Main Content */
        .property-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 15px;
        }

        /* Property Gallery */
        .property-gallery {
            display: grid;
            grid-template-columns: 2fr 1fr;
            grid-template-rows: auto auto;
            gap: 1rem;
            height: 500px;
            margin-bottom: 2rem;
        }

        .main-image {
            grid-row: span 2;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .main-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .side-image {
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .side-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Property Info */
        .property-info-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .property-details {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
        }

        .property-booking {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            position: sticky;
            top: 100px;
        }

        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .amenity-item i {
            color: var(--primary-color);
        }

        /* Reviews Section */
        .review-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            margin-right: 1rem;
        }

        .review-stars {
            color: #ffc107;
            margin-bottom: 0.5rem;
        }

        /* Map Section */
        .map-container {
            height: 400px;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin: 2rem 0;
        }

        /* Rooms Section */
        .room-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        /* Contact Owner */
        .contact-owner {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin: 2rem 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            cursor: pointer;
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

        /* Badges */
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }

        .badge-primary {
            background: var(--primary-color);
            color: white;
        }

        .badge-success {
            background: var(--success-color);
            color: white;
        }

        /* Property Features */
        .property-features {
            display: flex;
            gap: 1rem;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }

        .property-feature {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        /* Price per student */
        .price-per-student {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-top: 0.5rem;
        }
        
        /* Room specific actions */
        .room-actions {
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .property-gallery {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .main-image {
                grid-row: auto;
                height: 400px;
            }
            
            .side-images {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .property-info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .property-gallery {
                height: auto;
            }
            
            .main-image {
                height: 300px;
            }
            
            .side-images {
                grid-template-columns: 1fr;
            }
            
            .amenities-grid {
                grid-template-columns: 1fr;
            }
            
            .room-actions .btn {
                width: 100%;
            }
        }
        
        /* Booking Form */
        .booking-form {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            margin-top: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        /* Back Button */
        .back-btn {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Availability badge */
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
    </style>
</head>
<body>
    <!-- Property Header -->
    <header class="property-header">
        <div class="header-container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Search
                </a>
                <h1 class="h4 mb-0">Property Details</h1>
                <div></div> <!-- Empty spacer for symmetry -->
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="property-container">
        <!-- Property Gallery -->
        <div class="property-gallery">
            <div class="main-image">
                <?php if (!empty($images)): ?>
                    <img src="../../uploads/<?= htmlspecialchars($images[0]['image_url']) ?>" 
                         alt="<?= htmlspecialchars($property['property_name']) ?>">
                <?php else: ?>
                    <img src="../../assets/images/default-property.jpg" 
                         alt="Default property image">
                <?php endif; ?>
            </div>
            <div class="side-images">
                <?php for ($i = 1; $i < min(4, count($images)); $i++): ?>
                    <div class="side-image">
                        <img src="../../uploads/<?= htmlspecialchars($images[$i]['image_url']) ?>" 
                             alt="Property image <?= $i ?>">
                    </div>
                <?php endfor; ?>
                <?php if (count($images) < 4): ?>
                    <?php for ($i = count($images); $i < 4; $i++): ?>
                        <div class="side-image bg-light d-flex align-items-center justify-content-center">
                          
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Property Info Grid -->
        <div class="property-info-grid">
            <div class="property-details">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1><?= htmlspecialchars($property['property_name']) ?></h1>
                    <div class="d-flex gap-2">
                        <span class="badge badge-primary"><?= htmlspecialchars($property['category_name']) ?></span>
                        <span class="badge badge-success">
                            <i class="fas fa-check-circle me-1"></i> Levy Paid
                        </span>
                    </div>
                </div>
                
                <div class="property-location mb-4">
                    <i class="fas fa-map-marker-alt text-primary"></i>
                    <span><?= htmlspecialchars($property['location']) ?></span>
                </div>
                
                <div class="d-flex align-items-center mb-4">
                    <div class="review-stars">
                        <?php for ($i = 0; $i < $fullStars; $i++): ?>
                            <i class="fas fa-star"></i>
                        <?php endfor; ?>
                        <?php if ($halfStar): ?>
                            <i class="fas fa-star-half-alt"></i>
                        <?php endif; ?>
                        <?php for ($i = 0; $i < $emptyStars; $i++): ?>
                            <i class="far fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <span class="ms-2">(<?= number_format($property['average_rating'], 1) ?>)</span>
                    <span class="ms-3"><?= count($reviews) ?> reviews</span>
                </div>
                
                <div class="property-price mb-4">
                    <h2 class="text-primary">GHS <?= number_format($property['price'], 2) ?> <span class="text-muted" style="font-size: 1rem;">/year (per student)</span></h2>
                </div>
                
                <div class="property-features mb-4">
                    <div class="property-feature">
                        <i class="fas fa-bed"></i>
                        <span><?= $property['bedrooms'] ?? 0 ?> Bedrooms</span>
                    </div>
                    <div class="property-feature">
                        <i class="fas fa-bath"></i>
                        <span><?= $property['bathrooms'] ?? 0 ?> Bathrooms</span>
                    </div>
                    <div class="property-feature">
                        <i class="fas fa-ruler-combined"></i>
                        <span><?= $property['area_sqft'] ? number_format($property['area_sqft']) : 'N/A' ?> sqft</span>
                    </div>
                </div>
                
                <div class="mb-4">
                    <h3>Description</h3>
                    <p><?= htmlspecialchars($property['description']) ?></p>
                </div>
                
                <div class="mb-4">
                    <h3>Amenities</h3>
                    <div class="amenities-grid">
                        <?php if (!empty($features)): ?>
                            <?php foreach ($features as $feature): ?>
                                <div class="amenity-item">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span><?= htmlspecialchars($feature) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No amenities listed</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Available Rooms -->
                <div class="mb-4">
                    <h3>Available Rooms</h3>
                    <?php if (!empty($rooms)): ?>
                        <?php foreach ($rooms as $room): 
                            // Calculate available spots
                            $available_spots = $room['capacity'] - $room['current_occupancy'];
                            
                            // Determine availability status
                            if ($available_spots == 0) {
                                $availability_class = "full";
                                $availability_text = "Fully Booked";
                            } elseif ($available_spots <= 2) {
                                $availability_class = "limited";
                                $availability_text = "Limited Availability";
                            } else {
                                $availability_class = "available";
                                $availability_text = "Available";
                            }
                        ?>
                            <div class="room-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4>Room <?= htmlspecialchars($room['room_number']) ?></h4>
                                    <span class="availability-badge <?= $availability_class ?>">
                                        <?= $availability_text ?>
                                    </span>
                                </div>
                                
                                <div class="d-flex gap-3 mt-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-friends me-2 text-muted"></i>
                                        <span>Capacity: <?= $room['capacity'] ?> students</span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-<?= $room['gender'] == 'male' ? 'mars' : 'venus' ?> me-2 text-muted"></i>
                                        <span>Gender: <?= ucfirst($room['gender']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="d-flex mt-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-clock me-2 text-muted"></i>
                                        <span>Occupancy: <?= $room['current_occupancy'] ?>/<?= $room['capacity'] ?></span>
                                    </div>
                                </div>
                                
                                <div class="price-per-student">
                                    GHS <?= number_format($property['price'], 2) ?> per student
                                </div>
                                
                                <div class="room-actions">
                                    <a href="../bookings/create.php?property_id=<?= $property_id ?>&room_id=<?= $room['id'] ?>" 
                                       class="btn btn-primary">
                                        <i class="far fa-calendar-plus me-2"></i> Book This Room
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            No rooms currently available
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Location Map -->
                <div class="mb-4">
                    <h3>Location</h3>
                    <div class="map-container" id="property-map"></div>
                </div>
                
                <!-- Reviews Section -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>Reviews</h3>
                        <a href="#" class="btn btn-outline">Write a Review</a>
                    </div>
                    
                    <?php if (!empty($reviews)): ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <?php if ($review['profile_picture']): ?>
                                        <img src="../../uploads/<?= htmlspecialchars($review['profile_picture']) ?>" 
                                             class="review-avatar" 
                                             alt="<?= htmlspecialchars($review['reviewer_name']) ?>">
                                    <?php else: ?>
                                        <div class="review-avatar">
                                            <?= strtoupper(substr($review['reviewer_name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h5><?= htmlspecialchars($review['reviewer_name']) ?></h5>
                                        <div class="text-muted">
                                            <?= date('M d, Y', strtotime($review['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="review-stars">
                                    <?php for ($i = 0; $i < $review['rating']; $i++): ?>
                                        <i class="fas fa-star"></i>
                                    <?php endfor; ?>
                                    <?php for ($i = $review['rating']; $i < 5; $i++): ?>
                                        <i class="far fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                                
                                <p><?= htmlspecialchars($review['comment']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No reviews yet. Be the first to review this property!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="property-booking">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="text-primary">GHS <?= number_format($property['price'], 2) ?></h2>
                    <span class="text-muted">/year (per student)</span>
                </div>
                
                <div class="d-flex flex-column gap-2 mb-4">
                    <a href="../bookings/create.php?property_id=<?= $property_id ?>" class="btn btn-primary">
                        <i class="far fa-calendar-plus me-2"></i> Book Now
                    </a>
                    <button class="btn btn-outline">
                        <i class="far fa-heart me-2"></i> Save to Favorites
                    </button>
                </div>
                
                <div class="mb-4">
                    <h4>Contact Owner</h4>
                    <div class="d-flex align-items-center mt-3">
                        <div class="review-avatar">
                            <?= strtoupper(substr($property['owner_name'], 0, 1)) ?>
                        </div>
                        <div class="ms-3">
                            <div class="fw-bold"><?= htmlspecialchars($property['owner_name']) ?></div>
                            <div class="text-muted">Property Owner</div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <a href="tel:<?= htmlspecialchars($property['owner_phone']) ?>" class="btn btn-outline w-100">
                            <i class="fas fa-phone me-2"></i> <?= htmlspecialchars($property['owner_phone']) ?>
                        </a>
                        <a href="mailto:<?= htmlspecialchars($property['owner_email']) ?>" class="btn btn-outline w-100 mt-2">
                            <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($property['owner_email']) ?>
                        </a>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Levy Payment Verified</h5>
                        <p class="card-text">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            This property has paid the required levy and is approved by the university.
                        </p>
                        <p class="card-text small text-muted">
                            All properties listed on UniHomes undergo a verification process to ensure compliance with university housing regulations.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <script>
        // Initialize Leaflet map
        function initMap() {
            <?php if ($property['latitude'] && $property['longitude']): ?>
                const map = L.map('property-map').setView([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                L.marker([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>])
                    .addTo(map)
                    .bindPopup('<?= addslashes($property['property_name']) ?>');
            <?php else: ?>
                // Default to Accra if no coordinates
                const map = L.map('property-map').setView([5.6037, -0.1870], 13);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
            <?php endif; ?>
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', initMap);
    </script>
</body>
</html>