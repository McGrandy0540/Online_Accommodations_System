<?php
// Start session and check admin authentication
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
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
        SELECT p.*, u.username as owner_name 
        FROM property p
        JOIN users u ON p.owner_id = u.id
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
    
    // Get rooms
    $rooms = $db->prepare("SELECT * FROM property_rooms WHERE property_id = ? ORDER BY room_number");
    $rooms->execute([$propertyId]);
    $propertyRooms = $rooms->fetchAll(PDO::FETCH_ASSOC);
    
    // Get categories and owners
    $categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $owners = $db->query("SELECT id, username FROM users WHERE status = 'property_owner' AND deleted = 0 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Update property
        $stmt = $db->prepare("
            UPDATE property SET
                owner_id = ?,
                property_name = ?,
                category_id = ?,
                description = ?,
                price = ?,
                location = ?,
                bedrooms = ?,
                bathrooms = ?,
                area_sqft = ?,
                year_built = ?,
                parking = ?,
                status = ?,
                latitude = ?,
                longitude = ?,
                approved = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['owner_id'],
            $_POST['property_name'],
            $_POST['category_id'],
            $_POST['description'],
            $_POST['price'],
            $_POST['location'],
            $_POST['bedrooms'] ?? null,
            $_POST['bathrooms'] ?? null,
            $_POST['area_sqft'] ?? null,
            $_POST['year_built'] ?? null,
            $_POST['parking'] ?? null,
            $_POST['status'],
            $_POST['latitude'] ?? null,
            $_POST['longitude'] ?? null,
            isset($_POST['approved']) ? 1 : 0,
            $propertyId
        ]);
        
        // Update rooms if total_rooms changed
        if (isset($_POST['total_rooms'])) {
            $currentRoomCount = count($propertyRooms);
            $newRoomCount = (int)$_POST['total_rooms'];
            
            if ($newRoomCount > $currentRoomCount) {
                // Add new rooms
                $roomStmt = $db->prepare("INSERT INTO property_rooms (property_id, room_number, capacity, status) VALUES (?, ?, ?, ?)");
                for ($i = $currentRoomCount + 1; $i <= $newRoomCount; $i++) {
                    $roomNumber = $_POST['property_name'] . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                    $roomStmt->execute([
                        $propertyId,
                        $roomNumber,
                        $_POST['room_capacity'] ?? 2,
                        'available'
                    ]);
                }
            } elseif ($newRoomCount < $currentRoomCount) {
                // Delete extra rooms (only if they're not booked)
                $deleteStmt = $db->prepare("DELETE FROM property_rooms WHERE property_id = ? AND status = 'available' ORDER BY id DESC LIMIT ?");
                $deleteStmt->execute([$propertyId, $currentRoomCount - $newRoomCount]);
            }
        }
        
        // Update room capacities if changed
        if (isset($_POST['room_capacity'])) {
            $updateStmt = $db->prepare("UPDATE property_rooms SET capacity = ? WHERE property_id = ?");
            $updateStmt->execute([$_POST['room_capacity'], $propertyId]);
        }
        
        // Update features - first delete existing ones
        $db->prepare("DELETE FROM property_features WHERE property_id = ?")->execute([$propertyId]);
        
        // Add new features
        if (!empty($_POST['features'])) {
            $features = preg_split('/[\n,]+/', $_POST['features']);
            $featureStmt = $db->prepare("INSERT INTO property_features (property_id, feature_name) VALUES (?, ?)");
            foreach ($features as $feature) {
                if (!empty(trim($feature))) {
                    $featureStmt->execute([$propertyId, trim($feature)]);
                }
            }
        }
        
        // Handle virtual tour update
        if (!empty($_FILES['virtual_tour']['name'])) {
            // First delete existing virtual tour if any
            $existingTour = $db->prepare("SELECT id FROM property_images WHERE property_id = ? AND is_virtual_tour = TRUE");
            $existingTour->execute([$propertyId]);
            if ($existingTour->rowCount() > 0) {
                $db->prepare("DELETE FROM property_images WHERE property_id = ? AND is_virtual_tour = TRUE")->execute([$propertyId]);
            }
            
            // Upload new virtual tour
            $uploadDir = '../../../assets/uploads/virtual_tours/';
            $fileName = 'tour_' . $propertyId . '_' . basename($_FILES['virtual_tour']['name']);
            $targetFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['virtual_tour']['tmp_name'], $targetFile)) {
                $tourStmt = $db->prepare("INSERT INTO property_images (property_id, image_url, is_virtual_tour, media_type) VALUES (?, ?, TRUE, 'virtual_tour')");
                $tourStmt->execute([$propertyId, 'assets/uploads/virtual_tours/' . $fileName]);
            }
        }
        
        $db->commit();
        
        $_SESSION['success'] = "Property updated successfully!";
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error updating property: " . $e->getMessage();
    }
}

