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

// SIMPLIFIED: Remove status filter and rely on capacity calculations
$room_stmt = $pdo->prepare("
    SELECT pr.*, 
           -- Count confirmed bookings (actual occupants)
           (SELECT COUNT(*) 
            FROM bookings b 
            WHERE b.room_id = pr.id 
            AND b.status IN ('confirmed', 'paid', 'cash_approved')
            AND (b.cancelled_at IS NULL OR b.status != 'cancelled')
           ) AS confirmed_bookings,
           -- Count active pending bookings (these reserve spots temporarily)
           (SELECT COUNT(*) 
            FROM bookings b 
            WHERE b.room_id = pr.id 
            AND b.status IN ('pending', 'pending_payment')
            AND (b.cancelled_at IS NULL OR b.status != 'cancelled')
           ) AS pending_bookings,
           -- Calculate actual available capacity
           (pr.capacity - 
            (SELECT COUNT(*) 
             FROM bookings b 
             WHERE b.room_id = pr.id 
             AND b.status IN ('confirmed', 'paid', 'cash_approved')
             AND (b.cancelled_at IS NULL OR b.status != 'cancelled')
            )
           ) AS actual_physical_capacity
    FROM property_rooms pr 
    WHERE pr.property_id = ? 
    AND pr.levy_payment_status = 'approved'
    AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
    ORDER BY pr.room_number ASC
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
$average_rating = $property['average_rating'] ?? 0;
$fullStars = floor($average_rating);
$halfStar = ceil($average_rating - $fullStars);
$emptyStars = 5 - $fullStars - $halfStar;

// ========== VIRTUAL TOURS COMPONENT ==========
// Get the property owner
$owner_stmt = $pdo->prepare("SELECT DISTINCT po.owner_id, u.username 
                            FROM property_owners po
                            JOIN users u ON po.owner_id = u.id
                            WHERE po.property_id = ?
                            LIMIT 1");
$owner_stmt->execute([$property_id]);
$property_owner = $owner_stmt->fetch();

// Get owner's videos
$owner_videos = [];
if ($property_owner) {
    $videos_stmt = $pdo->prepare("SELECT * FROM property_owner_videos 
                                WHERE owner_id = ? AND status = 'active'
                                ORDER BY is_featured DESC, created_at DESC
                                LIMIT 5");
    $videos_stmt->execute([$property_owner['owner_id']]);
    $owner_videos = $videos_stmt->fetchAll();
}
// ========== END VIRTUAL TOURS COMPONENT ==========

// PERFECTED: Calculate total available spots for the property
$total_available_spots = 0;
$total_confirmed_bookings = 0;
$total_pending_bookings = 0;
$total_cancelled_bookings = 0;

foreach ($rooms as $room) {
    // PERFECTED: Calculate available spots properly
    // Cancelled bookings free up spots, pending bookings reserve spots
    $available_spots = $room['actual_physical_capacity'] - $room['pending_bookings'];
    $total_available_spots += max(0, $available_spots);
    $total_confirmed_bookings += $room['confirmed_bookings'];
    $total_pending_bookings += $room['pending_bookings'];
  
}
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
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

        .property-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 15px;
        }

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

        .map-container {
            height: 500px;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin: 2rem 0;
            position: relative;
        }

        #directions-panel {
            position: absolute;
            top: 10px;
            right: 10px;
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            max-height: 400px;
            overflow-y: auto;
            width: 300px;
            z-index: 1000;
            display: none;
        }

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

        .contact-owner {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin: 2rem 0;
        }

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

        .price-per-student {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-top: 0.5rem;
        }
        
        .room-actions {
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
        }

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

            #directions-panel {
                position: relative;
                width: 100%;
                max-height: 200px;
                margin-bottom: 1rem;
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
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        .capacity-info {
            background-color: #e3f2fd;
            border-left: 3px solid var(--primary-color);
            padding: 0.75rem;
            margin: 1rem 0;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            font-size: 0.9rem;
        }
        
        .booking-count {
            display: inline-block;
            background-color: var(--info-color);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-right: 0.5rem;
        }
        
        .available-count {
            font-weight: 600;
            color: var(--success-color);
        }

        .property-tours-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .property-tours-card .card-header {
            background: linear-gradient(135deg, #3498db, #2980b9) !important;
            padding: 1.25rem;
        }

        .video-tour-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .video-tour-item {
            cursor: pointer;
            transition: transform 0.3s;
        }

        .video-tour-item:hover {
            transform: translateY(-5px);
        }

        .video-thumbnail {
            position: relative;
            height: 180px;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
        }

        .video-thumbnail video,
        .video-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-play-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .video-tour-item:hover .video-play-overlay {
            opacity: 1;
        }

        .play-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: transform 0.3s;
        }

        .play-button:hover {
            transform: scale(1.1);
        }

        .featured-tag {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ffc107;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .video-type-tag {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #3498db;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .video-tour-info {
            padding: 1rem 0.5rem;
        }

        .video-tour-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-size: 1rem;
        }

        .video-tour-desc {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .video-tour-grid {
                grid-template-columns: 1fr;
            }
        }

        .location-controls {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 1000;
            display: flex;
            gap: 0.5rem;
        }

        .location-btn {
            background: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .location-btn:hover {
            background: #f8f9fa;
        }

        .distance-info {
            background: white;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-top: 0.5rem;
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
                <div></div>
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
                    <span class="ms-2">(<?= number_format($average_rating, 1) ?>)</span>
                    <span class="ms-3"><?= count($reviews) ?> reviews</span>
                </div>
                
                <div class="property-price mb-4">
                    <h2 class="text-primary">GHS <?= number_format($property['price'], 2) ?> <span class="text-muted" style="font-size: 1rem;">/year (per tenant)</span></h2>
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
                
                <!-- ========== VIRTUAL TOURS SECTION ========== -->
                <?php if (!empty($owner_videos)): ?>
                <div class="card property-tours-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-video me-2"></i>Virtual Property Tours
                        </h5>
                        <small>Explore this property from <?= htmlspecialchars($property_owner['username']) ?></small>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Take a virtual tour!</strong> Watch these videos to see what the property looks like before you book.
                        </div>
                        
                        <div class="video-tour-grid">
                            <?php foreach ($owner_videos as $index => $video): ?>
                                <div class="video-tour-item">
                                    <div class="video-thumbnail" onclick="openVideoModal(<?= $index ?>, '<?= htmlspecialchars($video['video_path']) ?>', '<?= htmlspecialchars($video['video_title']) ?>', <?= $video['id'] ?>)">
                                        <?php if ($video['thumbnail_path']): ?>
                                            <img src="../../<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Video Thumbnail">
                                        <?php else: ?>
                                            <video src="../../<?= htmlspecialchars($video['video_path']) ?>" muted></video>
                                        <?php endif; ?>
                                        
                                        <div class="video-play-overlay">
                                            <div class="play-button">
                                                <i class="fas fa-play"></i>
                                            </div>
                                        </div>
                                        
                                        <?php if ($video['is_featured']): ?>
                                            <span class="featured-tag">
                                                <i class="fas fa-star"></i> Featured
                                            </span>
                                        <?php endif; ?>
                                        
                                        <span class="video-type-tag">
                                            <?= ucfirst(str_replace('_', ' ', $video['video_type'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="video-tour-info">
                                        <h6 class="video-tour-title"><?= htmlspecialchars($video['video_title']) ?></h6>
                                        <?php if ($video['video_description']): ?>
                                            <p class="video-tour-desc"><?= htmlspecialchars(substr($video['video_description'], 0, 80)) ?><?= strlen($video['video_description']) > 80 ? '...' : '' ?></p>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <i class="fas fa-eye me-1"></i><?= number_format($video['views_count']) ?> views
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- ========== END VIRTUAL TOURS SECTION ========== -->
                
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
                    
                    <!-- Property Summary -->
                    <div class="capacity-info mb-3">
                        <div class="d-flex align-items-center mb-2">
                            <span class="booking-count">
                                <i class="fas fa-bookmark"></i> <?= $total_confirmed_bookings ?> confirmed
                            </span>
                            <?php if ($total_pending_bookings > 0): ?>
                                <span class="booking-count" style="background-color: var(--warning-color);">
                                    <i class="fas fa-clock"></i> <?= $total_pending_bookings ?> pending
                                </span>
                            <?php endif; ?>
                            <?php if ($total_cancelled_bookings > 0): ?>
                                <span class="booking-count" style="background-color: var(--accent-color);">
                                    <i class="fas fa-times"></i> <?= $total_cancelled_bookings ?> cancelled
                                </span>
                            <?php endif; ?>
                            <span class="available-count ms-3">
                                <i class="fas fa-user-friends"></i> <?= $total_available_spots ?> spots available
                            </span>
                        </div>
                    </div>
                    
                    <?php if (!empty($rooms)): ?>
                        <?php 
                        $has_available_rooms = false;
                        foreach ($rooms as $room): 
                            // Calculate available spots properly
                            // Cancelled bookings free up spots (already freed in actual_physical_capacity)
                            // Pending bookings reserve spots (need to subtract these)
                            $actual_available = max(0, $room['actual_physical_capacity'] - $room['pending_bookings']);
                            
                            // Determine availability status
                            if ($actual_available == 0) {
                                $availability_class = "full";
                                $availability_text = "Fully Booked";
                            } elseif ($actual_available <= 2) {
                                $availability_class = "limited";
                                $availability_text = "Limited Availability";
                                $has_available_rooms = true;
                            } else {
                                $availability_class = "available";
                                $availability_text = "Available";
                                $has_available_rooms = true;
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
                                        <span>Capacity: <?= $room['capacity'] ?> Tenants</span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-<?= $room['gender'] == 'male' ? 'mars' : 'venus' ?> me-2 text-muted"></i>
                                        <span>Gender: <?= ucfirst($room['gender'] ?? 'Any') ?></span>
                                    </div>
                                </div>
                                
                                <div class="d-flex mt-2">
                                    <div class="d-flex align-items-center flex-wrap">
                                        <i class="fas fa-users me-2 text-muted"></i>
                                        <span><strong>Occupied:</strong> <?= $room['confirmed_bookings'] ?>/<?= $room['capacity'] ?></span>
                                        <span class="ms-3 text-muted">|</span>
                                        <span class="ms-3"><strong>Available:</strong> <span class="text-success"><?= $actual_available ?></span></span>
                                        <?php if ($room['pending_bookings'] > 0): ?>
                                            <span class="text-warning ms-3">(<?= $room['pending_bookings'] ?> pending)</span>
                                        <?php endif; ?>
                                        
                                    </div>
                                </div>
                                
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> 
                                        When bookings are cancelled, spots become immediately available for new bookings
                                    </small>
                                </div>
                                
                                <div class="price-per-student">
                                    GHS <?= number_format($property['price'], 2) ?> per tenant/year
                                </div>
                                
                                <div class="room-actions">
                                    <?php if ($actual_available > 0): ?>
                                        <a href="../bookings/create.php?property_id=<?= $property_id ?>&room_id=<?= $room['id'] ?>" 
                                           class="btn btn-primary">
                                            <i class="far fa-calendar-plus me-2"></i> Book This Room
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary" disabled>
                                            <i class="fas fa-times me-2"></i> Fully Booked
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (!$has_available_rooms): ?>
                            <div class="alert alert-warning">
                                All rooms are currently fully booked. Please check back later.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            No rooms currently available for this property.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Location Map -->
                <div class="mb-4">
                    <h3>Location & Directions</h3>
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Real-time Directions:</strong> Get live directions from your current location to this property. 
                        The map will automatically detect your location and show the best route.
                    </div>
                    <div class="map-container" id="property-map">
                        <div class="location-controls">
                            <button class="location-btn" onclick="getUserLocation()">
                                <i class="fas fa-location-arrow"></i> My Location
                            </button>
                            <button class="location-btn" onclick="showDirections()">
                                <i class="fas fa-directions"></i> Get Directions
                            </button>
                        </div>
                        <div id="directions-panel"></div>
                    </div>
                    <div id="distance-info" class="distance-info" style="display: none;">
                        <i class="fas fa-route text-primary me-2"></i>
                        <span id="distance-text"></span>
                    </div>
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
                    <span class="text-muted">/year (per tenant)</span>
                </div>
                
                <!-- Property Availability Summary -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Availability Summary</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Total Available Spots:</span>
                            <strong class="<?= $total_available_spots > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $total_available_spots ?>
                            </strong>
                        </div>
                        <?php if ($total_pending_bookings > 0): ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span>Pending Bookings:</span>
                                <strong class="text-warning"><?= $total_pending_bookings ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span>Confirmed Bookings:</span>
                            <strong class="text-info"><?= $total_confirmed_bookings ?></strong>
                        </div>
                        <?php if ($total_cancelled_bookings > 0): ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span>Cancelled Bookings:</span>
                                <strong class="text-success"><?= $total_cancelled_bookings ?> (spots freed)</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="d-flex flex-column gap-2 mb-4">
                    <?php if ($total_available_spots > 0): ?>
                        <a href="../bookings/create.php?property_id=<?= $property_id ?>" class="btn btn-primary">
                            <i class="far fa-calendar-plus me-2"></i> Book Now
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-times me-2"></i> Fully Booked
                        </button>
                    <?php endif; ?>
                    
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

    <!-- ========== VIDEO MODAL ========== -->
    <?php if (!empty($owner_videos)): ?>
    <div class="modal fade" id="propertyVideoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="videoModalTitle">Virtual Tour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <video id="propertyVideoPlayer" controls style="width: 100%; max-height: 70vh; background: #000;">
                        <source id="propertyVideoSource" src="" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="nextVideoBtn">
                        Next Video <i class="fas fa-forward ms-2"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script>
        let map;
        let userLocation = null;
        let propertyLocation = null;
        let routingControl = null;
        let userMarker = null;
        let propertyMarker = null;
        
        // Calculate distance between two points using Haversine formula
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Radius of the Earth in kilometers
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            const distance = R * c; // Distance in kilometers
            return distance;
        }
        
        // Format distance for display
        function formatDistance(distance) {
            if (distance < 1) {
                return Math.round(distance * 1000) + ' meters';
            } else {
                return distance.toFixed(2) + ' km';
            }
        }
        
        // Format time for display
        function formatTime(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            
            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            } else {
                return `${minutes} minutes`;
            }
        }
        
        // Get user's current location with real-time tracking
        function getUserLocation() {
            if (navigator.geolocation) {
                // Clear any existing location watch
                if (window.locationWatchId) {
                    navigator.geolocation.clearWatch(window.locationWatchId);
                }
                
                // Get current position first
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        updateUserLocation(position);
                        
                        // Then watch for position changes
                        window.locationWatchId = navigator.geolocation.watchPosition(
                            updateUserLocation,
                            handleLocationError,
                            {
                                enableHighAccuracy: true,
                                timeout: 10000,
                                maximumAge: 30000 // 30 seconds
                            }
                        );
                    },
                    handleLocationError,
                    {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 300000 // 5 minutes
                    }
                );
            } else {
                showLocationError('Geolocation is not supported by this browser.');
            }
        }
        
        // Update user location on map
        function updateUserLocation(position) {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            
            // Remove existing user marker
            if (userMarker) {
                map.removeLayer(userMarker);
            }
            
            // Add user location marker with pulsing effect
            userMarker = L.marker([userLocation.lat, userLocation.lng], {
                icon: L.icon({
                    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                })
            }).addTo(map);
            
            userMarker.bindPopup(`
                <div style="min-width: 200px;">
                    <h6><b>Your Current Location</b></h6>
                    <p class="mb-2"><i class="fas fa-map-marker-alt text-primary"></i> Real-time tracking active</p>
                    <div class="d-grid">
                        <button class="btn btn-sm btn-primary" onclick="showDirections()">
                            <i class="fas fa-directions"></i> Update Directions
                        </button>
                    </div>
                </div>
            `).openPopup();
            
            // Fit map to show both locations
            if (propertyLocation) {
                const group = L.featureGroup([userMarker, propertyMarker]);
                map.fitBounds(group.getBounds().pad(0.1));
                
                // Calculate and display distance
                const distance = calculateDistance(
                    userLocation.lat, userLocation.lng,
                    propertyLocation.lat, propertyLocation.lng
                );
                
                document.getElementById('distance-text').textContent = 
                    `Distance: ${formatDistance(distance)} from your location`;
                document.getElementById('distance-info').style.display = 'block';
                
                // Auto-show directions if user moves significantly
                if (window.previousUserLocation) {
                    const prevDistance = calculateDistance(
                        window.previousUserLocation.lat, window.previousUserLocation.lng,
                        userLocation.lat, userLocation.lng
                    );
                    
                    if (prevDistance > 0.1) { // If moved more than 100 meters
                        setTimeout(showDirections, 1000);
                    }
                }
                
                window.previousUserLocation = { ...userLocation };
            }
        }
        
        // Handle location errors
        function handleLocationError(error) {
            let errorMessage = 'Unable to get your location. ';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMessage += 'Please allow location access to get real-time directions.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMessage += 'Location information is unavailable.';
                    break;
                case error.TIMEOUT:
                    errorMessage += 'Location request timed out.';
                    break;
            }
            showLocationError(errorMessage);
        }
        
        function showLocationError(message) {
            const errorPopup = L.popup()
                .setLatLng(propertyLocation ? [propertyLocation.lat, propertyLocation.lng] : [5.6037, -0.1870])
                .setContent(`<div class="alert alert-warning mb-0"><i class="fas fa-exclamation-triangle"></i> ${message}</div>`)
                .openOn(map);
        }
        
        // Show real-time directions between user location and property
        function showDirections() {
            if (!userLocation || !propertyLocation) {
                alert('Unable to calculate directions. Please ensure location access is enabled and try "My Location" first.');
                return;
            }
            
            // Remove existing routing control
            if (routingControl) {
                map.removeControl(routingControl);
            }
            
            // Calculate distance
            const distance = calculateDistance(
                userLocation.lat, userLocation.lng,
                propertyLocation.lat, propertyLocation.lng
            );
            
            // Add routing control with real-time updates
            routingControl = L.Routing.control({
                waypoints: [
                    L.latLng(userLocation.lat, userLocation.lng),
                    L.latLng(propertyLocation.lat, propertyLocation.lng)
                ],
                routeWhileDragging: false,
                addWaypoints: false,
                createMarker: function() { return null; }, // Don't create additional markers
                lineOptions: {
                    styles: [{ color: '#3498db', weight: 6, opacity: 0.8 }]
                },
                show: true,
                collapsible: true,
                fitSelectedRoutes: true,
                showAlternatives: false
            }).addTo(map);
            
            // Update directions panel
            routingControl.on('routesfound', function(e) {
                const routes = e.routes;
                const summary = routes[0].summary;
                
                document.getElementById('distance-text').innerHTML = `
                    <strong>Route Summary:</strong><br>
                    Distance: ${formatDistance(summary.totalDistance / 1000)}<br>
                    Time: ${formatTime(summary.totalTime)}<br>
                    <small class="text-muted">Directions update automatically as you move</small>
                `;
                document.getElementById('distance-info').style.display = 'block';
            });
            
            // Update property marker popup
            propertyMarker.setPopupContent(`
                <div style="min-width: 250px;">
                    <h6><b><?= addslashes($property['property_name']) ?></b></h6>
                    <p class="mb-2"><i class="fas fa-map-marker-alt text-primary"></i> <?= addslashes($property['location']) ?></p>
                    <div class="mb-2">
                        <i class="fas fa-route text-success"></i> 
                        <strong>Real-time directions active</strong>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-sm btn-primary" onclick="toggleDirectionsPanel()">
                            <i class="fas fa-list"></i> Toggle Directions
                        </button>
                        <a href="https://www.google.com/maps/dir/?api=1&origin=${userLocation.lat},${userLocation.lng}&destination=${propertyLocation.lat},${propertyLocation.lng}" 
                           target="_blank" class="btn btn-sm btn-success">
                            <i class="fab fa-google"></i> Open in Google Maps
                        </a>
                        <button class="btn btn-sm btn-info" onclick="getUserLocation()">
                            <i class="fas fa-sync-alt"></i> Update My Location
                        </button>
                    </div>
                </div>
            `);
            
            propertyMarker.openPopup();
        }
        
        // Toggle directions panel visibility
        function toggleDirectionsPanel() {
            const directionsPanel = document.getElementById('directions-panel');
            if (directionsPanel.style.display === 'none') {
                directionsPanel.style.display = 'block';
            } else {
                directionsPanel.style.display = 'none';
            }
        }
        
        // Initialize Leaflet map
        function initMap() {
            <?php if ($property['latitude'] && $property['longitude']): ?>
                propertyLocation = {
                    lat: <?= $property['latitude'] ?>,
                    lng: <?= $property['longitude'] ?>
                };
                
                map = L.map('property-map').setView([propertyLocation.lat, propertyLocation.lng], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                // Property marker with custom red icon
                window.propertyMarker = L.marker([propertyLocation.lat, propertyLocation.lng], {
                    icon: L.icon({
                        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41]
                    })
                }).addTo(map);
                
                // Initial popup content
                propertyMarker.bindPopup(`
                    <div style="min-width: 200px;">
                        <h6><b><?= addslashes($property['property_name']) ?></b></h6>
                        <p class="mb-2"><i class="fas fa-map-marker-alt text-primary"></i> <?= addslashes($property['location']) ?></p>
                        <div class="d-grid gap-2">
                            <button class="btn btn-sm btn-primary" onclick="getUserLocation()">
                                <i class="fas fa-location-arrow"></i> Start Real-time Directions
                            </button>
                            <small class="text-muted mt-2 d-block">Click to get live directions from your current location</small>
                        </div>
                    </div>
                `);
                
                // Auto-get user location after a short delay
                setTimeout(getUserLocation, 2000);
                
            <?php else: ?>
                // Default to Accra if no coordinates
                map = L.map('property-map').setView([5.6037, -0.1870], 13);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                // Show message that location is not available
                L.popup()
                    .setLatLng([5.6037, -0.1870])
                    .setContent('<div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Property location coordinates not available</div>')
                    .openOn(map);
            <?php endif; ?>
        }

        <?php if (!empty($owner_videos)): ?>
        let currentVideoIndex = 0;
        let videoPlaylist = <?= json_encode(array_map(function($v) {
            return [
                'path' => $v['video_path'],
                'title' => $v['video_title'],
                'id' => $v['id']
            ];
        }, $owner_videos)) ?>;

        const videoModal = new bootstrap.Modal(document.getElementById('propertyVideoModal'));
        const videoPlayer = document.getElementById('propertyVideoPlayer');
        const videoSource = document.getElementById('propertyVideoSource');
        const videoModalTitle = document.getElementById('videoModalTitle');
        const nextVideoBtn = document.getElementById('nextVideoBtn');

        function openVideoModal(index, videoPath, title, videoId) {
            currentVideoIndex = index;
            videoSource.src = '../../' + videoPath;
            videoModalTitle.textContent = title;
            videoPlayer.load();
            videoModal.show();
            videoPlayer.play();
            
            trackVideoView(videoId);
            
            if (currentVideoIndex >= videoPlaylist.length - 1) {
                nextVideoBtn.style.display = 'none';
            } else {
                nextVideoBtn.style.display = 'inline-block';
            }
        }

        function playNextVideo() {
            if (currentVideoIndex < videoPlaylist.length - 1) {
                currentVideoIndex++;
                const nextVideo = videoPlaylist[currentVideoIndex];
                openVideoModal(currentVideoIndex, nextVideo.path, nextVideo.title, nextVideo.id);
            }
        }

        if (nextVideoBtn) {
            nextVideoBtn.addEventListener('click', playNextVideo);
        }

        document.getElementById('propertyVideoModal').addEventListener('hidden.bs.modal', function () {
            videoPlayer.pause();
            videoPlayer.currentTime = 0;
        });

        function trackVideoView(videoId) {
            fetch('track_video_view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ video_id: videoId })
            }).catch(err => console.log('Error tracking view:', err));
        }
        <?php endif; ?>

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', initMap);
        
        // Make functions globally available
        window.showDirections = showDirections;
        window.toggleDirectionsPanel = toggleDirectionsPanel;
        window.getUserLocation = getUserLocation;
        <?php if (!empty($owner_videos)): ?>
        window.openVideoModal = openVideoModal;
        <?php endif; ?>
    </script>
</body>
</html>
