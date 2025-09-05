<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a property owner
if ($_SESSION['status'] !== 'property_owner') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get owner data
$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current owner data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch();

if (!$owner) {
    header('Location: ../auth/login.php');
    exit();
}

// Get properties owned by this owner
$properties_stmt = $pdo->prepare("
    SELECT p.id, p.property_name 
    FROM property p 
    WHERE p.owner_id = ? AND p.deleted = 0
    ORDER BY p.property_name
");
$properties_stmt->execute([$owner_id]);
$properties = $properties_stmt->fetchAll();

// Get bookings for the owner's properties with tenant documents
$bookings_stmt = $pdo->prepare("
    SELECT 
        b.id as booking_id,
        b.status as booking_status,
        b.start_date,
        b.end_date,
        u.id as student_id,
        u.username as student_name,
        u.email as student_email,
        u.phone_number as student_phone,
        p.id as property_id,
        p.property_name,
        pr.room_number,
        sd.ghana_card_path,
        sd.passport_path,
        sd.uploaded_at as documents_uploaded_at
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN property p ON b.property_id = p.id
    LEFT JOIN property_rooms pr ON b.room_id = pr.id
    LEFT JOIN student_documents sd ON b.user_id = sd.student_id
    WHERE p.owner_id = ? 
    AND b.status IN ('confirmed', 'paid', 'pending')
    AND sd.student_id IS NOT NULL
    ORDER BY b.created_at DESC
");
$bookings_stmt->execute([$owner_id]);
$bookings = $bookings_stmt->fetchAll();

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Documents - Property Owner Dashboard</title>
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
            background-color: #f5f7f9;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .back-button {
            color: white;
            text-decoration: none;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            transition: background-color var(--transition-speed);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .page-title {
            font-size: 28px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-title {
            font-size: 22px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .filter-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background-color: white;
        }

        .student-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .student-card {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 20px;
            background-color: white;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }

        .student-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .student-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .booking-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-confirmed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-paid {
            background-color: #cce5ff;
            color: #004085;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .student-details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        @media (min-width: 768px) {
            .student-details {
                grid-template-columns: 1fr 1fr;
            }
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        .detail-value {
            font-size: 14px;
            color: var(--secondary-color);
        }

        .documents-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .documents-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 576px) {
            .documents-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .document-card {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 15px;
            background-color: #f8f9fa;
        }

        .document-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .document-icon {
            font-size: 24px;
            color: var(--primary-color);
        }

        .document-name {
            font-weight: 600;
            color: var(--secondary-color);
        }

        .document-preview {
            width: 100%;
            border-radius: var(--border-radius);
            margin-bottom: 10px;
            cursor: pointer;
            transition: transform var(--transition-speed);
        }

        .document-preview:hover {
            transform: scale(1.02);
        }

        .document-actions {
            display: flex;
            justify-content: center;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .no-documents {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-documents-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .modal-image {
            width: 100%;
            height: auto;
            display: block;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            color: white;
            font-size: 30px;
            cursor: pointer;
            background-color: rgba(0, 0, 0, 0.5);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .page-title {
                text-align: left;
                font-size: 24px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-title {
                font-size: 20px;
            }

            .filters {
                flex-direction: column;
            }

            .filter-group {
                min-width: 100%;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }

            .student-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Student Documents</h1>
                <div></div> <!-- Empty div for spacing -->
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-alt"></i>
                Student Verification Documents
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                This section displays verification documents for tenants who have booked your properties. Only tenants with active bookings are shown.
            </div>

            <?php if (empty($properties)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    You don't have any properties listed yet. Students' documents will appear here once they book your properties.
                </div>
            <?php elseif (empty($bookings)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    No tenants with uploaded documents have booked your properties yet.
                </div>
            <?php else: ?>
                <div class="filters">
                    <div class="filter-group">
                        <label class="filter-label" for="propertyFilter">Filter by Property</label>
                        <select id="propertyFilter" class="filter-select">
                            <option value="all">All Properties</option>
                            <?php foreach ($properties as $property): ?>
                                <option value="property-<?php echo $property['id']; ?>"><?php echo htmlspecialchars($property['property_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label" for="statusFilter">Filter by Status</label>
                        <select id="statusFilter" class="filter-select">
                            <option value="all">All Statuses</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>

                <div class="student-list">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="student-card" data-property="property-<?php echo $booking['property_id']; ?>" data-status="<?php echo $booking['booking_status']; ?>">
                            <div class="student-header">
                                <h3 class="student-name"><?php echo htmlspecialchars($booking['student_name']); ?></h3>
                                <span class="booking-status status-<?php echo $booking['booking_status']; ?>">
                                    <?php echo ucfirst($booking['booking_status']); ?>
                                </span>
                            </div>
                            
                            <div class="student-details">
                                <div class="detail-item">
                                    <span class="detail-label">Email</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($booking['student_email']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Phone</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($booking['student_phone']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Property</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($booking['property_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Room</span>
                                    <span class="detail-value"><?php echo !empty($booking['room_number']) ? htmlspecialchars($booking['room_number']) : 'Not assigned'; ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Booking Period</span>
                                    <span class="detail-value">
                                        <?php echo date('M j, Y', strtotime($booking['start_date'])); ?> - 
                                        <?php echo date('M j, Y', strtotime($booking['end_date'])); ?>
                                    </span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Documents Uploaded</span>
                                    <span class="detail-value"><?php echo date('M j, Y g:i A', strtotime($booking['documents_uploaded_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="documents-section">
                                <h4 class="documents-title">
                                    <i class="fas fa-id-card"></i>
                                    Verification Documents
                                </h4>
                                
                                <div class="documents-grid">
                                    <div class="document-card">
                                        <div class="document-header">
                                            <div class="document-icon">
                                                <i class="fas fa-id-card"></i>
                                            </div>
                                            <div class="document-name">Ghana Card</div>
                                        </div>
                                        <img src="<?php echo htmlspecialchars($booking['ghana_card_path']); ?>" alt="Ghana Card" class="document-preview" onclick="openModal(this.src)">
                                        <div class="document-actions">
                                            <a href="<?php echo htmlspecialchars($booking['ghana_card_path']); ?>" download class="btn btn-primary">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="document-card">
                                        <div class="document-header">
                                            <div class="document-icon">
                                                <i class="fas fa-passport"></i>
                                            </div>
                                            <div class="document-name">Passport Photo</div>
                                        </div>
                                        <img src="<?php echo htmlspecialchars($booking['passport_path']); ?>" alt="Passport Photo" class="document-preview" onclick="openModal(this.src)">
                                        <div class="document-actions">
                                            <a href="<?php echo htmlspecialchars($booking['passport_path']); ?>" download class="btn btn-primary">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for image preview -->
    <div class="modal" id="imageModal">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <div class="modal-content">
            <img class="modal-image" id="modalImage" src="" alt="Document preview">
        </div>
    </div>

    <script>
        // Filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            const propertyFilter = document.getElementById('propertyFilter');
            const statusFilter = document.getElementById('statusFilter');
            const studentCards = document.querySelectorAll('.student-card');
            
            function filterStudents() {
                const propertyValue = propertyFilter.value;
                const statusValue = statusFilter.value;
                
                studentCards.forEach(card => {
                    const cardProperty = card.getAttribute('data-property');
                    const cardStatus = card.getAttribute('data-status');
                    
                    const propertyMatch = propertyValue === 'all' || cardProperty === propertyValue;
                    const statusMatch = statusValue === 'all' || cardStatus === statusValue;
                    
                    if (propertyMatch && statusMatch) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
            
            propertyFilter.addEventListener('change', filterStudents);
            statusFilter.addEventListener('change', filterStudents);
        });
        
        // Modal functionality
        function openModal(src) {
            document.getElementById('modalImage').src = src;
            document.getElementById('imageModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modal when clicking outside the image
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>