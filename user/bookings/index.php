<<?php
// bookings/new.php - Create New Booking
session_start();
require_once __DIR__. '../../../config/database.php';

// Check if user is a student
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get student details including credit score
$stmt = $pdo->prepare("SELECT users.*, 
                      (SELECT credit_score FROM users WHERE id = ?) AS credit_score 
                      FROM users WHERE id = ?");
$stmt->execute([$student_id, $student_id]);
$student = $stmt->fetch();

// Get property ID from URL
$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

// Initialize variables
$property = null;
$room = null;
$available_rooms = [];
$error = '';
$success = '';

// Handle booking cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = (int)$_POST['booking_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->execute([$booking_id, $student_id]);
        $booking = $stmt->fetch();
        
        if ($booking) {
            // Update booking status
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
            $stmt->execute([$booking_id]);
            
            // Update room availability
            if ($booking['room_id']) {
                $stmt = $pdo->prepare("
                    UPDATE property_rooms 
                    SET available_spots = available_spots + 1 
                    WHERE id = ?
                ");
                $stmt->execute([$booking['room_id']]);
                
                // Update room status if needed
                $stmt = $pdo->prepare("
                    UPDATE property_rooms
                    SET status = CASE 
                        WHEN available_spots > 0 THEN 'available'
                        ELSE 'occupied'
                    END
                    WHERE id = ?
                ");
                $stmt->execute([$booking['room_id']]);
            }
            
            $pdo->commit();
            $success = "Booking #$booking_id has been cancelled successfully!";
        } else {
            $error = "Booking not found or you don't have permission to cancel it.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error cancelling booking: " . $e->getMessage();
    }
}

// Get current bookings for this student
$bookings_stmt = $pdo->prepare("
    SELECT b.*, p.property_name, pr.room_number, p.location
    FROM bookings b
    JOIN property p ON b.property_id = p.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    WHERE b.user_id = ? AND b.status IN ('pending', 'confirmed', 'paid')
");
$bookings_stmt->execute([$student_id]);
$current_bookings = $bookings_stmt->fetchAll();

// Get property details
if ($property_id) {
    $stmt = $pdo->prepare("SELECT * FROM property WHERE id = ? AND approved = 1 AND deleted = 0");
    $stmt->execute([$property_id]);
    $property = $stmt->fetch();
    
    if (!$property) {
        $error = "Property not found or not available.";
    } else {
        // Get available rooms for this property with levy verification
        $stmt = $pdo->prepare("
            SELECT pr.*, 
                   (pr.capacity - (SELECT COUNT(b.id) FROM bookings b 
                                   WHERE b.room_id = pr.id 
                                   AND b.status IN ('confirmed', 'paid'))) as available_spots
            FROM property_rooms pr
            WHERE pr.property_id = ? 
            AND pr.levy_payment_status = 'approved' 
            AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
            AND pr.status = 'available'
            HAVING available_spots > 0
        ");
        $stmt->execute([$property_id]);
        $available_rooms = $stmt->fetchAll();
        
        // Get specific room if requested with gender matching
        if ($room_id) {
            $stmt = $pdo->prepare("
                SELECT pr.*, 
                       (pr.capacity - (SELECT COUNT(b.id) FROM bookings b 
                                       WHERE b.room_id = pr.id 
                                       AND b.status IN ('confirmed', 'paid'))) as available_spots
                FROM property_rooms pr
                WHERE pr.id = ? 
                AND pr.property_id = ?
                AND pr.levy_payment_status = 'approved' 
                AND (pr.levy_expiry_date IS NULL OR pr.levy_expiry_date >= CURDATE())
                AND pr.status = 'available'
                AND (pr.gender = ? OR pr.gender IS NULL)
            ");
            $stmt->execute([$room_id, $property_id, $student['sex']]);
            $room = $stmt->fetch();
            
            if (!$room || $room['available_spots'] <= 0) {
                $error = "Selected room is not available or doesn't match your gender.";
                $room = null;
            }
        }
    }
} else {
    $error = "No property selected.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    $start_date = $_POST['start_date'];
    $duration = (int)$_POST['duration'];
    $special_requests = trim($_POST['special_requests']);
    $selected_room_id = (int)$_POST['room_id'];
    
    // Validate inputs
    if (empty($start_date)) {
        $error = "Start date is required.";
    } elseif ($duration < 1 || $duration > 12) {
        $error = "Duration must be between 1 and 12 months.";
    } elseif (!$selected_room_id) {
        $error = "Please select a room.";
    } else {
        // Calculate end date
        $end_date = date('Y-m-d', strtotime($start_date . " +$duration months"));
        
        // Check room availability again with gender matching
        $stmt = $pdo->prepare("
            SELECT pr.capacity, pr.gender, 
                   (SELECT COUNT(b.id) FROM bookings b 
                    WHERE b.room_id = pr.id 
                    AND b.status IN ('confirmed', 'paid')) as current_occupants
            FROM property_rooms pr
            WHERE pr.id = ?
            AND (pr.gender = ? OR pr.gender IS NULL)
        ");
        $stmt->execute([$selected_room_id, $student['sex']]);
        $room_check = $stmt->fetch();
        
        if ($room_check && ($room_check['current_occupants'] < $room_check['capacity'])) {
            // Calculate total price
            $total_price = $property['price'] * $duration;
            
            // Create booking
            try {
                $pdo->beginTransaction();
                
                // Insert booking
                $stmt = $pdo->prepare("
                    INSERT INTO bookings 
                    (user_id, property_id, room_id, start_date, end_date, duration_months, special_requests, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $student_id,
                    $property_id,
                    $selected_room_id,
                    $start_date,
                    $end_date,
                    $duration,
                    $special_requests
                ]);
                
                $booking_id = $pdo->lastInsertId();
                
                // Update room status and available spots
                $stmt = $pdo->prepare("
                    UPDATE property_rooms 
                    SET available_spots = available_spots - 1 
                    WHERE id = ?
                ");
                $stmt->execute([$selected_room_id]);
                
                // Update room status if it becomes fully occupied
                $stmt = $pdo->prepare("
                    UPDATE property_rooms
                    SET status = CASE 
                        WHEN available_spots <= 1 THEN 'occupied'
                        ELSE 'available'
                    END
                    WHERE id = ?
                ");
                $stmt->execute([$selected_room_id]);
                
                $pdo->commit();
                
                $success = "Booking created successfully!";
                // Redirect to booking details or payment page
                header("Location: receipt.php?id=$booking_id");
                exit();
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Error creating booking: " . $e->getMessage();
            }
        } else {
            $error = "Selected room is no longer available or doesn't match your gender.";
        }
    }
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking | Landlords&Tenant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            padding-top: 20px;
        }
        
        .booking-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .booking-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 20px;
            font-weight: 600;
            color: var(--secondary-color);
            border-radius: 10px 10px 0 0 !important;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .property-img {
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            width: 100%;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .room-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .room-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
        }
        
        .room-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .room-card .badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.8rem;
            padding: 5px 10px;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }
        
        .summary-card {
            background-color: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 20px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .summary-total {
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--secondary-color);
        }
        
        .room-capacity {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }
        
        .capacity-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .available {
            background-color: var(--success-color);
        }
        
        .occupied {
            background-color: var(--warning-color);
        }
        
        .full {
            background-color: var(--accent-color);
        }
        
        .alert {
            border-radius: 8px;
        }
        
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .bookings-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            .room-grid {
                grid-template-columns: 1fr;
            }
            
            .property-img {
                height: 150px;
            }
            
            .bookings-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        .credit-score {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            margin-left: 10px;
        }
        
        .gender-match {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            background-color: #4CAF50;
            color: white;
            font-size: 0.85rem;
            margin-top: 5px;
        }
        
        .gender-mismatch {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            background-color: #f44336;
            color: white;
            font-size: 0.85rem;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="booking-container">
        <!-- Header -->
        <div class="booking-header text-center">
            <div class="d-flex justify-content-center mb-4">
                <a href="../../" class="d-inline-block">
                    <img src="../../assets/images/logo-removebg-preview.png" alt="UniHomes Logo" height="60">
                </a>
            </div>
            <h1 class="mb-3">New Accommodation Booking</h1>
            <p class="lead">Complete your booking in just a few simple steps</p>
        </div>
        
        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <!-- Current Bookings Section -->
        <?php if (count($current_bookings) > 0): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h4 mb-0">My Current Bookings</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover bookings-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Property</th>
                                    <th>Room</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_bookings as $booking): ?>
                                    <tr>
                                        <td>#<?= $booking['id'] ?></td>
                                        <td><?= htmlspecialchars($booking['property_name']) ?></td>
                                        <td>
                                            <?= $booking['room_number'] ? 'Room ' . htmlspecialchars($booking['room_number']) : 'N/A' ?>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($booking['start_date'])) ?></td>
                                        <td><?= date('M d, Y', strtotime($booking['end_date'])) ?></td>
                                        <td>
                                            <span class="status-badge bg-<?= 
                                                $booking['status'] === 'confirmed' ? 'success' : 
                                                ($booking['status'] === 'paid' ? 'primary' : 'warning')
                                            ?>">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <button type="submit" name="cancel_booking" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-times me-1"></i> Cancel
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Left Column: Booking Form -->
            <div class="col-lg-8">
                <?php if ($property): ?>
                    <!-- Property Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h4 mb-0">Property Details</h2>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <?php
                                    // Get property image
                                    $image_stmt = $pdo->prepare("SELECT image_url FROM property_images WHERE property_id = ? LIMIT 1");
                                    $image_stmt->execute([$property['id']]);
                                    $image = $image_stmt->fetch();
                                    ?>
                                    <img src="<?= $image ? '../../uploads/'.htmlspecialchars($image['image_url']) : '../../assets/images/default-property.jpg' ?>" 
                                         alt="<?= htmlspecialchars($property['property_name']) ?>" class="property-img">
                                </div>
                                <div class="col-md-8">
                                    <h3 class="mb-2"><?= htmlspecialchars($property['property_name']) ?></h3>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-map-marker-alt me-2"></i>
                                        <?= htmlspecialchars($property['location']) ?>
                                    </p>
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="me-4">
                                            <i class="fas fa-bed me-1"></i>
                                            <?= $property['bedrooms'] ? $property['bedrooms'] . ' Beds' : 'N/A' ?>
                                        </div>
                                        <div class="me-4">
                                            <i class="fas fa-bath me-1"></i>
                                            <?= $property['bathrooms'] ? $property['bathrooms'] . ' Baths' : 'N/A' ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-expand-arrows-alt me-1"></i>
                                            <?= $property['area_sqft'] ? number_format($property['area_sqft']) . ' sq.ft.' : 'N/A' ?>
                                        </div>
                                    </div>
                                    <p><?= htmlspecialchars($property['description']) ?></p>
                                    <h4 class="text-primary">GHS <?= number_format($property['price'], 2) ?> <small class="text-muted">per month</small></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Room Selection -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h4 mb-0">Select a Room</h2>
                            <p class="mb-0">Rooms marked with <span class="gender-match">Gender Match</span> are compatible with your profile</p>
                        </div>
                        <div class="card-body">
                            <?php if (count($available_rooms) > 0): ?>
                                <div class="room-grid">
                                    <?php foreach ($available_rooms as $room_item): 
                                        $is_selected = ($room && $room['id'] == $room_item['id']) || (!$room && $room_item['available_spots'] > 0);
                                        $capacity_percent = ($room_item['capacity'] - $room_item['available_spots']) / $room_item['capacity'] * 100;
                                        $gender_match = (!$room_item['gender'] || $room_item['gender'] === $student['sex']);
                                    ?>
                                        <div class="card room-card <?= $is_selected ? 'selected' : '' ?>" 
                                             data-room-id="<?= $room_item['id'] ?>"
                                             data-gender-match="<?= $gender_match ? 'true' : 'false' ?>">
                                            <div class="card-body">
                                                <h5 class="card-title">Room <?= htmlspecialchars($room_item['room_number']) ?></h5>
                                                <div class="mb-2">
                                                    <span class="badge bg-<?= 
                                                        $room_item['available_spots'] == $room_item['capacity'] ? 'success' : 
                                                        ($room_item['available_spots'] > 0 ? 'warning' : 'danger')
                                                    ?>">
                                                        <?= 
                                                            $room_item['available_spots'] == $room_item['capacity'] ? 'Available' : 
                                                            ($room_item['available_spots'] > 0 ? 'Partially Booked' : 'Fully Booked')
                                                        ?>
                                                    </span>
                                                    <?php if ($gender_match): ?>
                                                        <span class="gender-match">Gender Match</span>
                                                    <?php else: ?>
                                                        <span class="gender-mismatch">Gender Mismatch</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="room-capacity">
                                                    <?php for ($i = 0; $i < $room_item['capacity']; $i++): ?>
                                                        <div class="capacity-dot <?= 
                                                            $i < ($room_item['capacity'] - $room_item['available_spots']) ? 'occupied' : 'available'
                                                        ?>"></div>
                                                    <?php endfor; ?>
                                                </div>
                                                <p class="mb-1">
                                                    <i class="fas fa-user-friends me-2"></i>
                                                    Capacity: <?= $room_item['capacity'] ?> students
                                                </p>
                                                <p class="mb-1">
                                                    <i class="fas fa-user-check me-2"></i>
                                                    Available spots: <?= $room_item['available_spots'] ?>
                                                </p>
                                                <p class="mb-0">
                                                    <i class="fas fa-venus-mars me-2"></i>
                                                    Gender: <?= ucfirst($room_item['gender'] ?? 'any') ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No available rooms found for this property.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Booking Form -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="h4 mb-0">Booking Details</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="bookingForm">
                                <input type="hidden" name="room_id" id="selectedRoomId" value="<?= $room ? $room['id'] : '' ?>">
                                
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <div class="d-flex align-items-center p-3 bg-light rounded">
                                            <?php if (!empty($student['profile_picture'])): ?>
                                                <img src="<?= getProfilePicturePath($student['profile_picture']) ?>" 
                                                     class="profile-avatar me-3" alt="Profile Picture">
                                            <?php else: ?>
                                                <div class="profile-avatar bg-primary text-white d-flex align-items-center justify-content-center me-3">
                                                    <?= substr($student['username'], 0, 1) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h5 class="mb-0"><?= htmlspecialchars($student['username']) ?>
                                                    <span class="credit-score" title="Your credit score">
                                                        <?= number_format($student['credit_score'], 0) ?> CR
                                                    </span>
                                                </h5>
                                                <p class="text-muted mb-0">Student • <?= ucfirst($student['sex']) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 bg-light rounded">
                                            <h5 class="mb-0">Room Selected</h5>
                                            <p class="mb-0" id="selectedRoomText">
                                                <?= $room ? 'Room '.htmlspecialchars($room['room_number']) : 'No room selected' ?>
                                            </p>
                                            <p class="mb-0" id="selectedRoomGender">
                                                <?= $room ? 'Gender: '.ucfirst($room['gender']) : '' ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="start_date" class="form-label">Move-in Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                           min="<?= date('Y-m-d') ?>" required
                                           value="<?= isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : '' ?>">
                                    <div class="form-text">Select your preferred move-in date</div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="duration" class="form-label">Duration</label>
                                    <select class="form-select" id="duration" name="duration" required>
                                        <option value="">Select duration</option>
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <option value="<?= $i ?>" <?= isset($_POST['duration']) && $_POST['duration'] == $i ? 'selected' : '' ?>>
                                                <?= $i ?> month<?= $i > 1 ? 's' : '' ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <div class="form-text">Select how many months you want to stay</div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="special_requests" class="form-label">Special Requests</label>
                                    <textarea class="form-control" id="special_requests" name="special_requests" 
                                              rows="3" placeholder="Any special requests or requirements..."><?= isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : '' ?></textarea>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <a href="../search/" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Search
                                    </a>
                                    <button type="submit" name="create_booking" class="btn btn-primary" id="submitBooking">
                                        <i class="fas fa-calendar-check me-2"></i> Complete Booking
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Property not found or not available for booking.
                    </div>
                    <div class="text-center mt-4">
                        <a href="../search/" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i> Find Accommodation
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Booking Summary -->
            <div class="col-lg-4">
                <div class="summary-card">
                    <h3 class="mb-4">Booking Summary</h3>
                    
                    <div class="summary-item">
                        <span>Property:</span>
                        <span><?= $property ? htmlspecialchars($property['property_name']) : 'N/A' ?></span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Room:</span>
                        <span id="summaryRoom"><?= $room ? 'Room '.htmlspecialchars($room['room_number']) : 'Not selected' ?></span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Monthly Rate:</span>
                        <span id="monthlyRate"><?= $property ? 'GHS '.number_format($property['price'], 2) : 'N/A' ?></span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Duration:</span>
                        <span id="summaryDuration">Not selected</span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Move-in Date:</span>
                        <span id="summaryStartDate">Not selected</span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Move-out Date:</span>
                        <span id="summaryEndDate">Not selected</span>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="summary-item summary-total">
                        <span>Total Price:</span>
                        <span id="totalPrice">GHS 0.00</span>
                    </div>
                    
                    <div class="mt-4">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Your booking will be confirmed after payment is completed.
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Rooms with gender mismatch cannot be booked.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Room selection
            const roomCards = document.querySelectorAll('.room-card');
            const selectedRoomInput = document.getElementById('selectedRoomId');
            const selectedRoomText = document.getElementById('selectedRoomText');
            const selectedRoomGender = document.getElementById('selectedRoomGender');
            const summaryRoom = document.getElementById('summaryRoom');
            const submitBtn = document.getElementById('submitBooking');
            
            roomCards.forEach(card => {
                card.addEventListener('click', function() {
                    // Check gender match
                    const genderMatch = this.getAttribute('data-gender-match') === 'true';
                    
                    if (!genderMatch) {
                        alert('This room has a gender requirement that doesn\'t match your profile. Please select another room.');
                        return;
                    }
                    
                    // Remove selected class from all cards
                    roomCards.forEach(c => c.classList.remove('selected'));
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                    
                    // Get room ID
                    const roomId = this.getAttribute('data-room-id');
                    const roomNumber = this.querySelector('.card-title').textContent;
                    const roomGender = this.querySelector('.gender-match') ? 
                        this.querySelector('.gender-match').textContent : 
                        this.querySelector('.gender-mismatch').textContent;
                    
                    // Update form and summary
                    selectedRoomInput.value = roomId;
                    selectedRoomText.textContent = roomNumber;
                    selectedRoomGender.textContent = roomGender;
                    summaryRoom.textContent = roomNumber;
                    
                    // Recalculate total price
                    calculateTotalPrice();
                });
            });
            
            // Date and duration inputs
            const startDateInput = document.getElementById('start_date');
            const durationInput = document.getElementById('duration');
            const summaryStartDate = document.getElementById('summaryStartDate');
            const summaryDuration = document.getElementById('summaryDuration');
            const summaryEndDate = document.getElementById('summaryEndDate');
            const totalPrice = document.getElementById('totalPrice');
            
            // Add event listeners
            startDateInput.addEventListener('change', updateSummary);
            durationInput.addEventListener('change', updateSummary);
            
            function updateSummary() {
                // Update start date
                if (startDateInput.value) {
                    const date = new Date(startDateInput.value);
                    summaryStartDate.textContent = date.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                } else {
                    summaryStartDate.textContent = 'Not selected';
                }
                
                // Update duration
                if (durationInput.value) {
                    summaryDuration.textContent = `${durationInput.value} month${durationInput.value > 1 ? 's' : ''}`;
                } else {
                    summaryDuration.textContent = 'Not selected';
                }
                
                // Calculate end date
                if (startDateInput.value && durationInput.value) {
                    const startDate = new Date(startDateInput.value);
                    const endDate = new Date(startDate);
                    endDate.setMonth(endDate.getMonth() + parseInt(durationInput.value));
                    
                    summaryEndDate.textContent = endDate.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                } else {
                    summaryEndDate.textContent = 'Not selected';
                }
                
                // Calculate total price
                calculateTotalPrice();
            }
            
            function calculateTotalPrice() {
                if (!selectedRoomInput.value || !durationInput.value || !startDateInput.value) {
                    totalPrice.textContent = 'GHS 0.00';
                    return;
                }
                
                // Get monthly rate
                const monthlyRate = parseFloat(<?= $property ? $property['price'] : 0 ?>);
                const duration = parseInt(durationInput.value);
                
                // Calculate total
                const total = monthlyRate * duration;
                totalPrice.textContent = `GHS ${total.toFixed(2)}`;
            }
            
            // Initialize summary
            updateSummary();
            
            // Prevent form submission if gender mismatch
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                const selectedCard = document.querySelector('.room-card.selected');
                if (selectedCard) {
                    const genderMatch = selectedCard.getAttribute('data-gender-match') === 'true';
                    if (!genderMatch) {
                        e.preventDefault();
                        alert('You cannot book a room with gender requirements that don\'t match your profile.');
                    }
                }
            });
        });
    </script>
</body>
</html>