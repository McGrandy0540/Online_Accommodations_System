<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Function to get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return '../../assets/images/default-avatar.png';
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../../' . ltrim($path, '/');
}

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $property_id = $_POST['property_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (property_id, user_id, rating, comment) 
                                VALUES (:property_id, :user_id, :rating, :comment)");
        $stmt->execute([
            ':property_id' => $property_id,
            ':user_id' => $student_id,
            ':rating' => $rating,
            ':comment' => $comment
        ]);
        
        $_SESSION['success_message'] = "Review submitted successfully!";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error submitting review: " . $e->getMessage();
    }
}

// Handle review deletion
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    try {
        // Check if the review belongs to the current user
        $stmt = $pdo->prepare("SELECT user_id FROM reviews WHERE id = :id");
        $stmt->execute([':id' => $delete_id]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($review && $review['user_id'] == $student_id) {
            $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = :id");
            $stmt->execute([':id' => $delete_id]);
            
            $_SESSION['success_message'] = "Review deleted successfully!";
            header("Location: ".$_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error_message'] = "You can't delete this review";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting review: " . $e->getMessage();
    }
}

// Get properties for dropdown with details
$properties = [];
try {
    $stmt = $pdo->query("
        SELECT p.id, p.property_name, p.location, p.bedrooms, p.bathrooms, p.price, 
               GROUP_CONCAT(pi.image_url) AS images
        FROM property p
        LEFT JOIN property_images pi ON p.id = pi.property_id
        WHERE p.status = 'available'
        GROUP BY p.id
    ");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode images
    foreach ($properties as &$property) {
        $property['images'] = $property['images'] ? explode(',', $property['images']) : [];
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error loading properties: " . $e->getMessage();
}

// Get user's reviews with property details
$user_reviews = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.property_name, p.location, p.bedrooms, p.bathrooms, p.price,
               GROUP_CONCAT(pi.image_url) AS images
        FROM reviews r
        JOIN property p ON r.property_id = p.id
        LEFT JOIN property_images pi ON p.id = pi.property_id
        WHERE r.user_id = :user_id
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([':user_id' => $student_id]);
    $user_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode images for reviews
    foreach ($user_reviews as &$review) {
        $review['images'] = $review['images'] ? explode(',', $review['images']) : [];
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error loading reviews: " . $e->getMessage();
}

// Get responses to user's reviews
$review_responses = [];
try {
    // Check if review_responses table exists
    $table_exists = $pdo->query("SHOW TABLES LIKE 'review_responses'")->rowCount() > 0;
    
    if ($table_exists) {
        $stmt = $pdo->prepare("
            SELECT rr.*, u.username AS responder_name, p.property_name, r.comment AS review_comment,
                   GROUP_CONCAT(pi.image_url) AS images
            FROM review_responses rr
            JOIN reviews r ON rr.review_id = r.id
            JOIN users u ON rr.responder_id = u.id
            JOIN property p ON r.property_id = p.id
            LEFT JOIN property_images pi ON p.id = pi.property_id
            WHERE r.user_id = :user_id
            GROUP BY rr.id
            ORDER BY rr.created_at DESC
        ");
        $stmt->execute([':user_id' => $student_id]);
        $review_responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode images for responses
        foreach ($review_responses as &$response) {
            $response['images'] = $response['images'] ? explode(',', $response['images']) : [];
        }
    }
} catch (PDOException $e) {
    // Handle error gracefully
    $_SESSION['error_message'] = "Error loading responses: " . $e->getMessage();
}

// Function to generate star rating display
function displayStars($rating) {
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '★';
        } else {
            $stars .= '☆';
        }
    }
    return $stars;
}

// Get current tab from URL
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'write';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Accommodation Reviews</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--secondary-color);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(to bottom, var(--secondary-color), var(--dark-color));
            color: white;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            transition: width var(--transition-speed);
            overflow: hidden;
            z-index: 1000;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--header-height);
        }

        .sidebar-header h2 {
            color: white;
            font-size: 1.2rem;
            white-space: nowrap;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .sidebar-menu a.active {
            background-color: var(--primary-color);
            color: white;
        }

        .sidebar-menu i {
            width: 24px;
            margin-right: 15px;
            text-align: center;
            font-size: 1.1rem;
        }

        .menu-text {
            transition: opacity var(--transition-speed);
        }

        .sidebar.collapsed .menu-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-speed);
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 15px 0;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            position: relative;
            height: var(--header-height);
            display: flex;
            align-items: center;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
        }

        h1, h2, h3 {
            margin-bottom: 20px;
            color: var(--secondary-color);
        }

        .tabs {
            display: flex;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            text-decoration: none;
            color: var(--secondary-color);
        }

        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .tab:hover:not(.active) {
            background-color: var(--light-color);
        }

        .tab-content {
            display: none;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-bottom: 30px;
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .rating {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
        }

        .rating input {
            display: none;
        }

        .rating label {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .rating input:checked ~ label,
        .rating label:hover,
        .rating label:hover ~ label {
            color: var(--warning-color);
        }

        .rating input:checked + label {
            color: var(--warning-color);
        }

        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s ease;
            text-align: center;
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--primary-hover);
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .btn-danger {
            background-color: var(--accent-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .review-item {
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .review-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            align-items: center;
        }

        .property-name {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-color);
        }

        .review-date {
            color: #777;
            font-size: 0.9rem;
        }

        .rating-stars {
            color: var(--warning-color);
            margin: 10px 0;
            font-size: 1.2rem;
        }

        .review-content {
            margin-bottom: 15px;
            line-height: 1.7;
        }

        .response-container {
            background-color: var(--light-color);
            border-left: 4px solid var(--info-color);
            padding: 15px;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            margin-top: 15px;
        }

        .response-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--info-color);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #777;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        .empty-state h3 {
            color: #777;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Property details styles */
        .property-details {
            background-color: #f9f9f9;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }
        
        .property-title {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }
        
        .property-location {
            color: #666;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .property-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .property-info-item {
            background-color: white;
            padding: 15px;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--card-shadow);
        }
        
        .property-info-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .property-info-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .property-price {
            font-size: 1.5rem;
            color: var(--success-color);
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        /* Carousel styles */
        .carousel-container {
            position: relative;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            overflow: hidden;
            height: 300px;
        }
        
        .carousel-slide {
            display: none;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .carousel-slide.active {
            display: block;
        }
        
        .carousel-controls {
            position: absolute;
            bottom: 15px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        
        .carousel-btn {
            background-color: rgba(0,0,0,0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .carousel-indicators {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 10px;
        }
        
        .carousel-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #ddd;
            cursor: pointer;
        }
        
        .carousel-indicator.active {
            background-color: var(--primary-color);
        }
        
        .no-image {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 300px;
            background-color: #f0f0f0;
            border-radius: var(--border-radius);
            color: #666;
            font-size: 1.2rem;
        }
        
        .property-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar .menu-text {
                opacity: 0;
                width: 0;
                overflow: hidden;
            }
            
            .sidebar-header h2 {
                display: none;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .sidebar.collapsed {
                width: 0;
            }
            
            .sidebar.collapsed ~ .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }
            
            .review-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .review-date {
                margin-top: 5px;
            }
            
            .container {
                padding: 10px;
            }
            
            .tab-content {
                padding: 20px 15px;
            }
            
            .user-info {
                position: static;
                justify-content: center;
                margin-top: 10px;
            }
            
            .property-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .carousel-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>Tenant Dashboard</h2>
            <button class="toggle-btn" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        <div class="sidebar-menu">
            <ul>
                <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                <li><a href="../search/"><i class="fas fa-search"></i> <span class="menu-text">Find Accommodation</span></a></li>
                <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">My Bookings</span></a></li>
                <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                <li><a href="../messages/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                <li><a href="../reviews/" class="active"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                <li><a href="../maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                <li><a href="../settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                <li><a href="../notification/"><i class="fas fa-bell"></i> <span class="menu-text">Notifications</span></a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <header>
            <div class="container header-content">
                <h1>Student Accommodation Reviews</h1>
                <div class="user-info">
                    <img src="<?= getProfilePicturePath($_SESSION['profile_picture'] ?? '') ?>" alt="Profile">
                    <span><?= htmlspecialchars($_SESSION['username'] ?? 'Student') ?></span>
                </div>
            </div>
        </header>

        <div class="container">
            <!-- Display success/error messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <?= $_SESSION['error_message'] ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <div class="tabs">
                <a href="?tab=write" class="tab <?= $current_tab === 'write' ? 'active' : '' ?>" data-tab="write">Write Review</a>
                <a href="?tab=my-reviews" class="tab <?= $current_tab === 'my-reviews' ? 'active' : '' ?>" data-tab="my-reviews">My Reviews</a>
                <a href="?tab=responses" class="tab <?= $current_tab === 'responses' ? 'active' : '' ?>" data-tab="responses">Responses</a>
            </div>

            <!-- Write Review Tab -->
            <div id="write" class="tab-content <?= $current_tab === 'write' ? 'active' : '' ?>">
                <h2>Write a New Review</h2>
                <div class="card">
                    <form id="reviewForm" method="POST">
                        <div class="form-group">
                            <label for="property">Select Property</label>
                            <select id="property" name="property_id" required onchange="showPropertyDetails(this.value)">
                                <option value="">Choose a property...</option>
                                <?php foreach ($properties as $property): ?>
                                    <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['property_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="property-details" class="property-details" style="display: none;">
                            <h3 class="property-title" id="property-name-display"></h3>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <span id="property-location"></span>
                            </div>
                            
                            <div class="property-info-grid">
                                <div class="property-info-item">
                                    <div class="property-info-label">Bedrooms</div>
                                    <div class="property-info-value" id="property-bedrooms"></div>
                                </div>
                                <div class="property-info-item">
                                    <div class="property-info-label">Bathrooms</div>
                                    <div class="property-info-value" id="property-bathrooms"></div>
                                </div>
                                <div class="property-info-item">
                                    <div class="property-info-label">Price</div>
                                    <div class="property-info-value" id="property-price"></div>
                                </div>
                            </div>
                            
                            <div id="property-carousel">
                                <!-- Carousel will be inserted here -->
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Rating</label>
                            <div class="rating">
                                <input type="radio" id="star5" name="rating" value="5" required>
                                <label for="star5">★</label>
                                <input type="radio" id="star4" name="rating" value="4">
                                <label for="star4">★</label>
                                <input type="radio" id="star3" name="rating" value="3">
                                <label for="star3">★</label>
                                <input type="radio" id="star2" name="rating" value="2">
                                <label for="star2">★</label>
                                <input type="radio" id="star1" name="rating" value="1">
                                <label for="star1">★</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="review">Your Review</label>
                            <textarea id="review" name="comment" rows="6" placeholder="Share your experience with this accommodation..." required></textarea>
                        </div>
                        
                        <button type="submit" name="submit_review" class="btn btn-block">Submit Review</button>
                    </form>
                </div>
            </div>

            <!-- My Reviews Tab -->
            <div id="my-reviews" class="tab-content <?= $current_tab === 'my-reviews' ? 'active' : '' ?>">
                <h2>My Reviews</h2>
                
                <div class="reviews-list">
                    <?php if (!empty($user_reviews)): ?>
                        <?php foreach ($user_reviews as $review): ?>
                            <div class="card">
                                <div class="review-item">
                                    <div class="review-header">
                                        <span class="property-name"><?= htmlspecialchars($review['property_name']) ?></span>
                                        <span class="review-date">Posted on: <?= date('d M Y', strtotime($review['created_at'])) ?></span>
                                    </div>
                                    <div class="rating-stars"><?= displayStars($review['rating']) ?></div>
                                    
                                    <div class="property-details">
                                        <div class="property-location">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?= htmlspecialchars($review['location']) ?>
                                        </div>
                                        
                                        <div class="property-info-grid">
                                            <div class="property-info-item">
                                                <div class="property-info-label">Bedrooms</div>
                                                <div class="property-info-value"><?= $review['bedrooms'] ?></div>
                                            </div>
                                            <div class="property-info-item">
                                                <div class="property-info-label">Bathrooms</div>
                                                <div class="property-info-value"><?= $review['bathrooms'] ?></div>
                                            </div>
                                            <div class="property-info-item">
                                                <div class="property-info-label">Price</div>
                                                <div class="property-info-value">GHS <?= number_format($review['price'], 2) ?></div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($review['images'])): ?>
                                            <div class="carousel-container">
                                                <?php foreach ($review['images'] as $index => $image): ?>
                                                    <img src="../../uploads/<?= htmlspecialchars($image) ?>" 
                                                         class="carousel-slide <?= $index === 0 ? 'active' : '' ?>" 
                                                         alt="Property image">
                                                <?php endforeach; ?>
                                                <div class="carousel-controls">
                                                    <button class="carousel-btn prev-btn"><i class="fas fa-chevron-left"></i></button>
                                                    <button class="carousel-btn next-btn"><i class="fas fa-chevron-right"></i></button>
                                                </div>
                                            </div>
                                            <div class="carousel-indicators">
                                                <?php foreach ($review['images'] as $index => $image): ?>
                                                    <div class="carousel-indicator <?= $index === 0 ? 'active' : '' ?>"></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="no-image">
                                                <i class="fas fa-image"></i> No images available
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="review-content">
                                        <p><?= htmlspecialchars($review['comment']) ?></p>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="?tab=my-reviews&delete_id=<?= $review['id'] ?>" class="btn btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this review?')">Delete</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i>📝</i>
                            <h3>No Reviews Yet</h3>
                            <p>You haven't written any reviews yet. Share your experience to help other students!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Responses Tab -->
            <div id="responses" class="tab-content <?= $current_tab === 'responses' ? 'active' : '' ?>">
                <h2>Responses to My Reviews</h2>
                
                <div class="responses-list">
                    <?php if (!empty($review_responses)): ?>
                        <div class="card">
                            <?php foreach ($review_responses as $response): ?>
                                <div class="review-item">
                                    <div class="review-header">
                                        <span class="property-name"><?= htmlspecialchars($response['property_name']) ?></span>
                                        <span class="review-date">Posted on: <?= date('d M Y', strtotime($response['created_at'])) ?></span>
                                    </div>
                                    
                                    <div class="property-details">
                                        <div class="property-location">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?= htmlspecialchars($response['location'] ?? 'Location not available') ?>
                                        </div>
                                        
                                        <?php if (!empty($response['images'])): ?>
                                            <div class="carousel-container">
                                                <?php foreach ($response['images'] as $index => $image): ?>
                                                    <img src="../../uploads/<?= htmlspecialchars($image) ?>" 
                                                         class="carousel-slide <?= $index === 0 ? 'active' : '' ?>" 
                                                         alt="Property image">
                                                <?php endforeach; ?>
                                                <div class="carousel-controls">
                                                    <button class="carousel-btn prev-btn"><i class="fas fa-chevron-left"></i></button>
                                                    <button class="carousel-btn next-btn"><i class="fas fa-chevron-right"></i></button>
                                                </div>
                                            </div>
                                            <div class="carousel-indicators">
                                                <?php foreach ($response['images'] as $index => $image): ?>
                                                    <div class="carousel-indicator <?= $index === 0 ? 'active' : '' ?>"></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="no-image">
                                                <i class="fas fa-image"></i> No images available
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="review-content">
                                        <p>Your review: "<?= htmlspecialchars($response['review_comment'] ?? '') ?>"</p>
                                    </div>
                                    
                                    <div class="response-container">
                                        <div class="response-header">
                                            <span>Response from <?= htmlspecialchars($response['responder_name']) ?></span>
                                            <span class="review-date"><?= date('d M Y', strtotime($response['created_at'])) ?></span>
                                        </div>
                                        <p><?= htmlspecialchars($response['response']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i>💬</i>
                            <h3>No Responses Yet</h3>
                            <p>You haven't received any responses to your reviews yet. Property owners typically respond within 3-5 business days.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Properties data for JavaScript
        const propertiesData = <?= json_encode($properties) ?>;
        
        // Function to show property details
        function showPropertyDetails(propertyId) {
            const propertyDetails = document.getElementById('property-details');
            const property = propertiesData.find(p => p.id == propertyId);
            
            if (!property) {
                propertyDetails.style.display = 'none';
                return;
            }
            
            // Update property details
            document.getElementById('property-name-display').textContent = property.property_name;
            document.getElementById('property-location').textContent = property.location;
            document.getElementById('property-bedrooms').textContent = property.bedrooms;
            document.getElementById('property-bathrooms').textContent = property.bathrooms;
            document.getElementById('property-price').textContent = 'GHS ' + parseFloat(property.price).toFixed(2);
            
            // Update carousel
            const carouselContainer = document.getElementById('property-carousel');
            carouselContainer.innerHTML = '';
            
            if (property.images && property.images.length > 0) {
                let carouselHTML = `
                    <div class="carousel-container">
                        ${property.images.map((image, index) => `
                            <img src="../../uploads/${image}" 
                                 class="carousel-slide ${index === 0 ? 'active' : ''}" 
                                 alt="Property image">
                        `).join('')}
                        <div class="carousel-controls">
                            <button class="carousel-btn prev-btn"><i class="fas fa-chevron-left"></i></button>
                            <button class="carousel-btn next-btn"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div class="carousel-indicators">
                        ${property.images.map((_, index) => `
                            <div class="carousel-indicator ${index === 0 ? 'active' : ''}"></div>
                        `).join('')}
                    </div>
                `;
                
                carouselContainer.innerHTML = carouselHTML;
                initCarousel();
            } else {
                carouselContainer.innerHTML = `
                    <div class="no-image">
                        <i class="fas fa-image"></i> No images available
                    </div>
                `;
            }
            
            propertyDetails.style.display = 'block';
        }
        
        // Initialize carousel
        function initCarousel() {
            const slides = document.querySelectorAll('.carousel-slide');
            const indicators = document.querySelectorAll('.carousel-indicator');
            let currentSlide = 0;
            
            if (slides.length === 0) return;
            
            function showSlide(n) {
                slides.forEach(slide => slide.classList.remove('active'));
                indicators.forEach(indicator => indicator.classList.remove('active'));
                
                currentSlide = (n + slides.length) % slides.length;
                
                slides[currentSlide].classList.add('active');
                indicators[currentSlide].classList.add('active');
            }
            
            // Previous button
            document.querySelector('.prev-btn')?.addEventListener('click', () => {
                showSlide(currentSlide - 1);
            });
            
            // Next button
            document.querySelector('.next-btn')?.addEventListener('click', () => {
                showSlide(currentSlide + 1);
            });
            
            // Indicators
            indicators.forEach((indicator, index) => {
                indicator.addEventListener('click', () => {
                    showSlide(index);
                });
            });
            
            // Auto-advance every 5 seconds
            setInterval(() => {
                showSlide(currentSlide + 1);
            }, 5000);
        }
        
        // Initialize carousels on page load
        function initAllCarousels() {
            document.querySelectorAll('.carousel-container').forEach(container => {
                const slides = container.querySelectorAll('.carousel-slide');
                const indicators = container.parentElement.querySelectorAll('.carousel-indicator');
                const prevBtn = container.querySelector('.prev-btn');
                const nextBtn = container.querySelector('.next-btn');
                let currentSlide = 0;
                
                if (slides.length === 0) return;
                
                function showSlide(n) {
                    slides.forEach(slide => slide.classList.remove('active'));
                    indicators.forEach(indicator => indicator.classList.remove('active'));
                    
                    currentSlide = (n + slides.length) % slides.length;
                    
                    slides[currentSlide].classList.add('active');
                    indicators[currentSlide].classList.add('active');
                }
                
                // Previous button
                if (prevBtn) {
                    prevBtn.addEventListener('click', () => {
                        showSlide(currentSlide - 1);
                    });
                }
                
                // Next button
                if (nextBtn) {
                    nextBtn.addEventListener('click', () => {
                        showSlide(currentSlide + 1);
                    });
                }
                
                // Indicators
                indicators.forEach((indicator, index) => {
                    indicator.addEventListener('click', () => {
                        showSlide(index);
                    });
                });
                
                // Auto-advance every 5 seconds
                setInterval(() => {
                    showSlide(currentSlide + 1);
                }, 5000);
            });
        }
        
        // Tab switching functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and content
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Form submission handling
        document.getElementById('reviewForm')?.addEventListener('submit', function(e) {
            // Client-side validation
            const rating = this.querySelector('input[name="rating"]:checked');
            const comment = this.querySelector('#review').value;
            
            if (!rating) {
                e.preventDefault();
                alert('Please select a rating');
                return false;
            }
            
            if (!comment.trim()) {
                e.preventDefault();
                alert('Please write your review');
                return false;
            }
        });

        // Auto-select tab based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            
            if (tabParam) {
                // Remove active class from all tabs and content
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Activate the requested tab
                const tabElement = document.querySelector(`.tab[data-tab="${tabParam}"]`);
                const contentElement = document.getElementById(tabParam);
                
                if (tabElement && contentElement) {
                    tabElement.classList.add('active');
                    contentElement.classList.add('active');
                }
            }
            
            // If property is selected in URL, show its details
            const propertyId = urlParams.get('property_id');
            if (propertyId) {
                document.getElementById('property').value = propertyId;
                showPropertyDetails(propertyId);
            }
            
            // Initialize all carousels
            initAllCarousels();
        });

        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            const icon = sidebarToggle.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }
        });
    </script>
</body>
</html>