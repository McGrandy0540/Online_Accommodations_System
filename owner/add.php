<?php
session_start();
require_once  '../config/database.php';

// Check if user is property owner
if ($_SESSION['status'] !== 'property_owner') {
    header("Location: ../auth/login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Initialize variables
$errors = [];
$success = false;

// Fetch categories from database
$categories = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM categories");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching categories: " . $e->getMessage();
}

// Fetch room types from property_features
$room_types = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT feature_name FROM property_features WHERE feature_name LIKE 'Room Type:%'");
    $room_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching room types: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required = ['property_name', 'category_id', 'description', 'location', 'price', 'room_count', 'room_capacity'];
    
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
    
    // Validate numeric fields
    $numericFields = ['price', 'latitude', 'longitude', 'bedrooms', 'bathrooms', 'area_sqft', 'year_built', 'room_count', 'room_capacity'];
    foreach ($numericFields as $field) {
        if (!empty($_POST[$field]) && !is_numeric($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be a number";
        }
    }
    
    // Validate latitude and longitude ranges and precision
    if (!empty($_POST['latitude'])) {
        $latitude = $_POST['latitude'];
        if ($latitude < -90 || $latitude > 90) {
            $errors[] = "Latitude must be between -90 and 90 degrees";
        }
        // Check decimal places (now 20 for DECIMAL(22,20))
        $decimalPlaces = strlen(substr(strrchr($latitude, '.'), 1));
        if ($decimalPlaces > 20) {
            $errors[] = "Latitude can have maximum 20 decimal places";
        }
    }
    
    if (!empty($_POST['longitude'])) {
        $longitude = $_POST['longitude'];
        if ($longitude < -180 || $longitude > 180) {
            $errors[] = "Longitude must be between -180 and 180 degrees";
        }
        // Check decimal places (now 20 for DECIMAL(23,20))
        $decimalPlaces = strlen(substr(strrchr($longitude, '.'), 1));
        if ($decimalPlaces > 20) {
            $errors[] = "Longitude can have maximum 20 decimal places";
        }
    }
    
    // Validate year built
    if (!empty($_POST['year_built']) && ($_POST['year_built'] < 1800 || $_POST['year_built'] > date('Y'))) {
        $errors[] = "Year built must be between 1800 and " . date('Y');
    }
    
    // Validate images
    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) {
                $errors[] = "Error uploading image: " . $_FILES['images']['name'][$key];
                continue;
            }
            
            // Check file size (max 10MB)
            if ($_FILES['images']['size'][$key] > 10 * 1024 * 1024) {
                $errors[] = "Image too large (max 10MB): " . $_FILES['images']['name'][$key];
            }
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp_name);
            if (!in_array($mime, $allowed_types)) {
                $errors[] = "Invalid file type (only JPEG, PNG, GIF allowed): " . $_FILES['images']['name'][$key];
            }
        }
    }
    
    // If no errors, proceed with database insertion
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Insert property
            $stmt = $pdo->prepare("
                INSERT INTO property (
                    owner_id, property_name, category_id, description, 
                    price, location, bedrooms, bathrooms, area_sqft, 
                    year_built, parking, latitude, longitude, cancellation_policy
                ) VALUES (
                    :owner_id, :property_name, :category_id, :description, 
                    :price, :location, :bedrooms, :bathrooms, :area_sqft, 
                    :year_built, :parking, :latitude, :longitude, :cancellation_policy
                )
            ");
            
            // Format coordinates to ensure proper precision (20 decimal places)
            $latitude = !empty($_POST['latitude']) ? number_format((float)$_POST['latitude'], 20, '.', '') : null;
            $longitude = !empty($_POST['longitude']) ? number_format((float)$_POST['longitude'], 20, '.', '') : null;
            
            $stmt->execute([
                ':owner_id' => $owner_id,
                ':property_name' => $_POST['property_name'],
                ':category_id' => $_POST['category_id'],
                ':description' => $_POST['description'],
                ':price' => $_POST['price'],
                ':location' => $_POST['location'],
                ':bedrooms' => !empty($_POST['bedrooms']) ? $_POST['bedrooms'] : null,
                ':bathrooms' => !empty($_POST['bathrooms']) ? $_POST['bathrooms'] : null,
                ':area_sqft' => !empty($_POST['area_sqft']) ? $_POST['area_sqft'] : null,
                ':year_built' => !empty($_POST['year_built']) ? $_POST['year_built'] : null,
                ':parking' => !empty($_POST['parking']) ? $_POST['parking'] : null,
                ':latitude' => $latitude,
                ':longitude' => $longitude,
                ':cancellation_policy' => $_POST['cancellation_policy'] ?? 'moderate'
            ]);
            
            $property_id = $pdo->lastInsertId();
            
            // CRITICAL FIX: Insert ownership record
            $stmt = $pdo->prepare("INSERT INTO property_owners (owner_id, property_id) VALUES (?, ?)");
            $stmt->execute([$owner_id, $property_id]);
            
            // Auto-approve owner's properties
            $pdo->prepare("UPDATE property SET approved = 1 WHERE id = ?")->execute([$property_id]);

            // Insert property features
            if (!empty($_POST['features'])) {
                $featureStmt = $pdo->prepare("INSERT INTO property_features (property_id, feature_name) VALUES (:property_id, :feature_name)");
                foreach ($_POST['features'] as $feature) {
                    $featureStmt->execute([':property_id' => $property_id, ':feature_name' => $feature]);
                }
            }
            
            // Insert rooms
            if (!empty($_POST['rooms'])) {
                $roomStmt = $pdo->prepare("
                    INSERT INTO property_rooms (
                        property_id, room_number, capacity, status, gender
                    ) VALUES (
                        :property_id, :room_number, :capacity, :status, :gender
                    )
                ");
                
                foreach ($_POST['rooms'] as $room) {
                    // Skip if required room fields are missing
                    if (empty($room['room_number']) || empty($room['capacity']) || empty($room['status']) || empty($room['gender'])) {
                        continue;
                    }
                    
                    $roomStmt->execute([
                        ':property_id' => $property_id,
                        ':room_number' => $room['room_number'],
                        ':capacity' => $room['capacity'],
                        ':status' => $room['status'],
                        ':gender' => $room['gender']
                    ]);
                    
                    $room_id = $pdo->lastInsertId();
                    
                    // Insert room features
                    if (!empty($room['features'])) {
                        $roomFeatureStmt = $pdo->prepare("INSERT INTO property_features (property_id, feature_name) VALUES (:property_id, :feature_name)");
                        foreach ($room['features'] as $feature) {
                            $roomFeatureStmt->execute([':property_id' => $property_id, ':feature_name' => 'Room:' . $feature]);
                        }
                    }
                }
            }
            
            // Insert availability data with proper validation
            if (!empty($_POST['availability_data'])) {
                $availabilityData = json_decode($_POST['availability_data'], true);
                
                if (is_array($availabilityData)) {
                    $availabilityStmt = $pdo->prepare("
                        INSERT INTO availability_calendar (
                            property_id, date, status
                        ) VALUES (
                            :property_id, :date, :status
                        )
                    ");
                    
                    foreach ($availabilityData as $day) {
                        // Skip if date is not set or invalid
                        if (empty($day['date']) || !strtotime($day['date'])) {
                            continue;
                        }
                        
                        // Default to 'available' if status is not set
                        $status = $day['status'] ?? 'available';
                        
                        try {
                            $availabilityStmt->execute([
                                ':property_id' => $property_id,
                                ':date' => $day['date'],
                                ':status' => $status
                            ]);
                        } catch (PDOException $e) {
                            // Log error but don't stop execution
                            error_log("Error inserting availability: " . $e->getMessage());
                        }
                    }
                }
            }
        

        // Handle image uploads
        if (!empty($_FILES['images']['name'][0])) {
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $image_sql = "INSERT INTO property_images (property_id, image_url) VALUES (?, ?)";
            $image_stmt = $pdo->prepare($image_sql);
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $image_name = time() . '_' . basename($_FILES['images']['name'][$key]);
                    $target = $upload_dir . $image_name;
                    
                    if (move_uploaded_file($tmp_name, $target)) {
                        $image_stmt->execute([$property_id, $image_name]);
                    }
                }
            }
        }
    

            $pdo->commit();
            $success = true;
            
            // Clear POST data if successful
            if ($success) {
                $_POST = [];
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Property | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background-color: var(--secondary-color);
            box-shadow: var(--box-shadow);
        }

        .navbar-brand {
            font-weight: 700;
            color: white !important;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            transition: all 0.3s;
        }

        .nav-link:hover {
            color: white !important;
        }

        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease;
            margin-bottom: 2rem;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
            padding: 1.5rem;
            color: white;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: var(--border-radius);
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .btn-outline-secondary {
            border-radius: var(--border-radius);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
        }

        .feature-tag {
            margin-right: 8px;
            margin-bottom: 8px;
            padding: 0.5rem 0.75rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: 20px;
            display: inline-block;
            font-size: 0.85rem;
            box-shadow: var(--box-shadow);
        }

        .preview-image {
            width: 120px;
            height: 120px;
            object-fit: cover;
            margin-right: 15px;
            margin-bottom: 15px;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius);
            transition: all 0.3s;
            cursor: pointer;
        }

        .preview-image:hover {
            transform: scale(1.05);
            border-color: var(--primary-color);
        }

        .image-preview-container {
            display: flex;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--light-color);
            border-radius: var(--border-radius);
            min-height: 150px;
        }

        .section-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 50px;
            height: 3px;
            background-color: var(--primary-color);
        }

        .form-label {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .alert {
            border-radius: var(--border-radius);
        }

        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-upload-btn {
            border: 2px dashed #ccc;
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background-color: var(--light-color);
            width: 100%;
        }

        .file-upload-btn:hover {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }

        .file-upload-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .select2-container--default .select2-selection--multiple {
            border: 1px solid #e0e0e0;
            border-radius: var(--border-radius);
            padding: 0.375rem 0.75rem;
            min-height: calc(1.5em + 0.75rem + 2px);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary-color);
            border: none;
            border-radius: 20px;
            padding: 0 10px;
            margin-top: 5px;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 5px;
        }

        .footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 2rem 0;
            margin-top: 3rem;
        }

        .room-container {
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e0e0e0;
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .availability-calendar {
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1rem;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day {
            padding: 0.5rem;
            text-align: center;
            border-radius: 4px;
            cursor: pointer;
        }

        .calendar-day.available {
            background-color: #d4edda;
        }

        .calendar-day.booked {
            background-color: #f8d7da;
        }

        .calendar-day.maintenance {
            background-color: #fff3cd;
        }

        .calendar-day.selected {
            border: 2px solid var(--primary-color);
        }

        @media (max-width: 768px) {
            .card-header {
                padding: 1rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
            
            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .preview-image {
                width: 80px;
                height: 80px;
                margin-right: 10px;
                margin-bottom: 10px;
            }
            
            .file-upload-btn {
                padding: 1.5rem;
            }
            
            .file-upload-btn h5 {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-home me-2"></i>Landlords&Tenant
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="property_dashboard.php"><i class="fas fa-list me-1"></i> My Properties</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="add.php"><i class="fas fa-plus me-1"></i> Add Property</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card mb-5">
                    <div class="card-header">
                        <h2 class="mb-0"><i class="fas fa-home me-2"></i>Add New Property</h2>
                    </div>
                    <div class="card-body p-4">
                        <?php if (isset($success) && $success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                Property added successfully! 
                                <a href="index.php" class="alert-link fw-bold">View all properties</a> or 
                                <a href="add.php" class="alert-link fw-bold">add another</a>.
                            </div>
                        <?php elseif (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <strong>Please fix the following errors:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <h4 class="section-title">Basic Information</h4>
                            <div class="row g-3">
                                <div class="col-md-6 mb-3">
                                    <label for="property_name" class="form-label">Property Name *</label>
                                    <input type="text" class="form-control" id="property_name" name="property_name" required 
                                           value="<?= htmlspecialchars($_POST['property_name'] ?? '') ?>">
                                    <div class="invalid-feedback">
                                        Please provide a property name.
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Category *</label>
                                    <select class="form-select" id="category_id" name="category_id" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category['id'] ?>" <?= ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($category['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a category.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description *</label>
                                <textarea class="form-control" id="description" name="description" rows="5" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                <div class="invalid-feedback">
                                    Please provide a description.
                                </div>
                            </div>

                            <h4 class="section-title mt-5">Location Details</h4>
                            <div class="row g-3">
                                <div class="col-md-8 mb-3">
                                    <label for="location" class="form-label">Address *</label>
                                    <input type="text" class="form-control" id="location" name="location" required 
                                           value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                                    <div class="invalid-feedback">
                                        Please provide a location.
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="price" class="form-label">Yearly Price (GHS) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text"></span>
                                        <input type="number" step="0.01" min="0" class="form-control" id="price" name="price" required 
                                               value="<?= htmlspecialchars($_POST['price'] ?? '') ?>">
                                    </div>
                                    <div class="invalid-feedback">
                                        Please provide a valid price.
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-4 mb-3">
                                    <label for="latitude" class="form-label">Latitude</label>
                                    <input type="text" class="form-control" id="latitude" name="latitude" 
                                           value="<?= htmlspecialchars($_POST['latitude'] ?? '6.0647589321434054') ?>"
                                           pattern="-?\d{1,2}\.\d{1,20}"
                                           title="Between -90 and 90 with up to 20 decimal places (e.g. 6.0647589321434054)">
                                    <small class="text-muted">Example: 6.0647589321434054</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="longitude" class="form-label">Longitude</label>
                                    <input type="text" class="form-control" id="longitude" name="longitude" 
                                           value="<?= htmlspecialchars($_POST['longitude'] ?? '-0.26428613304683796') ?>"
                                           pattern="-?\d{1,3}\.\d{1,20}"
                                           title="Between -180 and 180 with up to 20 decimal places (e.g. -0.26428613304683796)">
                                    <small class="text-muted">Example: -0.26428613304683796</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="cancellation_policy" class="form-label">Cancellation Policy</label>
                                    <select class="form-select" id="cancellation_policy" name="cancellation_policy">
                                        <option value="flexible" <?= ($_POST['cancellation_policy'] ?? '') == 'flexible' ? 'selected' : '' ?>>Flexible (Full refund)</option>
                                        <option value="moderate" <?= ($_POST['cancellation_policy'] ?? 'moderate') == 'moderate' ? 'selected' : '' ?>>Moderate (Partial refund)</option>
                                        <option value="strict" <?= ($_POST['cancellation_policy'] ?? '') == 'strict' ? 'selected' : '' ?>>Strict (No refund)</option>
                                    </select>
                                </div>
                            </div>

                            <h4 class="section-title mt-5">Property Specifications</h4>
                            <div class="row g-3">
                                <div class="col-md-3 mb-3">
                                    <label for="bedrooms" class="form-label">Bedrooms</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-bed"></i></span>
                                        <input type="number" min="0" class="form-control" id="bedrooms" name="bedrooms" 
                                               value="<?= htmlspecialchars($_POST['bedrooms'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="bathrooms" class="form-label">Bathrooms</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-bath"></i></span>
                                        <input type="number" min="0" class="form-control" id="bathrooms" name="bathrooms" 
                                               value="<?= htmlspecialchars($_POST['bathrooms'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="area_sqft" class="form-label">Area (sqft)</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-ruler-combined"></i></span>
                                        <input type="number" step="0.01" min="0" class="form-control" id="area_sqft" name="area_sqft" 
                                               value="<?= htmlspecialchars($_POST['area_sqft'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="year_built" class="form-label">Year Built</label>
                                    <input type="number" min="1800" max="<?= date('Y') ?>" class="form-control" id="year_built" name="year_built" 
                                           value="<?= htmlspecialchars($_POST['year_built'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-4 mb-3">
                                    <label for="room_count" class="form-label">Number of Rooms *</label>
                                    <input type="number" min="1" class="form-control" id="room_count" name="room_count" required 
                                           value="<?= htmlspecialchars($_POST['room_count'] ?? '1') ?>">
                                    <div class="invalid-feedback">
                                        Please provide the number of rooms.
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="room_capacity" class="form-label">Room Capacity *</label>
                                    <input type="number" min="1" class="form-control" id="room_capacity" name="room_capacity" required 
                                           value="<?= htmlspecialchars($_POST['room_capacity'] ?? '2') ?>">
                                    <div class="invalid-feedback">
                                        Please provide room capacity.
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="parking" class="form-label">Parking</label>
                                    <input type="text" class="form-control" id="parking" name="parking" 
                                           value="<?= htmlspecialchars($_POST['parking'] ?? '') ?>" placeholder="e.g. Garage, Street Parking">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="features" class="form-label">Features & Amenities</label>
                                <select class="form-control select2-multiple" id="features" name="features[]" multiple="multiple">
                                    <option value="WiFi">WiFi</option>
                                    <option value="Air Conditioning">Air Conditioning</option>
                                    <option value="Heating">Heating</option>
                                    <option value="Kitchen">Kitchen</option>
                                    <option value="Washer">Washer</option>
                                    <option value="Dryer">Dryer</option>
                                    <option value="TV">TV</option>
                                    <option value="Swimming Pool">Swimming Pool</option>
                                    <option value="Gym">Gym</option>
                                    <option value="Parking">Parking</option>
                                    <option value="Security">Security</option>
                                    <option value="Furnished">Furnished</option>
                                    <option value="Pet Friendly">Pet Friendly</option>
                                    <option value="Wheelchair Accessible">Wheelchair Accessible</option>
                                    <?php foreach ($room_types as $type): ?>
                                        <option value="Room Type:<?= htmlspecialchars(str_replace('Room Type:', '', $type['feature_name'])) ?>">
                                            <?= htmlspecialchars(str_replace('Room Type:', '', $type['feature_name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Start typing to add custom features</small>
                                <div id="feature-tags" class="mt-3"></div>
                            </div>

                            <!-- Room Management Section -->
                            <h4 class="section-title mt-5">Room Details</h4>
                            <div id="rooms-container">
                                <!-- Room template will be cloned here -->
                                <div class="room-container" data-room-index="0">
                                    <div class="room-header">
                                        <h5>Room #1</h5>
                                        <button type="button" class="btn btn-sm btn-danger remove-room" style="display:none;">
                                            <i class="fas fa-trash"></i> Remove
                                        </button>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label for="room_number_0" class="form-label">Room Number *</label>
                                            <input type="text" class="form-control" id="room_number_0" name="rooms[0][room_number]" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="room_capacity_0" class="form-label">Capacity *</label>
                                            <input type="number" min="1" class="form-control" id="room_capacity_0" name="rooms[0][capacity]" required value="2">
                                        </div>
                                        <div class="col-md-3">
                                            <label for="room_status_0" class="form-label">Status *</label>
                                            <select class="form-select" id="room_status_0" name="rooms[0][status]" required>
                                                <option value="available" selected>Available</option>
                                                <option value="occupied">Occupied</option>
                                                <option value="maintenance">Maintenance</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="room_gender_0" class="form-label">Gender *</label>
                                            <select class="form-select" id="room_gender_0" name="rooms[0][gender]" required>
                                                <option value="male" selected>Male</option>
                                                <option value="female">Female</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <label class="form-label">Room Features</label>
                                        <select class="form-control select2-multiple" id="room_features_0" name="rooms[0][features][]" multiple="multiple">
                                            <option value="Private Bathroom">Private Bathroom</option>
                                            <option value="Shared Bathroom">Shared Bathroom</option>
                                            <option value="Desk">Desk</option>
                                            <option value="Wardrobe">Wardrobe</option>
                                            <option value="Balcony">Balcony</option>
                                            <option value="View">View</option>
                                        </select>
                                    </div>
                                    <div class="mt-3">
                                        <label class="form-label">Room Images</label>
                                        <div class="file-upload">
                                            <label class="file-upload-btn">
                                                <i class="fas fa-cloud-upload-alt me-2"></i>
                                                Upload Room Images
                                                <input type="file" class="file-upload-input" name="room_images[0][]" multiple accept="image/*">
                                            </label>
                                        </div>
                                        <div class="image-preview-container" id="room-image-preview-0"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-4">
                                <button type="button" id="add-room-btn" class="btn btn-outline-primary">
                                    <i class="fas fa-plus me-2"></i>Add Another Room
                                </button>
                            </div>

                            <!-- Availability Calendar -->
                            <h4 class="section-title mt-5">Availability Calendar</h4>
                            <div class="availability-calendar">
                                <div class="calendar-header">
                                    <h5>Set Property Availability</h5>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mark-available">
                                            <i class="fas fa-check-circle me-1"></i>Mark as Available
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mark-booked">
                                            <i class="fas fa-times-circle me-1"></i>Mark as Booked
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mark-maintenance">
                                            <i class="fas fa-wrench me-1"></i>Mark as Maintenance
                                        </button>
                                    </div>
                                </div>
                                <div class="calendar-grid" id="availability-calendar">
                                    <!-- Calendar will be generated by JavaScript -->
                                </div>
                                <input type="hidden" id="availability-data" name="availability_data">
                            </div>

                            <h4 class="section-title mt-5">Property Images</h4>
                            <div class="mb-4">
                                <div class="file-upload">
                                    <label class="file-upload-btn">
                                        <i class="fas fa-cloud-upload-alt fa-2x mb-3" style="color: var(--primary-color);"></i>
                                        <h5>Drag & Drop or Click to Upload</h5>
                                        <p class="text-muted">Upload multiple images (Max 10MB each)</p>
                                        <input type="file" class="file-upload-input" id="images" name="images[]" multiple accept="image/*">
                                    </label>
                                </div>
                                <div id="image-preview" class="image-preview-container"></div>
                            </div>

                            <div class="d-grid gap-3 d-md-flex justify-content-md-end mt-5">
                                <a href="index.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Property
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-home me-2"></i>UniHomes</h5>
                    <p>Find your perfect student accommodation with ease.</p>
                </div>
                <div class="col-md-3">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white">Home</a></li>
                        <li><a href="#" class="text-white">Properties</a></li>
                        <li><a href="#" class="text-white">About Us</a></li>
                        <li><a href="#" class="text-white">Contact</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h5>Connect</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white"><i class="fab fa-facebook me-2"></i>Facebook</a></li>
                        <li><a href="#" class="text-white"><i class="fab fa-twitter me-2"></i>Twitter</a></li>
                        <li><a href="#" class="text-white"><i class="fab fa-instagram me-2"></i>Instagram</a></li>
                    </ul>
                </div>
            </div>
            <hr class="mt-4 bg-light">
            <div class="text-center">
                <p class="mb-0">&copy; <?= date('Y') ?> UniHomes. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2-multiple').select2({
            tags: true,
            tokenSeparators: [','],
            placeholder: "Select or add features (e.g. WiFi, Parking)",
            allowClear: true
        });

        // Initialize datepicker
        $('.datepicker').flatpickr({
            dateFormat: "Y-m-d",
            minDate: "today"
        });

        // Image preview functionality
        $('#images').on('change', function() {
            const previewContainer = $('#image-preview');
            previewContainer.empty();
            
            const files = this.files;
            const maxFiles = 10;
            const maxSize = 10 * 1024 * 1024; // 10MB
            
            if (files.length > maxFiles) {
                alert(`You can upload a maximum of ${maxFiles} images`);
                $(this).val('');
                return;
            }
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                if (file.size > maxSize) {
                    alert(`File ${file.name} is too large (max 10MB)`);
                    continue;
                }
                
                if (!file.type.match('image.*')) {
                    alert(`File ${file.name} is not an image`);
                    continue;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const imgContainer = $('<div>').addClass('position-relative')
                        .css({
                            'margin-right': '15px',
                            'margin-bottom': '15px'
                        });
                    
                    const img = $('<img>').attr('src', e.target.result)
                        .addClass('preview-image');
                    
                    const removeBtn = $('<button>').addClass('btn btn-danger btn-sm position-absolute top-0 end-0 rounded-circle')
                        .css('transform', 'translate(50%, -50%)')
                        .html('<i class="fas fa-times"></i>')
                        .on('click', function() {
                            imgContainer.remove();
                            const dataTransfer = new DataTransfer();
                            const remainingFiles = $('#images')[0].files;
                            
                            for (let j = 0; j < remainingFiles.length; j++) {
                                if (remainingFiles[j].name !== file.name) {
                                    dataTransfer.items.add(remainingFiles[j]);
                                }
                            }
                            
                            $('#images')[0].files = dataTransfer.files;
                        });
                    
                    imgContainer.append(img).append(removeBtn);
                    previewContainer.append(imgContainer);
                };
                
                reader.readAsDataURL(file);
            }
        });

        // Room management functionality
        let roomCounter = 1;
        
        $('#add-room-btn').on('click', function() {
            const newRoomIndex = roomCounter++;
            const newRoom = $('.room-container[data-room-index="0"]').clone();
            
            newRoom.attr('data-room-index', newRoomIndex);
            newRoom.find('h5').text('Room #' + (newRoomIndex + 1));
            newRoom.find('input, select').each(function() {
                const oldId = $(this).attr('id');
                const oldName = $(this).attr('name');
                
                if (oldId) {
                    $(this).attr('id', oldId.replace('_0', '_' + newRoomIndex));
                }
                
                if (oldName) {
                    $(this).attr('name', oldName.replace('[0]', '[' + newRoomIndex + ']'));
                }
            });
            
            newRoom.find('.file-upload-input').attr('name', 'room_images[' + newRoomIndex + '][]');
            newRoom.find('.image-preview-container').attr('id', 'room-image-preview-' + newRoomIndex);
            newRoom.find('.remove-room').show();
            
            // Initialize Select2 for the new room features
            newRoom.find('.select2-multiple').select2({
                tags: true,
                tokenSeparators: [','],
                placeholder: "Select or add room features",
                allowClear: true
            });
            
            // Add image preview functionality for room images
            newRoom.find('.file-upload-input').on('change', function() {
                const previewContainer = newRoom.find('.image-preview-container');
                previewContainer.empty();
                
                const files = this.files;
                const maxFiles = 5;
                const maxSize = 10 * 1024 * 1024; // 10MB
                
                if (files.length > maxFiles) {
                    alert(`You can upload a maximum of ${maxFiles} images per room`);
                    $(this).val('');
                    return;
                }
                
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    
                    if (file.size > maxSize) {
                        alert(`File ${file.name} is too large (max 10MB)`);
                        continue;
                    }
                    
                    if (!file.type.match('image.*')) {
                        alert(`File ${file.name} is not an image`);
                        continue;
                    }
                    
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const imgContainer = $('<div>').addClass('position-relative')
                            .css({
                                'margin-right': '15px',
                                'margin-bottom': '15px'
                            });
                        
                        const img = $('<img>').attr('src', e.target.result)
                            .addClass('preview-image');
                        
                        const removeBtn = $('<button>').addClass('btn btn-danger btn-sm position-absolute top-0 end-0 rounded-circle')
                            .css('transform', 'translate(50%, -50%)')
                            .html('<i class="fas fa-times"></i>')
                            .on('click', function() {
                                imgContainer.remove();
                                const dataTransfer = new DataTransfer();
                                const remainingFiles = $(this).closest('.room-container').find('.file-upload-input')[0].files;
                                
                                for (let j = 0; j < remainingFiles.length; j++) {
                                    if (remainingFiles[j].name !== file.name) {
                                        dataTransfer.items.add(remainingFiles[j]);
                                    }
                                }
                                
                                $(this).closest('.room-container').find('.file-upload-input')[0].files = dataTransfer.files;
                            });
                        
                        imgContainer.append(img).append(removeBtn);
                        previewContainer.append(imgContainer);
                    };
                    
                    reader.readAsDataURL(file);
                }
            });
            
            $('#rooms-container').append(newRoom);
        });
        
        // Remove room functionality
        $(document).on('click', '.remove-room', function() {
            if (confirm('Are you sure you want to remove this room?')) {
                $(this).closest('.room-container').remove();
                
                // Renumber remaining rooms
                $('.room-container').each(function(index) {
                    $(this).attr('data-room-index', index);
                    $(this).find('h5').text('Room #' + (index + 1));
                    
                    // Only show remove button if not the first room
                    if (index === 0) {
                        $(this).find('.remove-room').hide();
                    }
                });
            }
        });

        // Initialize availability calendar with proper date handling
        function generateCalendar() {
            const calendar = $('#availability-calendar');
            calendar.empty();
            
            // Add day headers
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            days.forEach(day => {
                calendar.append($('<div>').addClass('calendar-day').text(day).css('font-weight', 'bold'));
            });
            
            // Get current date
            const date = new Date();
            const currentMonth = date.getMonth();
            const currentYear = date.getFullYear();
            
            // Get first day of month
            const firstDay = new Date(currentYear, currentMonth, 1).getDay();
            
            // Get days in month
            const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
            
            // Add empty cells for days before first day
            for (let i = 0; i < firstDay; i++) {
                calendar.append($('<div>').addClass('calendar-day'));
            }
            
            // Add days of month with proper date attributes
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dayElement = $('<div>')
                    .addClass('calendar-day available')
                    .text(day)
                    .attr('data-date', dateStr)
                    .data('date', dateStr);
                
                calendar.append(dayElement);
            }
            
            // Add click handlers for calendar days
            $('.calendar-day:not(:empty)').on('click', function() {
                $(this).toggleClass('selected');
            });
        }
        
        generateCalendar();
        
        // Calendar marking buttons
        $('#mark-available').on('click', function() {
            $('.calendar-day.selected').removeClass('booked maintenance').addClass('available');
        });
        
        $('#mark-booked').on('click', function() {
            $('.calendar-day.selected').removeClass('available maintenance').addClass('booked');
        });
        
        $('#mark-maintenance').on('click', function() {
            $('.calendar-day.selected').removeClass('available booked').addClass('maintenance');
        });
        
        // Before form submission, collect availability data with validation
        $('form').on('submit', function() {
            const availabilityData = [];
            
            $('.calendar-day:not(:empty)').each(function() {
                const date = $(this).data('date');
                // Skip if no valid date
                if (!date) return;
                
                let status = 'available';
                
                if ($(this).hasClass('booked')) {
                    status = 'booked';
                } else if ($(this).hasClass('maintenance')) {
                    status = 'maintenance';
                }
                
                availabilityData.push({
                    date: date,
                    status: status
                });
            });
            
            $('#availability-data').val(JSON.stringify(availabilityData));
        });

        // Add coordinate validation before form submission
        $('form').on('submit', function(e) {
            const lat = $('#latitude').val();
            const lng = $('#longitude').val();
            
            // Validate latitude
            if (lat) {
                const latNum = parseFloat(lat);
                if (isNaN(latNum) || latNum < -90 || latNum > 90) {
                    alert('Latitude must be between -90 and 90');
                    e.preventDefault();
                    return false;
                }
                
                // Check decimal places (now 20)
                const latParts = lat.split('.');
                if (latParts.length > 1 && latParts[1].length > 20) {
                    alert('Latitude can have maximum 20 decimal places');
                    e.preventDefault();
                    return false;
                }
            }
            
            // Validate longitude
            if (lng) {
                const lngNum = parseFloat(lng);
                if (isNaN(lngNum) || lngNum < -180 || lngNum > 180) {
                    alert('Longitude must be between -180 and 180');
                    e.preventDefault();
                    return false;
                }
                
                // Check decimal places (now 20)
                const lngParts = lng.split('.');
                if (lngParts.length > 1 && lngParts[1].length > 20) {
                    alert('Longitude can have maximum 20 decimal places');
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });

        // Drag and drop functionality
        const fileUploadBtn = $('.file-upload-btn');
        
        fileUploadBtn.on('dragover', function(e) {
            e.preventDefault();
            $(this).css({
                'border-color': 'var(--primary-color)',
                'background-color': 'rgba(52, 152, 219, 0.1)'
            });
        });
        
        fileUploadBtn.on('dragleave', function() {
            $(this).css({
                'border-color': '#ccc',
                'background-color': 'var(--light-color)'
            });
        });
        
        fileUploadBtn.on('drop', function(e) {
            e.preventDefault();
            $(this).css({
                'border-color': '#ccc',
                'background-color': 'var(--light-color)'
            });
            
            if (e.originalEvent.dataTransfer.files.length) {
                $('#images')[0].files = e.originalEvent.dataTransfer.files;
                $('#images').trigger('change');
            }
        });
    });

    // Non-jQuery form validation
    document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('.needs-validation');
        
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    });
    </script>
</body>
</html>