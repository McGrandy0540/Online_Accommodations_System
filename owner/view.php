<?php
// properties/view.php - Property View Page
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if property ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../');
    exit();
}

$property_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');

// Get property details
$property_stmt = $pdo->prepare("
    SELECT p.*, u.username as owner_name, u.email as owner_email, 
           u.phone_number as owner_phone, c.name as category_name,
           (SELECT AVG(rating) FROM reviews WHERE property_id = p.id) as average_rating,
           (SELECT COUNT(*) FROM reviews WHERE property_id = p.id) as review_count
    FROM property p
    JOIN users u ON p.owner_id = u.id
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ? AND p.deleted = 0
");
$property_stmt->execute([$property_id]);
$property = $property_stmt->fetch();

if (!$property) {
    header('Location: ../');
    exit();
}

// Check if user is property owner (for edit/delete options)
$is_owner = ($_SESSION['user_id'] == $property['owner_id'] && $_SESSION['status'] == 'property_owner');

// Get property images
$images_stmt = $pdo->prepare("SELECT * FROM property_images WHERE property_id = ? ORDER BY id");
$images_stmt->execute([$property_id]);
$images = $images_stmt->fetchAll();

// Get property features
$features_stmt = $pdo->prepare("SELECT * FROM property_features WHERE property_id = ?");
$features_stmt->execute([$property_id]);
$features = $features_stmt->fetchAll();

// Get available rooms
$rooms_stmt = $pdo->prepare("
    SELECT * FROM property_rooms 
    WHERE property_id = ? AND status = 'available'
    ORDER BY room_number
");
$rooms_stmt->execute([$property_id]);
$available_rooms = $rooms_stmt->fetchAll();

// Get reviews with user info
$reviews_stmt = $pdo->prepare("
    SELECT r.*, u.username, u.profile_picture
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.property_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$reviews_stmt->execute([$property_id]);
$reviews = $reviews_stmt->fetchAll();

// Check if user has already reviewed this property
$user_reviewed = false;
if (isset($_SESSION['user_id'])) {
    $review_check = $pdo->prepare("SELECT 1 FROM reviews WHERE property_id = ? AND user_id = ?");
    $review_check->execute([$property_id, $_SESSION['user_id']]);
    $user_reviewed = $review_check->fetchColumn();
}

// Get similar properties (same category, same location)
$similar_stmt = $pdo->prepare("
    SELECT p.*, 
           (SELECT image_url FROM property_images WHERE property_id = p.id LIMIT 1) as thumbnail,
           (SELECT AVG(rating) FROM reviews WHERE property_id = p.id) as avg_rating
    FROM property p
    WHERE p.category_id = ? AND p.location LIKE ? AND p.id != ? AND p.deleted = 0
    ORDER BY p.created_at DESC
    LIMIT 3
");
$similar_stmt->execute([
    $property['category_id'],
    '%' . $property['location'] . '%',
    $property_id
]);
$similar_properties = $similar_stmt->fetchAll();

// Get profile picture path
$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($property['property_name']) ?> | Landlords&Tenant</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />

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
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            padding-top: var(--header-height);
        }
        
        .property-header {
            background-color: white;
            padding: 2rem 0;
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }
        
        .property-title {
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }
        
        .property-location {
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .property-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .property-price-period {
            font-size: 1rem;
            color: #6c757d;
        }
        
        .property-rating {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .rating-stars {
            color: var(--warning-color);
            margin-right: 0.5rem;
        }
        
        .rating-count {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .property-badge {
            background-color: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-right: 0.5rem;
            display: inline-block;
        }
        
        .property-main-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .property-thumbnail {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .property-thumbnail:hover {
            transform: scale(1.02);
            box-shadow: var(--box-shadow);
        }
        
        .property-thumbnail.active {
            border: 3px solid var(--primary-color);
        }
        
        .property-section {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }
        
        .section-title {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--primary-color);
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .feature-item {
            background-color: var(--light-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        .feature-item i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .room-card {
            border: 1px solid #eee;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
            border-color: var(--primary-color);
        }
        
        .room-number {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }
        
        .room-capacity {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .room-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-available {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-occupied {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-maintenance {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .owner-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            text-align: center;
        }
        
        .owner-avatar {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--primary-color);
            margin: 0 auto 1rem;
        }
        
        .owner-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .owner-contact {
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .contact-btn {
            width: 100%;
            margin-bottom: 0.5rem;
        }
        
        .review-card {
            border: 1px solid #eee;
            border-radius: var(--border-radius);
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
            object-fit: cover;
            border-radius: 50%;
            margin-right: 1rem;
        }
        
        .review-user {
            font-weight: 600;
        }
        
        .review-date {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .review-rating {
            color: var(--warning-color);
            margin-bottom: 0.5rem;
        }
        
        .review-text {
            color: #495057;
        }
        
        .similar-property {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }
        
        .similar-property:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }
        
        .similar-property-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .similar-property-body {
            padding: 1rem;
        }
        
        .similar-property-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .similar-property-price {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .similar-property-rating {
            color: var(--warning-color);
            font-size: 0.9rem;
        }
        
        .booking-form {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--card-shadow);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-danger {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
            border-color: #c0392b;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            font-weight: 500;
        }
        
        .nav-tabs .nav-link {
            color: var(--secondary-color);
            border: none;
            padding: 0.5rem 1rem;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: var(--primary-color);
        }
        
        /* Image gallery modal */
        .gallery-modal .modal-dialog {
            max-width: 90%;
            max-height: 90vh;
        }
        
        .gallery-modal .modal-body {
            padding: 0;
        }
        
        .gallery-modal-img {
            width: 100%;
            height: 70vh;
            object-fit: contain;
        }
        
        .gallery-thumbnails {
            display: flex;
            overflow-x: auto;
            padding: 1rem;
            gap: 0.5rem;
        }
        
        .gallery-thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .gallery-thumbnail.active {
            border-color: var(--primary-color);
        }
        
        /* Floating action button for mobile */
        .floating-action-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
        }
        
        /* Mobile Styles */
        @media (max-width: 992px) {
            .property-main-image {
                height: 300px;
            }
            
            .property-header {
                padding: 1.5rem 0;
            }
            
            .property-section {
                padding: 1.5rem;
            }
            
            .room-card {
                padding: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .property-main-image {
                height: 250px;
            }
            
            .property-header {
                padding: 1rem 0;
            }
            
            .property-section {
                padding: 1rem;
            }
            
            .owner-avatar {
                width: 80px;
                height: 80px;
            }
            
            .floating-action-btn {
                display: flex;
            }
            
            .room-card {
                margin-bottom: 1rem;
            }
            
            .similar-property {
                margin-bottom: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .property-main-image {
                height: 200px;
            }
            
            .property-title {
                font-size: 1.5rem;
            }
            
            .property-price {
                font-size: 1.25rem;
            }
            
            .property-section {
                padding: 1rem 0.75rem;
            }
            
            .feature-item {
                width: 100%;
            }
            
            .room-card {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .room-select-btn {
                font-size: 0.8rem;
                padding: 0.25rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="container">
            <nav class="navbar navbar-expand-lg navbar-light bg-white">
                <div class="container-fluid">
                    <a class="navbar-brand" href="dashboard.php">
                        <img src="../assets/images/logo-removebg-preview.png" alt="UniHomes Logo" height="40">
                        <span class="ms-2 fw-bold">Landlords&Tenant</span>
                    </a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav ms-auto">
                            <li class="nav-item">
                                <a class="nav-link" href="dashboard.php">Home</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="property_dashboard.php">Properties</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../index.php">About</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../index.php">Contact</a>
                            </li>
                            <?php if(isset($_SESSION['user_id'])): ?>
                                <li class="nav-item dropdown">
                                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                        <?php if (!empty($profile_pic_path)): ?>
                                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="rounded-circle" width="30" height="30" alt="Profile">
                                        <?php else: ?>
                                            <span class="badge bg-primary rounded-circle p-2">
                                                <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="ms-1 d-none d-lg-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form action="logout.php" method="POST">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </li>
                            <?php else: ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="../auth/login.php">Login</a>
                                </li>
                                <li class="nav-item">
                                    <a class="btn btn-primary ms-2" href="../auth/register.php">Register</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Property Header -->
    <div class="property-header">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h1 class="property-title"><?= htmlspecialchars($property['property_name']) ?></h1>
                    <div class="property-location">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($property['location']) ?>
                    </div>
                    <div class="d-flex align-items-center mb-3">
                        <span class="property-badge"><?= htmlspecialchars($property['category_name']) ?></span>
                        <?php if($property['approved']): ?>
                            <span class="property-badge" style="background-color: var(--success-color);">
                                <i class="fas fa-check-circle me-1"></i> Verified
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="property-rating">
                        <div class="rating-stars">
                            <?php
                            $rating = round($property['average_rating'] ?? 0);
                            for ($i = 1; $i <= 5; $i++) {
                                echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                            }
                            ?>
                        </div>
                        <span class="rating-count">(<?= $property['review_count'] ?? 0 ?> reviews)</span>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="property-price">
                        GHS <?= number_format($property['price'], 2) ?>
                        <span class="property-price-period">/ year</span>
                    </div>
                    <?php if(!$is_owner): ?>
                        <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#bookingModal">
                            <i class="fas fa-calendar-check me-1"></i> Book Now
                        </button>
                    <?php else: ?>
                        <div class="d-flex justify-content-end gap-2 mt-2">
                            <a href="edit.php?id=<?= $property['id'] ?>" class="btn btn-outline-primary">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mb-5">
        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Image Gallery -->
                <div class="property-section">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <?php if(!empty($images)): ?>
                                <img src="../uploads/<?= htmlspecialchars($images[0]['image_url']) ?>" 
                                     class="property-main-image" 
                                     id="mainImage"
                                     alt="<?= htmlspecialchars($property['property_name']) ?>">
                            <?php else: ?>
                                <img src="../../assets/images/default-property.jpg" 
                                     class="property-main-image" 
                                     alt="Default property image">
                            <?php endif; ?>
                        </div>
                        
                        <?php if(count($images) > 1): ?>
                            <div class="col-md-12">
                                <div class="row">
                                    <?php foreach($images as $index => $image): ?>
                                        <div class="col-4 col-md-3 mb-3">
                                            <img src="../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                                 class="property-thumbnail <?= $index === 0 ? 'active' : '' ?>" 
                                                 onclick="changeMainImage(this, '../uploads/<?= htmlspecialchars($image['image_url']) ?>')"
                                                 alt="Property thumbnail <?= $index + 1 ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="col-md-12 text-center mt-2">
                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#galleryModal">
                                <i class="fas fa-images me-1"></i> View All Photos
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Property Description -->
                <div class="property-section">
                    <h3 class="section-title">Description</h3>
                    <p><?= nl2br(htmlspecialchars($property['description'])) ?></p>
                </div>
                
                <!-- Property Features -->
                <div class="property-section">
                    <h3 class="section-title">Features</h3>
                    <ul class="feature-list">
                        <?php if($property['bedrooms']): ?>
                            <li class="feature-item">
                                <i class="fas fa-bed"></i> <?= $property['bedrooms'] ?> Bedrooms
                            </li>
                        <?php endif; ?>
                        <?php if($property['bathrooms']): ?>
                            <li class="feature-item">
                                <i class="fas fa-bath"></i> <?= $property['bathrooms'] ?> Bathrooms
                            </li>
                        <?php endif; ?>
                        <?php if($property['area_sqft']): ?>
                            <li class="feature-item">
                                <i class="fas fa-ruler-combined"></i> <?= number_format($property['area_sqft']) ?> sqft
                            </li>
                        <?php endif; ?>
                        <?php if($property['year_built']): ?>
                            <li class="feature-item">
                                <i class="fas fa-calendar-alt"></i> Built in <?= $property['year_built'] ?>
                            </li>
                        <?php endif; ?>
                        <?php if($property['parking']): ?>
                            <li class="feature-item">
                                <i class="fas fa-car"></i> <?= htmlspecialchars($property['parking']) ?>
                            </li>
                        <?php endif; ?>
                        <?php foreach($features as $feature): ?>
                            <li class="feature-item">
                                <i class="fas fa-check"></i> <?= htmlspecialchars($feature['feature_name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Available Rooms -->
                <div class="property-section">
                    <h3 class="section-title">Available Rooms</h3>
                    <?php if(count($available_rooms) > 0): ?>
                        <div class="row">
                            <?php foreach($available_rooms as $room): ?>
                                <div class="col-md-6">
                                    <div class="room-card">
                                        <h5 class="room-number">Room <?= htmlspecialchars($room['room_number']) ?></h5>
                                        <p class="room-capacity">
                                            <i class="fas fa-user-friends"></i> Capacity: <?= $room['capacity'] ?> person(s)
                                        </p>
                                        <span class="room-status status-available">
                                            <i class="fas fa-check-circle"></i> Available
                                        </span>
                                        <?php if(!$is_owner): ?>
                                            <button class="btn btn-primary btn-sm mt-3" 
                                                    onclick="bookRoom(<?= $room['id'] ?>, '<?= htmlspecialchars($room['room_number']) ?>')">
                                                <i class="fas fa-bookmark me-1"></i> Book This Room
                                            </button>
                                        <?php else: ?>
                                            <div class="d-flex gap-2 mt-3">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="viewRoomDetails(<?= $room['id'] ?>, '<?= htmlspecialchars($room['room_number']) ?>')"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#roomDetailsModal">
                                                    <i class="fas fa-eye me-1"></i> View Details
                                                </button>
                                                <button class="btn btn-success btn-sm room-select-btn" 
                                                        onclick="selectRoomForCashPayment(
                                                            <?= $room['id'] ?>, 
                                                            '<?= htmlspecialchars($room['room_number']) ?>', 
                                                            <?= $room['capacity'] ?>, 
                                                            '<?= $room['gender'] ?>'
                                                        )"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#cashPaymentModal">
                                                    <i class="fas fa-money-bill-wave me-1"></i> Record Payment
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> 
                            Currently there are no available rooms in this property.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Reviews -->
                <div class="property-section">
                    <h3 class="section-title">Reviews</h3>
                    
                    <?php if(count($reviews) > 0): ?>
                        <?php foreach($reviews as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <?php if(!empty($review['profile_picture'])): ?>
                                        <img src="<?= getProfilePicturePath($review['profile_picture']) ?>" 
                                             class="review-avatar" 
                                             alt="<?= htmlspecialchars($review['username']) ?>">
                                    <?php else: ?>
                                        <div class="review-avatar bg-light text-dark d-flex align-items-center justify-content-center">
                                            <?= strtoupper(substr($review['username'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="review-user"><?= htmlspecialchars($review['username']) ?></div>
                                        <div class="review-date">
                                            <?= date('F j, Y', strtotime($review['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $review['rating'] ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                                    }
                                    ?>
                                </div>
                                <p class="review-text"><?= nl2br(htmlspecialchars($review['comment'] ?? 'No comment provided')) ?></p>
                            </div>
                        <?php endforeach; ?>
                        
                        <a href="reviews.php?property_id=<?= $property['id'] ?>" class="btn btn-outline-primary">
                            <i class="fas fa-list me-1"></i> View All Reviews
                        </a>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> 
                            This property doesn't have any reviews yet.
                        </div>
                    <?php endif; ?>
                    
                    <?php if(isset($_SESSION['user_id']) && !$user_reviewed && !$is_owner): ?>
                        <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#reviewModal">
                            <i class="fas fa-star me-1"></i> Write a Review
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Location Map -->
                <div class="property-section">
                    <h3 class="section-title">Location</h3>
                    <div id="propertyMap" style="height: 300px; background-color: #eee; border-radius: var(--border-radius);"></div>
                    <p class="mt-3">
                        <i class="fas fa-map-marker-alt text-danger me-2"></i>
                        <?= htmlspecialchars($property['location']) ?>
                    </p>
                </div>
            </div>
            
            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Owner Card -->
                <div class="property-section owner-card sticky-top" style="top: 80px;">
                    <h3 class="section-title text-center">Property Owner</h3>
                    
                    <?php if(!empty($profile_pic_path)): ?>
                        <img src="<?= htmlspecialchars($profile_pic_path) ?>" 
                             class="owner-avatar" 
                             alt="<?= htmlspecialchars($property['owner_name']) ?>">
                    <?php else: ?>
                        <div class="owner-avatar bg-primary text-white d-flex align-items-center justify-content-center">
                            <?= strtoupper(substr($property['owner_name'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    
                    <h4 class="owner-name"><?= htmlspecialchars($property['owner_name']) ?></h4>
                    <p class="owner-contact">
                        <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($property['owner_email']) ?>
                    </p>
                    <p class="owner-contact">
                        <i class="fas fa-phone me-2"></i> <?= htmlspecialchars($property['owner_phone']) ?>
                    </p>
                    
                    <?php if(!$is_owner): ?>
                        <button class="btn btn-primary contact-btn" data-bs-toggle="modal" data-bs-target="#contactModal">
                            <i class="fas fa-envelope me-1"></i> Contact Owner
                        </button>
                        <button class="btn btn-outline-primary contact-btn" data-bs-toggle="modal" data-bs-target="#messageModal">
                            <i class="fas fa-comment me-1"></i> Send Message
                        </button>
                    <?php else: ?>
                        <button class="btn btn-outline-primary contact-btn" disabled>
                            <i class="fas fa-user me-1"></i> This is your property
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Booking Form (for mobile) -->
                <div class="d-block d-lg-none mt-4">
                    <div class="booking-form">
                        <h3 class="section-title">Book This Property</h3>
                        <?php if(count($available_rooms) > 0): ?>
                            <form id="mobileBookingForm">
                                <div class="mb-3">
                                    <label for="mobileRoomSelect" class="form-label">Select Room</label>
                                    <select class="form-select" id="mobileRoomSelect" required>
                                        <?php foreach($available_rooms as $room): ?>
                                            <option value="<?= $room['id'] ?>">
                                                Room <?= htmlspecialchars($room['room_number']) ?> (Capacity: <?= $room['capacity'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="mobileStartDate" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="mobileStartDate" required>
                                </div>
                                <div class="mb-3">
                                    <label for="mobileDuration" class="form-label">Duration (months)</label>
                                    <input type="number" class="form-control" id="mobileDuration" min="1" max="12" value="6" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-calendar-check me-1"></i> Book Now
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Currently there are no available rooms in this property.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Similar Properties -->
                <div class="property-section mt-4">
                    <h3 class="section-title">Similar Properties</h3>
                    <?php if(count($similar_properties) > 0): ?>
                        <?php foreach($similar_properties as $similar): ?>
                            <a href="view.php?id=<?= $similar['id'] ?>" class="similar-property text-decoration-none text-dark">
                                <?php if($similar['thumbnail']): ?>
                                    <img src="../uploads/<?= htmlspecialchars($similar['thumbnail']) ?>" 
                                         class="similar-property-img" 
                                         alt="<?= htmlspecialchars($similar['property_name']) ?>">
                                <?php else: ?>
                                    <img src="../assets/images/default-property.jpg" 
                                         class="similar-property-img" 
                                         alt="Default property image">
                                <?php endif; ?>
                                <div class="similar-property-body">
                                    <h5 class="similar-property-title"><?= htmlspecialchars($similar['property_name']) ?></h5>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="similar-property-price">
                                            GHS <?= number_format($similar['price'], 2) ?>
                                        </span>
                                        <span class="similar-property-rating">
                                            <i class="fas fa-star"></i> <?= number_format($similar['avg_rating'] ?? 0, 1) ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <a href="../?category=<?= $property['category_id'] ?>&location=<?= urlencode($property['location']) ?>" 
                           class="btn btn-outline-primary w-100 mt-3">
                            <i class="fas fa-search me-1"></i> View More
                        </a>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> 
                            No similar properties found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Floating Action Button (Mobile) -->
    <?php if(!$is_owner && count($available_rooms) > 0): ?>
        <button class="floating-action-btn" data-bs-toggle="modal" data-bs-target="#bookingModal">
            <i class="fas fa-calendar-check"></i>
        </button>
    <?php endif; ?>
    
    <!-- Gallery Modal -->
    <div class="modal fade gallery-modal" id="galleryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= htmlspecialchars($property['property_name']) ?> Gallery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if(!empty($images)): ?>
                        <img src="../uploads/<?= htmlspecialchars($images[0]['image_url']) ?>" 
                             class="gallery-modal-img" 
                             id="modalMainImage"
                             alt="<?= htmlspecialchars($property['property_name']) ?>">
                        
                        <div class="gallery-thumbnails">
                            <?php foreach($images as $index => $image): ?>
                                <img src="../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                     class="gallery-thumbnail <?= $index === 0 ? 'active' : '' ?>" 
                                     onclick="changeModalImage(this, '../uploads/<?= htmlspecialchars($image['image_url']) ?>')"
                                     alt="Property thumbnail <?= $index + 1 ?>">
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <img src="../../assets/images/default-property.jpg" 
                             class="gallery-modal-img" 
                             alt="Default property image">
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Booking Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Book <?= htmlspecialchars($property['property_name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="bookingForm" action="../../bookings/create.php" method="POST">
                    <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <div class="modal-body">
                        <?php if(count($available_rooms) > 0): ?>
                            <div class="mb-3">
                                <label for="roomSelect" class="form-label">Select Room</label>
                                <select class="form-select" id="roomSelect" name="room_id" required>
                                    <?php foreach($available_rooms as $room): ?>
                                        <option value="<?= $room['id'] ?>">
                                            Room <?= htmlspecialchars($room['room_number']) ?> (Capacity: <?= $room['capacity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="startDate" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="startDate" name="start_date" required>
                            </div>
                            <div class="mb-3">
                                <label for="duration" class="form-label">Duration (months)</label>
                                <input type="number" class="form-control" id="duration" name="duration" min="1" max="12" value="6" required>
                            </div>
                            <div class="mb-3">
                                <label for="specialRequests" class="form-label">Special Requests</label>
                                <textarea class="form-control" id="specialRequests" name="special_requests" rows="3"></textarea>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                A deposit of 20% will be required to confirm your booking.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Currently there are no available rooms in this property.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <?php if(count($available_rooms) > 0): ?>
                            <button type="submit" class="btn btn-primary">Proceed to Payment</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Contact Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Contact Owner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="contactForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="contactName" class="form-label">Your Name</label>
                            <input type="text" class="form-control" id="contactName" value="<?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactEmail" class="form-label">Your Email</label>
                            <input type="email" class="form-control" id="contactEmail" value="<?= isset($_SESSION['email']) ? htmlspecialchars($_SESSION['email']) : '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactPhone" class="form-label">Your Phone</label>
                            <input type="tel" class="form-control" id="contactPhone" value="<?= isset($_SESSION['phone_number']) ? htmlspecialchars($_SESSION['phone_number']) : '' ?>">
                        </div>
                        <div class="mb-3">
                            <label for="contactMessage" class="form-label">Message</label>
                            <textarea class="form-control" id="contactMessage" rows="5" required>I'm interested in your property "<?= htmlspecialchars($property['property_name']) ?>". Please contact me with more details.</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Message Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Message to Owner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="messageForm" action="../../chat/send.php" method="POST">
                    <input type="hidden" name="recipient_id" value="<?= $property['owner_id'] ?>">
                    <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="messageSubject" class="form-label">Subject</label>
                            <input type="text" class="form-control" id="messageSubject" name="subject" required>
                        </div>
                        <div class="mb-3">
                            <label for="messageContent" class="form-label">Message</label>
                            <textarea class="form-control" id="messageContent" name="message" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Write a Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="reviewForm" action="../../reviews/create.php" method="POST">
                    <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Rating</label>
                            <div class="rating-input">
                                <input type="radio" id="star5" name="rating" value="5" checked>
                                <label for="star5" class="fas fa-star"></label>
                                <input type="radio" id="star4" name="rating" value="4">
                                <label for="star4" class="fas fa-star"></label>
                                <input type="radio" id="star3" name="rating" value="3">
                                <label for="star3" class="fas fa-star"></label>
                                <input type="radio" id="star2" name="rating" value="2">
                                <label for="star2" class="fas fa-star"></label>
                                <input type="radio" id="star1" name="rating" value="1">
                                <label for="star1" class="fas fa-star"></label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="reviewComment" class="form-label">Your Review</label>
                            <textarea class="form-control" id="reviewComment" name="comment" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Room Details Modal -->
    <div class="modal fade" id="roomDetailsModal" tabindex="-1" aria-labelledby="roomDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="roomDetailsModalLabel">
                        <i class="fas fa-door-open me-2"></i>Room Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="roomDetailsContent">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                            <p class="mt-2">Loading room details...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="selectRoomFromDetails" style="display: none;">
                        <i class="fas fa-money-bill-wave me-2"></i>Select for Cash Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

     <!-- Cash Payment Modal -->
    <div class="modal fade" id="cashPaymentModal" tabindex="-1" aria-labelledby="cashPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="cashPaymentModalLabel">
                        <i class="fas fa-money-bill-wave me-2"></i>Record Cash Payment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="cashPaymentForm" method="POST" action="process_cash_payment.php">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Use this form to record a cash payment from a student who has paid you directly.
                        </div>
                        
                        <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                        <input type="hidden" id="selectedRoomId" name="room_id" value="">
                        
                        <!-- Selected Room Details -->
                        <div class="mb-4 p-3 border rounded bg-light">
                            <h6>Selected Room Details</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Room Number:</strong></p>
                                    <p id="displayRoomNumber">-</p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Capacity:</strong></p>
                                    <p id="displayRoomCapacity">-</p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Gender:</strong></p>
                                    <p id="displayRoomGender">-</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="studentName" class="form-label">Student Full Name *</label>
                                <input type="text" class="form-control" id="studentName" name="student_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="studentEmail" class="form-label">Student Email *</label>
                                <input type="email" class="form-control" id="studentEmail" name="student_email" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="studentPhone" class="form-label">Student Phone *</label>
                                <input type="tel" class="form-control" id="studentPhone" name="student_phone" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="studentId" class="form-label">Student ID (Optional)</label>
                                <input type="text" class="form-control" id="studentId" name="student_id">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="paymentAmount" class="form-label">Payment Amount (GHS) *</label>
                                <input type="number" class="form-control" id="paymentAmount" name="amount" 
                                       step="0.01" min="0" value="<?= number_format($property['price'] / 12, 2) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="startDate" class="form-label">Move-in Date *</label>
                                <input type="date" class="form-control" id="startDate" name="start_date" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="tenantLocation" class="form-label">Location *</label>
                                <input type="text" class="form-control" id="tenantLocation" name="tenant_location" required
                                       placeholder="e.g. Kumasi, Accra, Kade, Oda, etc.">
                            </div>
                        </div>

                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="durationMonths" class="form-label">Duration (Months) *</label>
                                <select class="form-select" id="durationMonths" name="duration_months" required>
                                    <option value="">Select duration...</option>
                                    <option value="1">1 Month</option>
                                    <option value="3">3 Months</option>
                                    <option value="6" selected>6 Months</option>
                                    <option value="9">9 Months</option>
                                    <option value="12">12 Months</option>
                                </select>
                            </div>
                        </div>
                        

                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> By submitting this form, you confirm that you have received cash payment from the student. 
                            This will create a booking record and mark it as paid.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i>Record Payment & Create Booking
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    
    <!-- Delete Property Modal (for owners) -->
    <?php if($is_owner): ?>
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Property</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="deleteForm" action="delete.php" method="POST">
                        <input type="hidden" name="id" value="<?= $property['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <div class="modal-body">
                            <p>Are you sure you want to delete this property? This action cannot be undone.</p>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                                <label class="form-check-label" for="confirmDelete">
                                    I understand this will permanently remove the property
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Property</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="bg-dark text-white py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4 mb-md-0">
                    <h5>About UniHomes</h5>
                    <p>Connecting students with quality accommodation near campus since 2023.</p>
                </div>
                <div class="col-md-4 mb-4 mb-md-0">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="../../" class="text-white">Home</a></li>
                        <li><a href="../../properties/" class="text-white">Properties</a></li>
                        <li><a href="../../about/" class="text-white">About Us</a></li>
                        <li><a href="../../contact/" class="text-white">Contact</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5>Contact Us</h5>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-map-marker-alt me-2"></i> Kumasi, Ghana</li>
                        <li><i class="fas fa-phone me-2"></i> +233 123 456 789</li>
                        <li><i class="fas fa-envelope me-2"></i> info@unihomes.com</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <p class="mb-0">&copy; 2023 UniHomes. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <div class="social-icons">
                        <a href="#" class="text-white me-2"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-2"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-2"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    </script>
    
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    
    <script>
        // Global variables to store selected room details
        let selectedRoomId = null;
        let selectedRoomNumber = null;
        let selectedRoomCapacity = null;
        let selectedRoomGender = null;
        let selectedPropertyName = "<?= htmlspecialchars($property['property_name']) ?>";
        
        // Function to update modal with room details
        function updateCashPaymentModal() {
            if (selectedRoomId) {
                document.getElementById('displayRoomNumber').textContent = selectedRoomNumber;
                document.getElementById('displayRoomCapacity').textContent = selectedRoomCapacity;
                document.getElementById('displayRoomGender').textContent = selectedRoomGender;
                
                // Set hidden input values
                document.getElementById('selectedRoomId').value = selectedRoomId;
                
                // Update modal title
                document.getElementById('cashPaymentModalLabel').innerHTML = 
                    `<i class="fas fa-money-bill-wave me-2"></i>Record Cash Payment - ${selectedPropertyName}`;
            }
        }
        
        // Function to capture room details
        function selectRoomForCashPayment(roomId, roomNumber, capacity, gender) {
            selectedRoomId = roomId;
            selectedRoomNumber = roomNumber;
            selectedRoomCapacity = capacity;
            selectedRoomGender = gender;
            updateCashPaymentModal();
        }

        // Change main image when thumbnail is clicked
        function changeMainImage(element, newSrc) {
            document.getElementById('mainImage').src = newSrc;
            
            // Update active thumbnail
            document.querySelectorAll('.property-thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
        
        // Change modal image when thumbnail is clicked
        function changeModalImage(element, newSrc) {
            document.getElementById('modalMainImage').src = newSrc;
            
            // Update active thumbnail
            document.querySelectorAll('.gallery-thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
        
        // Book specific room
        function bookRoom(roomId, roomNumber) {
            document.getElementById('roomSelect').value = roomId;
            document.getElementById('mobileRoomSelect').value = roomId;
            
            // Update modal title
            document.querySelector('#bookingModal .modal-title').textContent = `Book Room ${roomNumber}`;
            
            // Show modal
            const bookingModal = new bootstrap.Modal(document.getElementById('bookingModal'));
            bookingModal.show();
        }
        
        // Initialize map with corrected marker icons
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($property['latitude'] && $property['longitude']): ?>
                // Create custom icon to prevent 404 errors
                const defaultIcon = L.icon({
                    iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-icon.png',
                    iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-icon-2x.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });

                const map = L.map('propertyMap').setView([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                L.marker([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>], {icon: defaultIcon}).addTo(map)
                    .bindPopup('<?= addslashes($property['property_name']) ?>')
                    .openPopup();
            <?php else: ?>
                document.getElementById('propertyMap').innerHTML = '<div class="alert alert-info m-3">Location map not available for this property.</div>';
            <?php endif; ?>
            
            // Set minimum date for booking to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('startDate').min = today;
            document.getElementById('mobileStartDate').min = today;
            
            // Form submissions
            document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
                // Additional validation can be added here
                // If validation passes, form will submit
            });
            
            document.getElementById('mobileBookingForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                // You can implement mobile form submission logic here
                alert('Booking request submitted!');
            });
            
            document.getElementById('contactForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                alert('Your message has been sent to the property owner.');
                const contactModal = bootstrap.Modal.getInstance(document.getElementById('contactModal'));
                contactModal.hide();
            });
            
            // Rating input styling
            const ratingInputs = document.querySelectorAll('.rating-input input');
            ratingInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const stars = this.parentElement.querySelectorAll('label');
                    const value = parseInt(this.value);
                    
                    stars.forEach((star, index) => {
                        if (index < value) {
                            star.classList.add('text-warning');
                        } else {
                            star.classList.remove('text-warning');
                        }
                    });
                });
            });
            
            // Initialize rating stars
            const firstChecked = document.querySelector('.rating-input input:checked');
            if (firstChecked) {
                firstChecked.dispatchEvent(new Event('change'));
            }
            
            // Cash Payment Modal functionality
            const cashPaymentModal = document.getElementById('cashPaymentModal');
            
            // When modal is shown, populate with selected room details
            cashPaymentModal.addEventListener('show.bs.modal', function() {
                // Set minimum date to today
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('startDate').min = today;
                document.getElementById('startDate').value = today;
                
                // Update room details
                updateCashPaymentModal();
            });
            
            // Reset form when modal is hidden
            cashPaymentModal.addEventListener('hidden.bs.modal', function() {
                document.getElementById('cashPaymentForm').reset();
                selectedRoomId = null;
            });
            
            // Handle form submission
            document.getElementById('cashPaymentForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!selectedRoomId) {
                    alert('Please select a room first');
                    return;
                }
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
                
                // Submit form data
                const formData = new FormData(this);
                
                fetch('process_cash_payment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Close modal
                        bootstrap.Modal.getInstance(cashPaymentModal).hide();
                        
                        // Show success message with room details
                        const successMsg = `Payment recorded for Room ${selectedRoomNumber}!\n` +
                                           `Property: ${selectedPropertyName}\n` +
                                           `Capacity: ${selectedRoomCapacity} students\n` +
                                           `Gender: ${selectedRoomGender}`;
                        alert(successMsg);
                        
                        // Optional: Update UI to reflect booked room
                        const roomCard = document.querySelector(`.room-card:has(button[onclick*="${selectedRoomId}"])`);
                        if (roomCard) {
                            roomCard.querySelector('.room-status').textContent = 'Occupied';
                            roomCard.querySelector('.room-status').className = 'room-status status-occupied';
                        }
                        
                        // Reload page to update room availability
                        setTimeout(() => {
                            window.location.reload();
                        }, 3000);
                    } else {
                        throw new Error(data.message || 'Failed to process cash payment');
                    }
                })
                .catch(error => {
                    alert(error.message);
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                });
            });
        });
    </script>
</body>
</html>