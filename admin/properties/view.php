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
               u.phone_number as owner_phone, u.profile_picture as owner_photo,
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


    
    // Get room details
    $roomStmt = $db->prepare("SELECT * FROM property_rooms WHERE property_id = ?");
    $roomStmt->execute([$propertyId]);
    $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get first room for capacity (assuming all rooms have same capacity)
    $capacity = $rooms[0]['capacity'] ?? 2;
    
    // Get occupancy stats
    $occupancyStmt = $db->prepare("
        SELECT COUNT(*) AS total_rooms, 
               SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_rooms,
               SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms
        FROM property_rooms
        WHERE property_id = ?
    ");
    $occupancyStmt->execute([$propertyId]);
    $occupancy = $occupancyStmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$property['owner_id']]);
$owner = $stmt->fetch();

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

$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');
    
    // Get features
    $features = $db->prepare("SELECT feature_name FROM property_features WHERE property_id = ?");
    $features->execute([$propertyId]);
    $propertyFeatures = $features->fetchAll(PDO::FETCH_COLUMN);
    
    // Get virtual tour
    $tourStmt = $db->prepare("SELECT image_url FROM property_images WHERE property_id = ? AND is_virtual_tour = 1 LIMIT 1");
    $tourStmt->execute([$propertyId]);
    $virtualTour = $tourStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Error fetching property: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Property | landlords&Tenant Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #2c3e50;
            --danger: #f72585;
            --success: #4cc9f0;
            --warning: #f8961e;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --white: #ffffff;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            padding-top: 70px;
        }
        
        .top-nav {
            position: fixed;
            top: 0;
            left: 280px;
            right: 0;
            height: 70px;
            background: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            z-index: 999;
        }
        
        .card {
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            background: var(--secondary);
            color: var(--white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .property-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .detail-card {
            background: var(--light);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .detail-card h5 {
            color: var(--secondary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .detail-card h5 i {
            color: var(--primary);
        }
        
        .detail-card p {
            font-size: 1.1rem;
        }
        
        .features-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .feature-badge {
            background: var(--light);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .virtual-tour-container {
            margin-top: 20px;
            text-align: center;
        }
        
        .virtual-tour-container iframe,
        .virtual-tour-container img {
            width: 100%;
            max-width: 600px;
            height: 400px;
            border-radius: 8px;
            border: 1px solid var(--light-gray);
        }
        
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .room-card {
            background: var(--white);
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .room-number {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .room-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .status-available {
            background: #e3f9ee;
            color: #00a854;
        }
        
        .status-occupied {
            background: #fff0f0;
            color: #f5222d;
        }
        
        .status-maintenance {
            background: #fff7e6;
            color: #fa8c16;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn i {
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .owner-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 15px;
        }
        
        .owner-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--light-gray);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Top Navigation -->
        <nav class="top-nav">
            <div class="nav-left">
                <h3>Property Details</h3>
            </div>
            <div class="nav-right">
                <a href="index.php" class="btn">
                    <i class="fas fa-arrow-left"></i> Back to Properties
                </a>
            </div>
        </nav>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><?php echo htmlspecialchars($property['property_name']); ?></h2>
                    <div class="action-buttons">
                        <a href="edit.php?id=<?php echo $propertyId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Property
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="property-details-grid">
                        <div class="detail-card">
                            <h5><i class="fas fa-tag"></i> Category</h5>
                            <p><?php echo htmlspecialchars($property['category_name']); ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-dollar-sign"></i> Price per Student</h5>
                            <p>GHS <?php echo number_format($property['price'], 2); ?></p>
                            <small class="text-muted">Per student per room</small>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-map-marker-alt"></i> Location</h5>
                            <p><?php echo htmlspecialchars($property['location']); ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-door-open"></i> Room Capacity</h5>
                            <p><?php echo htmlspecialchars($capacity); ?> students per room</p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-bed"></i> Bedrooms</h5>
                            <p><?php echo $property['bedrooms'] ?? 'N/A'; ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-bath"></i> Bathrooms</h5>
                            <p><?php echo $property['bathrooms'] ?? 'N/A'; ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-ruler-combined"></i> Area</h5>
                            <p><?php echo $property['area_sqft'] ? number_format($property['area_sqft']) . ' sqft' : 'N/A'; ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-calendar-alt"></i> Year Built</h5>
                            <p><?php echo $property['year_built'] ?? 'N/A'; ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-car"></i> Parking</h5>
                            <p><?php echo $property['parking'] ?? 'N/A'; ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-info-circle"></i> Status</h5>
                            <p><?php echo ucfirst($property['status']); ?></p>
                        </div>
                        
                        <div class="detail-card">
                            <h5><i class="fas fa-chart-pie"></i> Occupancy</h5>
                            <p>
                                <?php echo $occupancy['occupied_rooms'] ?? 0; ?> Occupied / 
                                <?php echo $occupancy['total_rooms'] ?? 0; ?> Total Rooms
                            </p>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-align-left"></i> Description</h5>
                        <p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
                    </div>
                    <?php if (!empty($propertyFeatures)): ?>
                        <div class="detail-card">
                            <h5><i class="fas fa-star"></i> Features</h5>
                            <div class="features-list">
                                <?php foreach ($propertyFeatures as $feature): ?>
                                    <span class="feature-badge"><?php echo htmlspecialchars($feature); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($virtualTour): ?>
                        <div class="detail-card virtual-tour-container">
                            <h5><i class="fas fa-vr-cardboard"></i> Virtual Tour</h5>
                            <?php if (strpos($virtualTour['image_url'], '.mp4') !== false ||
                                      strpos($virtualTour['image_url'], '.webm') !== false): ?>
                                <video controls>
                                    <source src="../../../<?php echo htmlspecialchars($virtualTour['image_url']); ?>" type="video/mp4">
                                    Your browser does not support the video tag.
                                </video>
                            <?php else: ?>
                                <img src="../../../<?php echo htmlspecialchars($virtualTour['image_url']); ?>" alt="Virtual Tour">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-door-open"></i> Rooms</h5>
                        <div class="room-grid">
                            <?php foreach ($rooms as $room): ?>
                                <div class="room-card">
                                    <div class="room-number"><?php echo htmlspecialchars($room['room_number']); ?></div>
                                    <div>Capacity: <?php echo htmlspecialchars($room['capacity']); ?> students</div>
                                    <div class="room-status status-<?php echo strtolower($room['status']); ?>">
                                        <?php echo ucfirst($room['status']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <h5><i class="fas fa-user-tie"></i> Property Owner</h5>
                        <div class="owner-info">
                             <?php if (!empty($profile_pic_path)): ?>
                                <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar me-4" alt="Profile Picture">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder me-4">
                                    <?= substr($owner['username'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h5><?php echo htmlspecialchars($property['owner_name']); ?></h5>
                                <p><?php echo htmlspecialchars($property['owner_email']); ?></p>
                                <p><?php echo htmlspecialchars($property['owner_phone']); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="edit.php?id=<?php echo $propertyId; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Property
                        </a>
                        <a href="index.php" class="btn">
                            <i class="fas fa-arrow-left"></i> Back to Properties
                        </a>
                    </div>
                               