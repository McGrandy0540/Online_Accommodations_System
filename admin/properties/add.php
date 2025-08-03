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

// Get categories and owners for dropdowns
try {
    $categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $owners = $db->query("SELECT id, username FROM users WHERE status = 'property_owner' AND deleted = 0 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Insert property
        $stmt = $db->prepare("
            INSERT INTO property (
                owner_id, property_name, category_id, description, price, 
                location, bedrooms, bathrooms, area_sqft, year_built, 
                parking, status, latitude, longitude, approved, total_rooms
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            1, // Auto-approve for admin
            $_POST['total_rooms'] ?? 1
        ]);
        
        $propertyId = $db->lastInsertId();
        
        // Add rooms based on total_rooms
        if (isset($_POST['total_rooms']) && $_POST['total_rooms'] > 1) {
            $roomStmt = $db->prepare("INSERT INTO property_rooms (property_id, room_number, capacity, status) VALUES (?, ?, ?, ?)");
            
            for ($i = 1; $i <= $_POST['total_rooms']; $i++) {
                $roomNumber = $_POST['property_name'] . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                $roomStmt->execute([
                    $propertyId,
                    $roomNumber,
                    $_POST['room_capacity'] ?? 2,
                    'available'
                ]);
            }
        } else {
            // Add single room if total_rooms is 1 or not specified
            $roomStmt = $db->prepare("INSERT INTO property_rooms (property_id, room_number, capacity, status) VALUES (?, ?, ?, ?)");
            $roomStmt->execute([
                $propertyId,
                $_POST['property_name'] . '-001',
                $_POST['room_capacity'] ?? 2,
                'available'
            ]);
        }
        
        // Add features
        if (!empty($_POST['features'])) {
            $features = preg_split('/[\n,]+/', $_POST['features']);
            $featureStmt = $db->prepare("INSERT INTO property_features (property_id, feature_name) VALUES (?, ?)");
            foreach ($features as $feature) {
                if (!empty(trim($feature))) {
                    $featureStmt->execute([$propertyId, trim($feature)]);
                }
            }
        }
        
        // Add virtual tour if provided
        if (!empty($_FILES['virtual_tour']['name'])) {
            $uploadDir = '../../../assets/uploads/virtual_tours/';
            $fileName = 'tour_' . $propertyId . '_' . basename($_FILES['virtual_tour']['name']);
            $targetFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['virtual_tour']['tmp_name'], $targetFile)) {
                $tourStmt = $db->prepare("INSERT INTO property_images (property_id, image_url, is_virtual_tour, media_type) VALUES (?, ?, TRUE, 'virtual_tour')");
                $tourStmt->execute([$propertyId, 'assets/uploads/virtual_tours/' . $fileName]);
            }
        }
        
        $db->commit();
        
        $_SESSION['success'] = "Property added successfully with " . ($_POST['total_rooms'] ?? 1) . " rooms!";
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error adding property: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Property | landlords&Tenant Admin</title>
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
        
        /* Helper Classes */
        .text-muted {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        /* Room Preview Section */
        .room-preview {
            background-color: var(--light-color);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
            border: 1px dashed var(--primary-color);
        }
        
        .room-preview h4 {
            color: var(--secondary-color);
            margin-bottom: 10px;
        }
        
        .room-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .room-item {
            background-color: white;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 8px;
            text-align: center;
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
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
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
                <h2><i class="fas fa-plus-circle"></i> Add New Property</h2>
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
                        <input type="text" name="property_name" class="form-control" required id="propertyName">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Owner *</label>
                                <select name="owner_id" class="form-control select2" required>
                                    <option value="">Select Owner</option>
                                    <?php foreach ($owners as $owner): ?>
                                        <option value="<?php echo $owner['id']; ?>">
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
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <textarea name="description" class="form-control" rows="5" required></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-dollar-sign"></i> Price per Student *</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" name="price" step="0.01" min="0" class="form-control" required>
                                </div>
                                <small class="text-muted">Price charged per student per room</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-info-circle"></i> Status *</label>
                                <select name="status" class="form-control" required>
                                    <option value="available">Available</option>
                                    <option value="booked">Booked</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Location *</label>
                                <input type="text" name="location" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-bed"></i> Bedrooms per unit</label>
                                <input type="number" name="bedrooms" class="form-control" min="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-bath"></i> Bathrooms per unit</label>
                                <input type="number" name="bathrooms" class="form-control" min="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-ruler-combined"></i> Area (sqft)</label>
                                <input type="number" name="area_sqft" step="0.01" class="form-control" min="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Year Built</label>
                                <input type="number" name="year_built" class="form-control" min="1800" max="<?php echo date('Y'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Parking Information</label>
                        <input type="text" name="parking" class="form-control">
                    </div>
                    
                    <!-- Room Configuration Section -->
                    <div class="form-group">
                        <label><i class="fas fa-door-open"></i> Room Configuration</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Total Number of Similar Rooms *</label>
                                    <input type="number" name="total_rooms" class="form-control" min="1" value="1" required id="totalRooms">
                                    <small class="text-muted">Enter the total number of identical rooms/apartments</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Capacity per Room *</label>
                                    <input type="number" name="room_capacity" class="form-control" min="1" value="2" required>
                                    <small class="text-muted">Number of people each room can accommodate</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="room-preview" id="roomPreview">
                            <h4><i class="fas fa-eye"></i> Room Preview</h4>
                            <p>Based on your input, the following rooms will be created:</p>
                            <div class="room-list" id="roomList">
                                <!-- Room items will be dynamically inserted here -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-globe-americas"></i> Latitude</label>
                                <input type="text" name="latitude" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-globe-americas"></i> Longitude</label>
                                <input type="text" name="longitude" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-star"></i> Features</label>
                        <textarea name="features" class="form-control" placeholder="e.g. WiFi, Air Conditioning, Swimming Pool"></textarea>
                        <small class="text-muted">Enter each feature on a new line or separate with commas</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-vr-cardboard"></i> Virtual Tour (Optional)</label>
                        <input type="file" name="virtual_tour" class="form-control" accept=".mp4,.webm,.ogv,.jpg,.jpeg,.png,.gif">
                        <small class="text-muted">Upload a virtual tour video or 360° image</small>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Property
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
            
            // Room preview functionality
            function updateRoomPreview() {
                const totalRooms = parseInt($('#totalRooms').val()) || 1;
                const propertyName = $('#propertyName').val() || 'Property';
                const roomList = $('#roomList');
                
                roomList.empty();
                
                if (totalRooms > 20) {
                    roomList.append(`<div class="room-item">${propertyName}-001</div>`);
                    roomList.append(`<div class="room-item">${propertyName}-002</div>`);
                    roomList.append(`<div class="room-item">...and ${totalRooms - 2} more</div>`);
                    roomList.append(`<div class="room-item">${propertyName}-${String(totalRooms).padStart(3, '0')}</div>`);
                } else {
                    for (let i = 1; i <= totalRooms; i++) {
                        roomList.append(`<div class="room-item">${propertyName}-${String(i).padStart(3, '0')}</div>`);
                    }
                }
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
            
            // Auto-generate location coordinates if possible
            $('input[name="location"]').on('blur', function() {
                if (!$(this).val().trim()) return;
                
                if (!($('input[name="latitude"]').val() || $('input[name="longitude"]').val())) {
                    // In a real implementation, you would call a geocoding API here
                    console.log('Would geocode location:', $(this).val());
                }
            });
        });
    </script>
</body>
</html>