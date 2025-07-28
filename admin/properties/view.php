<?php
// Start session and check admin authentication
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit();
}

// Database connection
require_once(__DIR__ . '../../../config/database.php');
$db = Database::getInstance();

// Get property ID
$propertyId = $_GET['id'] ?? null;
if (!$propertyId) {
    header("Location: index.php");
    exit();
}

// Get property data
try {
    $stmt = $db->prepare("
        SELECT p.*, u.username as owner_name, u.email as owner_email, 
               u.phone_number as owner_phone, u.profile_pic as owner_photo,
               c.name as category_name
        FROM property p
        JOIN users u ON p.owner_id = u.id
        JOIN categories c ON p.category_id = c.id
        WHERE p.id = ? AND p.deleted = 0
    ");
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$property) {
        $_SESSION['error'] = "Property not found";
        header("Location: index.php");
        exit();
    }
    
    // Get features
    $features = $db->prepare("SELECT feature_name FROM property_features WHERE property_id = ?");
    $features->execute([$propertyId]);
    $propertyFeatures = $features->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Get images
    $images = $db->prepare("SELECT * FROM property_images WHERE property_id = ? ORDER BY is_virtual_tour DESC");
    $images->execute([$propertyId]);
    $propertyImages = $images->fetchAll(PDO::FETCH_ASSOC);
    
    // Get rooms
    $rooms = $db->prepare("SELECT * FROM property_rooms WHERE property_id = ? ORDER BY room_number");
    $rooms->execute([$propertyId]);
    $propertyRooms = $rooms->fetchAll(PDO::FETCH_ASSOC);
    
    // Get bookings
    $bookings = $db->prepare("
        SELECT b.*, u.username as student_name, u.profile_pic as student_photo
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE b.property_id = ?
        ORDER BY b.start_date DESC
        LIMIT 5
    ");
    $bookings->execute([$propertyId]);
    $propertyBookings = $bookings->fetchAll(PDO::FETCH_ASSOC);
    
    // Get reviews
    $reviews = $db->prepare("
        SELECT r.*, u.username as student_name, u.profile_pic as student_photo
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.property_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $reviews->execute([$propertyId]);
    $propertyReviews = $reviews->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($property['property_name']); ?> | UniHomes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css" />
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
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
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            padding: 20px;
            background: var(--secondary-color);
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background-color: var(--accent-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        /* Image Gallery */
        .swiper {
            width: 100%;
            height: 400px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .swiper-slide {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
        }
        
        .swiper-slide img, .swiper-slide video {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .swiper-pagination-bullet-active {
            background: var(--primary-color);
        }
        
        .swiper-button-next, .swiper-button-prev {
            color: var(--primary-color);
        }
        
        /* Property Details */
        .property-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .detail-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
        }
        
        .detail-card h5 {
            color: var(--secondary-color);
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .detail-card p {
            font-size: 1.2rem;
            font-weight: 500;
        }
        
        /* Status Badges */
        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .status.available {
            background: #e3f9ee;
            color: #00a854;
        }
        
        .status.booked, .status.occupied {
            background: #fff0f0;
            color: #f5222d;
        }
        
        .status.paid {
            background: #e6f7ff;
            color: #1890ff;
        }
        
        .status.maintenance {
            background: #fff7e6;
            color: #fa8c16;
        }
        
        /* Features */
        .features-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        
        .feature-badge {
            display: inline-block;
            background: var(--light-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--dark-color);
        }
        
        /* Owner Info */
        .owner-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
        }
        
        .owner-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--light-color);
        }
        
        .owner-info h4 {
            margin-bottom: 5px;
            color: var(--secondary-color);
        }
        
        .owner-contacts {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .owner-contacts a {
            color: var(--primary-color);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .owner-contacts a:hover {
            color: var(--accent-color);
        }
        
        /* Rooms Table */
        .rooms-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .rooms-table th, .rooms-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .rooms-table th {
            background: var(--light-color);
            font-weight: 600;
        }
        
        .rooms-table tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }
        
        /* Reviews */
        .review-card {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 15px;
        }
        
        .review-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .review-content {
            flex: 1;
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .review-author {
            font-weight: 600;
        }
        
        .review-date {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .review-rating {
            color: var(--warning-color);
            margin-bottom: 5px;
        }
        
        /* Map */
        #property-map {
            height: 300px;
            width: 100%;
            border-radius: var(--border-radius);
            margin-top: 15px;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .property-details {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
            
            .swiper {
                height: 350px;
            }
        }
        
        @media (max-width: 768px) {
            .property-details {
                grid-template-columns: 1fr 1fr;
            }
            
            .swiper {
                height: 300px;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .card-header .btn-group {
                width: 100%;
                display: flex;
                gap: 10px;
            }
            
            .card-header .btn {
                flex: 1;
                text-align: center;
            }
        }
        
        @media (max-width: 576px) {
            .property-details {
                grid-template-columns: 1fr;
            }
            
            .swiper {
                height: 250px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card fade-in">
            <div class="card-header">
                <h2><?php echo htmlspecialchars($property['property_name']); ?></h2>
                <div class="btn-group">
                    <a href="edit.php?id=<?php echo $propertyId; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <button onclick="confirmDelete(<?php echo $propertyId; ?>)" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Image Gallery -->
                <?php if (!empty($propertyImages)): ?>
                <div class="swiper">
                    <div class="swiper-wrapper">
                        <?php foreach ($propertyImages as $image): ?>
                            <div class="swiper-slide">
                                <?php if (strpos($image['image_url'], '.mp4') !== false || strpos($image['image_url'], '.webm') !== false): ?>
                                    <video controls>
                                        <source src="../../../<?php echo htmlspecialchars($image['image_url']); ?>" type="video/mp4">
                                    </video>
                                <?php else: ?>
                                    <img src="../../../<?php echo htmlspecialchars($image['image_url']); ?>" alt="Property Image">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="swiper-pagination"></div>
                    <div class="swiper-button-next"></div>
                    <div class="swiper-button-prev"></div>
                </div>
                <?php else: ?>
                    <div class="no-images" style="background: #f0f0f0; padding: 40px; text-align: center; border-radius: var(--border-radius); margin-bottom: 20px;">
                        <i class="fas fa-image" style="font-size: 3rem; color: #ccc; margin-bottom: 10px;"></i>
                        <p>No images available</p>
                    </div>
                <?php endif; ?>
                
                <!-- Property Highlights -->
                <div class="property-details">
                    <div class="detail-card">
                        <h5><i class="fas fa-tag"></i> Category</h5>
                        <p><?php echo htmlspecialchars($property['category_name']); ?></p>
                    </div>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-dollar-sign"></i> Price</h5>
                        <p>$<?php echo number_format($property['price'], 2); ?></p>
                    </div>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-info-circle"></i> Status</h5>
                        <p><span class="status <?php echo strtolower($property['status']); ?>"><?php echo ucfirst($property['status']); ?></span></p>
                    </div>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-map-marker-alt"></i> Location</h5>
                        <p><?php echo htmlspecialchars($property['location']); ?></p>
                    </div>
                    
                    <?php if ($property['bedrooms']): ?>
                    <div class="detail-card">
                        <h5><i class="fas fa-bed"></i> Bedrooms</h5>
                        <p><?php echo htmlspecialchars($property['bedrooms']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($property['bathrooms']): ?>
                    <div class="detail-card">
                        <h5><i class="fas fa-bath"></i> Bathrooms</h5>
                        <p><?php echo htmlspecialchars($property['bathrooms']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($property['area_sqft']): ?>
                    <div class="detail-card">
                        <h5><i class="fas fa-ruler-combined"></i> Area</h5>
                        <p><?php echo number_format($property['area_sqft']); ?> sqft</p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($property['year_built']): ?>
                    <div class="detail-card">
                        <h5><i class="fas fa-calendar-alt"></i> Year Built</h5>
                        <p><?php echo htmlspecialchars($property['year_built']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Description -->
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                        <h5><i class="fas fa-align-left"></i> Description</h5>
                    </div>
                    <div class="card-body">
                        <p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
                    </div>
                </div>
                
                <!-- Features -->
                <?php if (!empty($propertyFeatures)): ?>
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                        <h5><i class="fas fa-star"></i> Features & Amenities</h5>
                    </div>
                    <div class="card-body">
                        <div class="features-grid">
                            <?php foreach ($propertyFeatures as $feature): ?>
                                <span class="feature-badge"><?php echo htmlspecialchars($feature); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Rooms -->
                        <?php if (!empty($propertyRooms)): ?>
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                                <h5><i class="fas fa-door-open"></i> Rooms (<?php echo count($propertyRooms); ?>)</h5>
                            </div>
                            <div class="card-body">
                                <table class="rooms-table">
                                    <thead>
                                        <tr>
                                            <th>Room Number</th>
                                            <th>Capacity</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($propertyRooms as $room): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                                                <td><?php echo htmlspecialchars($room['capacity']); ?></td>
                                                <td><span class="status <?php echo strtolower($room['status']); ?>"><?php echo ucfirst($room['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Location Map -->
                        <?php if ($property['latitude'] && $property['longitude']): ?>
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                                <h5><i class="fas fa-map-marked-alt"></i> Location</h5>
                            </div>
                            <div class="card-body">
                                <div id="property-map"></div>
                                <script>
                                    function initMap() {
                                        const location = { 
                                            lat: <?php echo $property['latitude']; ?>, 
                                            lng: <?php echo $property['longitude']; ?> 
                                        };
                                        const map = new google.maps.Map(document.getElementById("property-map"), {
                                            zoom: 15,
                                            center: location,
                                        });
                                        new google.maps.Marker({
                                            position: location,
                                            map: map,
                                            title: "<?php echo htmlspecialchars($property['property_name']); ?>"
                                        });
                                    }
                                </script>
                                <script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap"></script>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Owner Information -->
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                                <h5><i class="fas fa-user-tie"></i> Owner Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="owner-card">
                                    <img src="../../../<?php echo htmlspecialchars($property['owner_photo'] ?? 'assets/images/profiles/default.jpg'); ?>" alt="Owner" class="owner-avatar">
                                    <div class="owner-info">
                                        <h4><?php echo htmlspecialchars($property['owner_name']); ?></h4>
                                        <div class="owner-contacts">
                                            <a href="mailto:<?php echo htmlspecialchars($property['owner_email']); ?>">
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($property['owner_email']); ?>
                                            </a>
                                            <a href="tel:<?php echo htmlspecialchars($property['owner_phone']); ?>">
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($property['owner_phone']); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Bookings -->
                        <?php if (!empty($propertyBookings)): ?>
                        <div class="card" style="margin-bottom: 20px;">
                            <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                                <h5><i class="fas fa-calendar-check"></i> Recent Bookings</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($propertyBookings as $booking): ?>
                                    <div class="review-card">
                                        <img src="../../../<?php echo htmlspecialchars($booking['student_photo'] ?? 'assets/images/profiles/default.jpg'); ?>" alt="Student" class="review-avatar">
                                        <div class="review-content">
                                            <div class="review-header">
                                                <span class="review-author"><?php echo htmlspecialchars($booking['student_name']); ?></span>
                                                <span class="review-date"><?php echo date('M j, Y', strtotime($booking['start_date'])); ?></span>
                                            </div>
                                            <div>
                                                <span class="status <?php echo strtolower($booking['status']); ?>"><?php echo ucfirst($booking['status']); ?></span>
                                            </div>
                                            <div>
                                                <?php echo date('M j, Y', strtotime($booking['start_date'])); ?> - 
                                                <?php echo date('M j, Y', strtotime($booking['end_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Recent Reviews -->
                        <?php if (!empty($propertyReviews)): ?>
                        <div class="card">
                            <div class="card-header" style="background: var(--light-color); color: var(--dark-color);">
                                <h5><i class="fas fa-star"></i> Recent Reviews</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($propertyReviews as $review): ?>
                                    <div class="review-card">
                                        <img src="../../../<?php echo htmlspecialchars($review['student_photo'] ?? 'assets/images/profiles/default.jpg'); ?>" alt="Student" class="review-avatar">
                                        <div class="review-content">
                                            <div class="review-header">
                                                <span class="review-author"><?php echo htmlspecialchars($review['student_name']); ?></span>
                                                <span class="review-date"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                                            </div>
                                            <div class="review-rating">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $review['rating'] ? '' : '-empty'; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <p><?php echo htmlspecialchars($review['comment']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.js"></script>
    <script>
        // Initialize image gallery swiper
        const swiper = new Swiper('.swiper', {
            loop: true,
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
        });
        
        // Confirm delete
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this property? This action cannot be undone.')) {
                window.location.href = 'delete.php?id=' + id;
            }
        }
    </script>
</body>
</html>