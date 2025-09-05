<?php
// add_room.php - Add new room to property with incremental payment
session_start();
require_once '../config/database.php';

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get admin phone number for Paystack
$admin_stmt = $pdo->prepare("SELECT phone_number FROM users WHERE status = 'admin' LIMIT 1");
$admin_stmt->execute();
$admin = $admin_stmt->fetch();
$admin_phone = $admin['phone_number'] ?? '';

// Get property ID from query parameter
$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

// Verify property belongs to this owner
if ($property_id > 0) {
    $property_stmt = $pdo->prepare("SELECT * FROM property WHERE id = ? AND owner_id = ? AND deleted = 0");
    $property_stmt->execute([$property_id, $owner_id]);
    $property = $property_stmt->fetch();
    
    if (!$property) {
        $_SESSION['error'] = "Property not found or doesn't belong to you";
        header('Location: property_dashboard.php');
        exit();
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    // Validate inputs
    $errors = [];
    $property_id = (int)$_POST['property_id'];
    $room_number = trim($_POST['room_number']);
    $capacity = (int)$_POST['capacity'];
    $gender = $_POST['gender'];
    
    // Basic validation
    if (empty($room_number)) {
        $errors[] = "Room number is required";
    }
    
    if ($capacity < 1 || $capacity > 10) {
        $errors[] = "Capacity must be between 1 and 10";
    }
    
    if (!in_array($gender, ['male', 'female'])) {
        $errors[] = "Invalid gender selection";
    }
    
    // Verify property belongs to owner
    $property_stmt = $pdo->prepare("SELECT * FROM property WHERE id = ? AND owner_id = ? AND deleted = 0");
    $property_stmt->execute([$property_id, $owner_id]);
    $property = $property_stmt->fetch();
    
    if (!$property) {
        $errors[] = "Property not found or doesn't belong to you";
    }
    
    // Check if room number already exists for this property
    $room_check = $pdo->prepare("SELECT id FROM property_rooms WHERE property_id = ? AND room_number = ?");
    $room_check->execute([$property_id, $room_number]);
    
    if ($room_check->fetch()) {
        $errors[] = "A room with this number already exists in the property";
    }
    
    // If no errors, proceed
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Insert the new room with pending payment status
            $insert_stmt = $pdo->prepare("
                INSERT INTO property_rooms 
                (property_id, room_number, capacity, gender, status, levy_payment_status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 'available', 'pending', NOW(), NOW())
            ");
            $insert_stmt->execute([$property_id, $room_number, $capacity, $gender]);
            
            // Get the count of pending rooms for this property (should be 1 since we just added it)
            $pending_count_stmt = $pdo->prepare("
                SELECT COUNT(*) as pending_count 
                FROM property_rooms 
                WHERE property_id = ? AND levy_payment_status = 'pending'
            ");
            $pending_count_stmt->execute([$property_id]);
            $pending_count = $pending_count_stmt->fetch()['pending_count'];
            
            // Calculate amount due (only for the newly added room)
            $room_fee = 50; // GHS 50 per room
            $total_amount_due = $room_fee;
            
            // Store payment data in session
            $_SESSION['room_payment'] = [
                'owner_id' => $owner_id,
                'property_id' => $property_id,
                'amount' => $total_amount_due,
                'reference' => 'ROOM_' . time() . '_' . bin2hex(random_bytes(4)),
                'pending_rooms' => $pending_count,
                'expired_rooms' => 0,
                'discount' => 0,
                'admin_phone' => $admin_phone,
                'new_room_id' => $pdo->lastInsertId(), // Store the new room ID
                'room_number' => $room_number,
                'capacity' => $capacity,
                'gender' => $gender
            ];
            
            $pdo->commit();
            
            // Redirect to payment processing
            header("Location: property_dashboard.php");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
    
    // If we got here, there were errors
    $_SESSION['errors'] = $errors;
    header("Location: add_room.php?property_id=$property_id");
    exit();
}

// Get properties owned by this user for dropdown
$properties_stmt = $pdo->prepare("
    SELECT id, property_name 
    FROM property 
    WHERE owner_id = ? AND deleted = 0 
    ORDER BY property_name ASC
");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

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

// Get unread notifications
$notifications_stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
");
$notifications_stmt->execute([$owner_id]);
$unread_notifications = $notifications_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Room | Landlords&Tenant</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--secondary-color);
            cursor: pointer;
            display: none;
        }
        
        .user-controls {
            display: flex;
            align-items: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            transition: all var(--transition-speed) ease;
            position: fixed;
            top: var(--header-height);
            bottom: 0;
            left: 0;
            overflow-y: auto;
        }
        
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu li a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-menu li a.active {
            background-color: var(--primary-color);
        }
        
        .sidebar-menu li a i {
            width: 24px;
            margin-right: 10px;
            text-align: center;
        }
        
        .sidebar-menu li a span {
            transition: opacity var(--transition-speed) ease;
        }
        
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left var(--transition-speed) ease;
        }
        
        .dashboard-header {
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
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .card-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: var(--box-shadow);
        }
        
        .profile-avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            border: 3px solid white;
            box-shadow: var(--box-shadow);
        }
        
        /* Mobile Styles */
        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .sidebar-menu li a span {
                opacity: 1;
            }
            
            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar.collapsed .sidebar-menu li a span {
                opacity: 0;
                width: 0;
                display: none;
            }
            
            .sidebar.collapsed .sidebar-menu li a i {
                margin-right: 0;
                font-size: 1.2rem;
            }
            
            .sidebar.collapsed .sidebar-menu li a {
                justify-content: center;
                padding: 15px 10px;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1.5rem;
            }
            
            .profile-avatar, .profile-avatar-placeholder {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1rem;
            }
        }
        
        /* Form styles */
        .form-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--card-shadow);
        }
        
        .form-title {
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-color);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: var(--border-radius);
        }
        
        .payment-info {
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 2rem;
            border-left: 4px solid var(--warning-color);
        }
        
        .payment-info h5 {
            color: var(--secondary-color);
        }
        
        .payment-info .price {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--success-color);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../assets/images/landlords-logo2.png" alt="landlords&tenants Logo">
                <span>landlords&tenants</span>
            </a>
            
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="profile-avatar-placeholder">
                                <?= substr($owner['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($owner['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="logout.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-menu">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                    <li><a href="property_dashboard.php"><i class="fas fa-home"></i> <span>My Properties</span></a></li>
                    <li><a href="bookings/"><i class="fas fa-calendar-alt"></i> <span>Bookings</span></a></li>
                    <li><a href="payments/"><i class="fas fa-wallet"></i> <span>Payments</span></a></li>
                    <li><a href="reviews/"><i class="fas fa-star"></i> <span>Reviews</span></a></li>
                    <li><a href="chat/"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
                    <li><a href="maintenance/"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                    <li><a href="virtual-tours/"><i class="fas fa-video"></i> <span>Virtual Tours</span></a></li>
                    <li><a href="roommate-matching/"><i class="fas fa-users"></i> <span>Roommate Matching</span></a></li>
                    <li><a href="announcement.php"><i class="fas fa-bullhorn"></i> <span>Announcements</span></a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="container">
                <!-- Welcome Header -->
                <div class="dashboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8 d-flex align-items-center">
                            <?php if (!empty($profile_pic_path)): ?>
                                <img src="<?= htmlspecialchars($profile_pic_path) ?>" class="profile-avatar me-4" alt="Profile Picture">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder me-4">
                                    <?= substr($owner['username'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h2>Add New Room</h2>
                                <p class="mb-0">Add a new room to your property listing</p>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-flex align-items-center justify-content-end">
                                <div class="me-3 position-relative">
                                    <a href="notifications.php" class="text-white position-relative">
                                        <i class="fas fa-bell fa-lg"></i>
                                        <?php if(count($unread_notifications) > 0): ?>
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                            <?= count($unread_notifications) ?>
                                        </span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-user-tie me-1"></i> Property Owner
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Section -->
                <div class="row">
                    <div class="col-lg-8 mx-auto">
                        <div class="form-section">
                            <h3 class="form-title"><i class="fas fa-door-open me-2"></i> New Room Details</h3>
                            
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($_SESSION['errors'] as $error): ?>
                                            <li><?= htmlspecialchars($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php unset($_SESSION['errors']); ?>
                            <?php endif; ?>
                            
                            <form method="POST" id="addRoomForm">
                                <input type="hidden" name="property_id" value="<?= $property_id ?>">
                                
                                <div class="mb-3">
                                    <label for="propertySelect" class="form-label">Property</label>
                                    <select class="form-select" id="propertySelect" name="property_id" required <?= $property_id ? 'disabled' : '' ?>>
                                        <?php if ($property_id): ?>
                                            <option value="<?= $property_id ?>" selected><?= htmlspecialchars($property['property_name']) ?></option>
                                        <?php else: ?>
                                            <option value="">Select Property</option>
                                            <?php foreach ($properties as $prop): ?>
                                                <option value="<?= $prop['id'] ?>"><?= htmlspecialchars($prop['property_name']) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ($property_id): ?>
                                        <input type="hidden" name="property_id" value="<?= $property_id ?>">
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="roomNumber" class="form-label">Room Number/Name</label>
                                    <input type="text" class="form-control" id="roomNumber" name="room_number" required>
                                    <div class="form-text">Enter a unique identifier for this room (e.g., "Room 1", "Master Bedroom")</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="capacity" class="form-label">Capacity</label>
                                    <select class="form-select" id="capacity" name="capacity" required>
                                        <option value="1">1 student</option>
                                        <option value="2" selected>2 students</option>
                                        <option value="3">3 students</option>
                                        <option value="4">4 students</option>
                                        <option value="5">5 students</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="gender" class="form-label">Gender Specification</label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="male">Male Only</option>
                                        <option value="female">Female Only</option>
                                    </select>
                                </div>
                                
                                <div class="payment-info">
                                    <h5><i class="fas fa-info-circle me-2"></i>Payment Information</h5>
                                    <p>Adding a new room requires a one-time levy payment of <span class="price">GHS 50.00</span>.</p>
                                    <p>This payment covers the room listing for <strong>1 year</strong> and will be processed after you submit this form.</p>
                                    <div class="alert alert-warning mt-3">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        The room will not be visible to students until payment is completed and approved by admin.
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                    <a href="property_dashboard.php" class="btn btn-outline-secondary me-md-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                    <button type="submit" name="add_room" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Add Room & Proceed to Payment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Form validation
        document.getElementById('addRoomForm').addEventListener('submit', function(e) {
            const roomNumber = document.getElementById('roomNumber').value.trim();
            if (!roomNumber) {
                e.preventDefault();
                alert('Please enter a room number/name');
                return false;
            }
            return true;
        });
    </script>
</body>
</html>