// Get existing virtual tour if any
try {
    $virtualTour = $db->prepare("SELECT image_url FROM property_images WHERE property_id = ? AND is_virtual_tour = TRUE LIMIT 1");
    $virtualTour->execute([$propertyId]);
    $existingTour = $virtualTour->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $existingTour = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Property | landlords&Tenant Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        }
        
        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Alert Styles */
        .alert {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        .input-group {
            display: flex;
        }
        
        .input-group-prepend {
            display: flex;
            align-items: center;
            padding: 0 15px;
            background-color: #eee;
            border: 1px solid #ddd;
            border-right: none;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
        }
        
        .input-group .form-control {
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
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
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        /* Grid System */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .col-md-3, .col-md-4, .col-md-6 {
            padding: 0 10px;
            margin-bottom: 15px;
        }
        
        .col-md-3 { width: 25%; }
        .col-md-4 { width: 33.333%; }
        .col-md-6 { width: 50%; }
        
        /* Room Management Styles */
        .room-management {
            background-color: var(--light-color);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .room-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .room-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: var(--transition);
        }
        
        .room-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .room-card h5 {
            margin-bottom: 5px;
            color: var(--secondary-color);
        }
        
        .room-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-available {
            background-color: #e3f9ee;
            color: #00a854;
        }
        
        .status-occupied {
            background-color: #fff0f0;
            color: #f5222d;
        }
        
        .status-maintenance {
            background-color: #fff7e6;
            color: #fa8c16;
        }
        
        /* Virtual Tour Preview */
        .virtual-tour-preview {
            margin-top: 15px;
        }
        
        .virtual-tour-preview img, 
        .virtual-tour-preview video {
            max-width: 100%;
            border-radius: var(--border-radius);
            margin-top: 10px;
        }
        
        /* Helper Classes */
        .text-muted {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .col-md-3, .col-md-4, .col-md-6 {
                width: 50%;
            }
        }
        
        @media (max-width: 768px) {
            .col-md-3, .col-md-4, .col-md-6 {
                width: 100%;
            }
            
            .card-header h2 {
                font-size: 1.3rem;
            }
            
            .form-control {
                padding: 10px 12px;
            }
            
            .room-list {
                grid-template-columns: 1fr;
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
        
        /* Select2 Customization */
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 42px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card fade-in">
            <div class="card-header">
                <h2><i class="fas fa-edit"></i> Edit Property</h2>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" enctype="multipart/form-data" id="propertyForm">
                    <div class="form-group">
                        <label><i class="fas fa-home"></i> Property Name *</label>
                        <input type="text" name="property_name" class="form-control" value="<?php echo htmlspecialchars($property['property_name']); ?>" required id="propertyName">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Owner *</label>
                                <select name="owner_id" class="form-control select2" required>
                                    <option value="">Select Owner</option>
                                    <?php foreach ($owners as $owner): ?>
                                        <option value="<?php echo $owner['id']; ?>" <?php echo $owner['id'] == $property['owner_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($owner['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Category *</label>
                                <select name="category_id" class="form-control select2" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $category['id'] == $property['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <textarea name="description" class="form-control" rows="5" required><?php echo htmlspecialchars($property['description']); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-dollar-sign"></i> Price (per room) *</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" name="price" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($property['price']); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-info-circle"></i> Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="available" <?php echo $property['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="booked" <?php echo $property['status'] == 'booked' ? 'selected' : ''; ?>>Booked</option>
                                    <option value="paid" <?php echo $property['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Location *</label>
                                <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($property['location']); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-bed"></i> Bedrooms per unit</label>
                                <input type="number" name="bedrooms" class="form-control" min="0" value="<?php echo htmlspecialchars($property['bedrooms']); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-bath"></i> Bathrooms per unit</label>
                                <input type="number" name="bathrooms" class="form-control" min="0" value="<?php echo htmlspecialchars($property['bathrooms']); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-ruler-combined"></i> Area (sqft)</label>
                                <input type="number" name="area_sqft" step="0.01" class="form-control" min="0" value="<?php echo htmlspecialchars($property['area_sqft']); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Year Built</label>
                                <input type="number" name="year_built" class="form-control" min="1800" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($property['year_built']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Parking Information</label>
                        <input type="text" name="parking" class="form-control" value="<?php echo htmlspecialchars($property['parking']); ?>">
                    </div>
                    
                    <!-- Room Management Section -->
                    <div class="room-management">
                        <h4><i class="fas fa-door-open"></i> Room Management</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Total Number of Rooms *</label>
                                    <input type="number" name="total_rooms" class="form-control" min="1" value="<?php echo count($propertyRooms); ?>" required id="totalRooms">
                                    <small class="text-muted">Current: <?php echo count($propertyRooms); ?> rooms</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Capacity per Room *</label>
                                    <input type="number" name="room_capacity" class="form-control" min="1" value="<?php echo htmlspecialchars($propertyRooms[0]['capacity'] ?? 2); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <h5>Existing Rooms</h5>
                        <div class="room-list">
                            <?php foreach ($propertyRooms as $room): ?>
                                <div class="room-card">
                                    <h5><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                    <p>Capacity: <?php echo htmlspecialchars($room['capacity']); ?></p>
                                    <span class="room-status status-<?php echo strtolower($room['status']); ?>">
                                        <?php echo ucfirst($room['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-globe-americas"></i> Latitude</label>
                                <input type="text" name="latitude" class="form-control" value="<?php echo htmlspecialchars($property['latitude']); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-globe-americas"></i> Longitude</label>
                                <input type="text" name="longitude" class="form-control" value="<?php echo htmlspecialchars($property['longitude']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-star"></i> Features</label>
                        <textarea name="features" class="form-control" placeholder="e.g. WiFi, Air Conditioning, Swimming Pool"><?php echo htmlspecialchars(implode(", ", $propertyFeatures)); ?></textarea>
                        <small class="text-muted">Enter each feature on a new line or separate with commas</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-vr-cardboard"></i> Virtual Tour</label>
                        <input type="file" name="virtual_tour" class="form-control" accept=".mp4,.webm,.ogv,.jpg,.jpeg,.png,.gif">
                        <small class="text-muted">Upload a new virtual tour to replace the existing one</small>
                        
                        <?php if ($existingTour): ?>
                            <div class="virtual-tour-preview">
                                <p>Current Virtual Tour:</p>
                                <?php if (strpos($existingTour['image_url'], '.mp4') !== false || strpos($existingTour['image_url'], '.webm') !== false): ?>
                                    <video controls width="320" height="240" src="../../../<?php echo htmlspecialchars($existingTour['image_url']); ?>"></video>
                                <?php else: ?>
                                    <img src="../../../<?php echo htmlspecialchars($existingTour['image_url']); ?>" alt="Virtual Tour Preview" width="320">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group form-check">
                        <input type="checkbox" name="approved" class="form-check-input" id="approved" <?php echo $property['approved'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="approved">Approved</label>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Property
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                width: '100%'
            });
            
            // Room management preview
            function updateRoomPreview() {
                const totalRooms = parseInt($('#totalRooms').val()) || 1;
                const propertyName = $('#propertyName').val() || 'Property';
                const roomList = $('.room-list');
                
                // This is just for display - actual rooms are managed server-side
                console.log('Would update to show', totalRooms, 'rooms');
            }
            
            // Initial update
            updateRoomPreview();
            
            // Update preview when values change
            $('#totalRooms, #propertyName').on('input change', updateRoomPreview);
            
            // Form validation
            $('#propertyForm').on('submit', function(e) {
                const requiredFields = $(this).find('[required]');
                let isValid = true;
                
                requiredFields.each(function() {
                    if (!$(this).val().trim()) {
                        $(this).css('border-color', 'var(--accent-color)');
                        isValid = false;
                    } else {
                        $(this).css('border-color', '');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields');
                }
            });
        });
    </script>
</body>
</html>