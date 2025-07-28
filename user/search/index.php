<?php
session_start();
require_once __DIR__. '../../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$pdo = Database::getInstance();
$student_id = $_SESSION['user_id'];

// Get filter options from database
$categories = $pdo->query("SELECT * FROM categories")->fetchAll();
$locations = $pdo->query("SELECT DISTINCT location FROM property WHERE approved = 1")->fetchAll(PDO::FETCH_COLUMN);

// Default filters
$filters = [
    'min_price' => $_GET['min_price'] ?? 0,
    'max_price' => $_GET['max_price'] ?? 2500,
    'category' => $_GET['category'] ?? '',
    'location' => $_GET['location'] ?? '',
    'bedrooms' => $_GET['bedrooms'] ?? '',
    'gender' => $_GET['gender'] ?? '',
    'amenities' => isset($_GET['amenities']) ? (array)$_GET['amenities'] : []
];

// FIXED: Show all approved properties regardless of room availability
$query = "SELECT 
            p.*, 
            (SELECT image_url FROM property_images WHERE property_id = p.id LIMIT 1) as thumbnail,
            (SELECT AVG(rating) FROM reviews WHERE property_id = p.id) as average_rating,
            p.price as per_person_price
          FROM property p
          WHERE p.approved = 1 AND p.deleted = 0";

// Apply filters
$params = [];
if (!empty($filters['category'])) {
    $query .= " AND p.category_id = ?";
    $params[] = $filters['category'];
}
if (!empty($filters['location'])) {
    $query .= " AND p.location LIKE ?";
    $params[] = '%' . $filters['location'] . '%';
}
if (!empty($filters['bedrooms'])) {
    $query .= " AND p.bedrooms >= ?";
    $params[] = $filters['bedrooms'];
}
if (!empty($filters['min_price'])) {
    $query .= " AND p.price >= ?";
    $params[] = $filters['min_price'];
}
if (!empty($filters['max_price'])) {
    $query .= " AND p.price <= ?";
    $params[] = $filters['max_price'];
}
if (!empty($filters['gender'])) {
    $query .= " AND EXISTS (
        SELECT 1 FROM property_rooms pr 
        WHERE pr.property_id = p.id 
        AND pr.gender = ? 
        AND pr.status = 'available'
        AND pr.levy_payment_status = 'approved'
    )";
    $params[] = $filters['gender'];
}

// Add sorting
$sort = $_GET['sort'] ?? 'newest';
switch ($sort) {
    case 'price_asc':
        $query .= " ORDER BY p.price ASC";
        break;
    case 'price_desc':
        $query .= " ORDER BY p.price DESC";
        break;
    case 'rating':
        $query .= " ORDER BY average_rating DESC";
        break;
    default:
        $query .= " ORDER BY p.created_at DESC";
}

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$properties = $stmt->fetchAll();

// Get amenities for filter options
$amenities = $pdo->query("SELECT DISTINCT feature_name FROM property_features")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Accommodation | UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
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
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
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
        }

        .search-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .search-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .search-main {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 15px;
        }

        .filter-sidebar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.25rem;
            height: fit-content;
            position: sticky;
            top: 80px;
        }

        .filter-section {
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding-bottom: 1rem;
        }

        .filter-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .filter-title {
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--secondary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .filter-title i {
            transition: transform 0.3s ease;
        }

        .filter-title.collapsed i {
            transform: rotate(-90deg);
        }

        .filter-content {
            max-height: 300px;
            overflow-y: auto;
            transition: max-height 0.3s ease;
        }

        .filter-content.collapse:not(.show) {
            display: block;
            max-height: 0;
            overflow: hidden;
        }

        .price-range {
            padding: 0.5rem 0;
        }

        .price-inputs {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .price-inputs input {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }

        .form-check {
            margin-bottom: 0.5rem;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .results-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }

        .results-count {
            font-weight: 500;
        }

        .sort-dropdown .dropdown-toggle {
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 0.5rem 1rem;
        }

        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.25rem;
        }

        .property-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .property-image {
            height: 200px;
            position: relative;
            overflow: hidden;
        }

        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .property-card:hover .property-image img {
            transform: scale(1.05);
        }

        .property-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            z-index: 10;
        }

        .property-content {
            padding: 1.25rem;
        }

        .property-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .property-price span {
            font-size: 0.9rem;
            font-weight: 400;
            color: #6c757d;
        }

        .property-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .property-location {
            display: flex;
            align-items: center;
            color: #6c757d;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }

        .property-location i {
            margin-right: 0.5rem;
        }

        .property-features {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .property-feature {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .property-rating {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stars {
            color: #ffc107;
            margin-right: 0.5rem;
        }

        .rating-count {
            color: #6c757d;
            font-size: 0.8rem;
        }

        .property-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        #map-view {
            height: 400px;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            display: none;
        }

        .view-toggle {
            display: flex;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .view-toggle-btn {
            padding: 0.5rem 1rem;
            background: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-toggle-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .view-toggle-btn:first-child {
            border-right: 1px solid #ddd;
        }

        .mobile-filter-btn {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            font-size: 1.5rem;
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .levy-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--success-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            z-index: 10;
        }
        
        .property-image .carousel {
            height: 200px;
        }
        
        .property-image .carousel-inner,
        .property-image .carousel-item,
        .property-image .carousel-item img {
            height: 100%;
            width: 100%;
            object-fit: cover;
        }
        
        .carousel-control-prev,
        .carousel-control-next {
            background-color: rgba(0,0,0,0.3);
            width: 30px;
            height: 30px;
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner h2 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .welcome-banner p {
            max-width: 800px;
            opacity: 0.9;
        }
        
        .banner-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 4rem;
            opacity: 0.2;
        }
        
        .capacity-info {
            background-color: #e3f2fd;
            border-left: 3px solid var(--primary-color);
            padding: 0.75rem;
            margin: 1rem 0;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            font-size: 0.9rem;
        }
        
        .capacity-info h5 {
            color: var(--primary-color);
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        
        .room-detail {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e9ecef;
        }
        
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .room-title {
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .availability-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .available {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .limited {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .full {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .progress-container {
            margin: 0.5rem 0;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .room-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.5rem;
        }
        
        .per-person-price {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .capacity-stats {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .gender-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .male-badge {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .female-badge {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .room-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .room-table th {
            background-color: #e9ecef;
            padding: 0.5rem;
            text-align: left;
            font-weight: 600;
            border: 1px solid #dee2e6;
        }
        
        .room-table td {
            padding: 0.5rem;
            border: 1px solid #dee2e6;
        }
        
        .room-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .room-table tr:hover {
            background-color: #e9ecef;
        }
        
        .booking-count {
            display: inline-block;
            background-color: var(--info-color);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }
        
        .room-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .available-count {
            font-weight: 600;
        }
        
        .property-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .property-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .available-badge {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .full-badge {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        @media (max-width: 992px) {
            .search-main {
                grid-template-columns: 1fr;
            }

            .filter-sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 90%;
                max-width: 400px;
                height: 100vh;
                z-index: 1100;
                overflow-y: auto;
                transition: left 0.3s ease;
            }

            .filter-sidebar.active {
                left: 0;
            }

            .mobile-filter-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .sort-dropdown {
                width: 100%;
            }

            .sort-dropdown .dropdown-toggle {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .property-grid {
                grid-template-columns: 1fr;
            }

            .property-actions {
                flex-direction: column;
            }
            
            .room-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .property-summary {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .room-table {
                font-size: 0.8rem;
            }
            
            .room-table th, 
            .room-table td {
                padding: 0.3rem;
            }
        }
    </style>
</head>
<body>
    <!-- Search Header -->
    <header class="search-header">
        <div class="search-container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="../dashboard.php" class="text-white">
                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                </a>
                <h1 class="h4 mb-0">Find Accommodation</h1>
                <div class="d-flex align-items-center">
                    <button id="toggleView" class="btn btn-sm btn-light me-2">
                        <i class="fas fa-map-marked-alt"></i>
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown">
                            <i class="fas fa-sort me-1"></i> Sort
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="?<?= http_build_query(array_merge($_GET, ['sort' => 'newest'])) ?>">Newest First</a></li>
                            <li><a class="dropdown-item" href="?<?= http_build_query(array_merge($_GET, ['sort' => 'price_asc'])) ?>">Price: Low to High</a></li>
                            <li><a class="dropdown-item" href="?<?= http_build_query(array_merge($_GET, ['sort' => 'price_desc'])) ?>">Price: High to Low</a></li>
                            <li><a class="dropdown-item" href="?<?= http_build_query(array_merge($_GET, ['sort' => 'rating'])) ?>">Highest Rating</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="search-main">
        <!-- Filter Sidebar -->
        <aside class="filter-sidebar" id="filterSidebar">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Filters</h2>
                <button class="btn btn-sm btn-outline-primary" id="resetFilters">
                    <i class="fas fa-redo me-1"></i> Reset
                </button>
            </div>

            <form id="searchFilters" method="GET">
                <!-- Price Range -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#priceFilter">
                        <span>Price Range (Per Student)</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="priceFilter" class="filter-content collapse show">
                        <div class="range-slider">
                            <input type="range" class="form-range" min="0" max="10000" step="50" 
                                   id="priceRange" value="<?= $filters['max_price'] ?>">
                        </div>
                        <div class="price-inputs">
                            <div class="form-group">
                                <label for="minPrice" class="form-label">Min</label>
                                <input type="number" class="form-control" id="minPrice" 
                                       name="min_price" value="<?= $filters['min_price'] ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label for="maxPrice" class="form-label">Max</label>
                                <input type="number" class="form-control" id="maxPrice" 
                                       name="max_price" value="<?= $filters['max_price'] ?>" max="10000">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#locationFilter">
                        <span>Location</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="locationFilter" class="filter-content collapse show">
                        <select class="form-select mb-2" name="location">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>" 
                                    <?= $filters['location'] === $loc ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Property Type -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#typeFilter">
                        <span>Property Type</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="typeFilter" class="filter-content collapse show">
                        <select class="form-select" name="category">
                            <option value="">All Types</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" 
                                    <?= $filters['category'] == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Bedrooms -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#bedroomFilter">
                        <span>Bedrooms</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="bedroomFilter" class="filter-content collapse show">
                        <select class="form-select" name="bedrooms">
                            <option value="">Any</option>
                            <option value="1" <?= $filters['bedrooms'] == 1 ? 'selected' : '' ?>>1+</option>
                            <option value="2" <?= $filters['bedrooms'] == 2 ? 'selected' : '' ?>>2+</option>
                            <option value="3" <?= $filters['bedrooms'] == 3 ? 'selected' : '' ?>>3+</option>
                            <option value="4" <?= $filters['bedrooms'] == 4 ? 'selected' : '' ?>>4+</option>
                        </select>
                    </div>
                </div>

                <!-- Gender -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#genderFilter">
                        <span>Gender Preference</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="genderFilter" class="filter-content collapse show">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="genderAny" value="" 
                                <?= empty($filters['gender']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="genderAny">Any</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="genderMale" value="male" 
                                <?= $filters['gender'] === 'male' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="genderMale">Male Only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="genderFemale" value="female" 
                                <?= $filters['gender'] === 'female' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="genderFemale">Female Only</label>
                        </div>
                    </div>
                </div>

                <!-- Amenities -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#amenitiesFilter">
                        <span>Amenities</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="amenitiesFilter" class="filter-content collapse show">
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="amenities[]" 
                                       id="amenity-<?= preg_replace('/[^a-z0-9]/', '-', strtolower($amenity)) ?>" 
                                       value="<?= htmlspecialchars($amenity) ?>"
                                       <?= in_array($amenity, $filters['amenities']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="amenity-<?= preg_replace('/[^a-z0-9]/', '-', strtolower($amenity)) ?>">
                                    <?= htmlspecialchars($amenity) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Levy Status Filter -->
                <div class="filter-section">
                    <div class="filter-title" data-bs-toggle="collapse" data-bs-target="#levyFilter">
                        <span>Levy Status</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div id="levyFilter" class="filter-content collapse show">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="levy_paid" 
                                   id="levyPaid" checked disabled>
                            <label class="form-check-label" for="levyPaid">
                                <i class="fas fa-check-circle text-success me-1"></i> Levy Paid (Required)
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Hidden field to preserve sorting -->
                <input type="hidden" name="sort" value="<?= $sort ?>">

                <button type="submit" class="btn btn-primary w-100 mt-3">
                    <i class="fas fa-search me-2"></i> Apply Filters
                </button>
            </form>
        </aside>

        <!-- Results Section -->
        <section class="results-container">
            <div class="welcome-banner">
                <h2>Find Your Perfect Accommodation</h2>
                <p>Search through our verified properties with guaranteed levy payment compliance for a safe and secure stay.</p>
                <i class="fas fa-home banner-icon"></i>
            </div>
            
            <div class="results-header">
                <div class="results-count">
                    <?= count($properties) ?> properties found
                </div>
                <div class="view-toggle">
                    <button class="view-toggle-btn active" id="listViewBtn">
                        <i class="fas fa-list"></i> List
                    </button>
                    <button class="view-toggle-btn" id="mapViewBtn">
                        <i class="fas fa-map"></i> Map
                    </button>
                </div>
            </div>

            <!-- Map View (Hidden by default) -->
            <div id="map-view"></div>

            <!-- List View -->
            <div id="list-view">
                <?php if (empty($properties)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> No properties match your search criteria.
                        Try adjusting your filters.
                    </div>
                <?php else: ?>
                    <div class="property-grid">
                        <?php foreach ($properties as $property): 
                            // Get images for THIS property
                            $image_query = "SELECT image_url FROM property_images WHERE property_id = ?";
                            $image_stmt = $pdo->prepare($image_query);
                            $image_stmt->execute([$property['id']]);
                            $images = $image_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // FIXED: Get room details with correct availability calculation
                            $room_query = "SELECT pr.*, 
                                           (SELECT COUNT(*) 
                                            FROM bookings b 
                                            WHERE b.room_id = pr.id 
                                            AND b.status IN ('pending', 'confirmed', 'paid')
                                           ) AS pending_bookings
                                           FROM property_rooms pr 
                                           WHERE pr.property_id = ? 
                                           AND pr.levy_payment_status = 'approved'
                                           AND pr.status = 'available'
                                           ORDER BY pr.room_number ASC";
                            $room_stmt = $pdo->prepare($room_query);
                            $room_stmt->execute([$property['id']]);
                            $rooms = $room_stmt->fetchAll();
                            
                            // Calculate total available spots
                            $total_available_spots = 0;
                            $total_bookings = 0;
                            foreach ($rooms as $room) {
                                $available_spots = $room['capacity'] - $room['current_occupancy'] - $room['pending_bookings'];
                                $total_available_spots += max(0, $available_spots);
                                $total_bookings += $room['pending_bookings'];
                            }
                        ?>
                            <div class="property-card">
                                <div class="property-image">
                                    <div id="carousel-<?= $property['id'] ?>" class="carousel slide" data-bs-ride="carousel">
                                        <div class="carousel-inner">
                                            <?php if (!empty($images)): ?>
                                                <?php foreach ($images as $index => $image): ?>
                                                    <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                                        <img src="../../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                                             class="d-block w-100" 
                                                             alt="Property image">
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="carousel-item active">
                                                    <img src="../../assets/images/default-property.jpg" 
                                                         class="d-block w-100" 
                                                         alt="Default property image">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (count($images) > 1): ?>
                                            <button class="carousel-control-prev" type="button" 
                                                    data-bs-target="#carousel-<?= $property['id'] ?>" 
                                                    data-bs-slide="prev">
                                                <span class="carousel-control-prev-icon"></span>
                                            </button>
                                            <button class="carousel-control-next" type="button" 
                                                    data-bs-target="#carousel-<?= $property['id'] ?>" 
                                                    data-bs-slide="next">
                                                <span class="carousel-control-next-icon"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <span class="property-badge">
                                        <?= htmlspecialchars($property['status']) ?>
                                    </span>
                                    <span class="levy-badge" title="Levy payment approved">
                                        <i class="fas fa-check-circle"></i> Levy Paid
                                    </span>
                                </div>
                                <div class="property-content">
                                    <div class="property-summary">
                                        <div>
                                            <h3 class="property-title">
                                                <a href="details.php?id=<?= $property['id'] ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($property['property_name']) ?>
                                                </a>
                                            </h3>
                                            <div class="property-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?= htmlspecialchars($property['location']) ?>
                                            </div>
                                        </div>
                                        <div class="property-status">
                                            <?php if ($total_available_spots > 0): ?>
                                                <span class="status-badge available-badge">Available</span>
                                            <?php else: ?>
                                                <span class="status-badge full-badge">Fully Booked</span>
                                            <?php endif; ?>
                                            <div class="property-price">
                                                GHS <?= number_format($property['per_person_price'], 2) ?> 
                                                <span>/year (per student)</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="property-info">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="booking-count">
                                                <i class="fas fa-bookmark"></i> <?= $total_bookings ?> bookings
                                            </span>
                                            <span class="available-count ms-3">
                                                <i class="fas fa-user-friends"></i> <?= $total_available_spots ?> spots available
                                            </span>
                                        </div>
                                        
                                        <!-- Room Details Table -->
                                        <div class="capacity-info">
                                            <h5>Room Details</h5>
                                            <table class="room-table">
                                                <thead>
                                                    <tr>
                                                        <th>Room</th>
                                                        <th>Capacity</th>
                                                        <th>Occupancy</th>
                                                        <th>Gender</th>
                                                        <th>Available</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($rooms as $room): 
                                                        $available_spots = $room['capacity'] - $room['current_occupancy'] - $room['pending_bookings'];
                                                        
                                                        // Determine availability status
                                                        if ($available_spots <= 0) {
                                                            $availability_class = "full";
                                                            $availability_text = "Fully Booked";
                                                        } elseif ($available_spots <= 2) {
                                                            $availability_class = "limited";
                                                            $availability_text = "Limited";
                                                        } else {
                                                            $availability_class = "available";
                                                            $availability_text = "Available";
                                                        }
                                                        
                                                        // Gender badge
                                                        $gender_class = ($room['gender'] == 'male') ? 'male-badge' : 'female-badge';
                                                        $gender_icon = ($room['gender'] == 'male') ? 'mars' : 'venus';
                                                    ?>
                                                        <tr>
                                                            <td><?= $room['room_number'] ?></td>
                                                            <td><?= $room['capacity'] ?></td>
                                                            <td><?= $room['current_occupancy'] ?> occupied</td>
                                                            <td>
                                                                <span class="gender-badge <?= $gender_class ?>">
                                                                    <i class="fas fa-<?= $gender_icon ?>"></i> <?= ucfirst($room['gender']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="availability-badge <?= $availability_class ?>">
                                                                    <?= $available_spots > 0 ? $available_spots . ' spots' : 'Full' ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($available_spots > 0): ?>
                                                                    <a href="../bookings/create.php?property_id=<?= $property['id'] ?>&room_id=<?= $room['id'] ?>" 
                                                                       class="btn btn-sm btn-primary">
                                                                        <i class="fas fa-calendar-plus me-1"></i> Book
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Fully Booked</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="property-features">
                                        <div class="property-feature">
                                            <i class="fas fa-bed"></i>
                                            <?= $property['bedrooms'] ?? 0 ?> beds
                                        </div>
                                        <div class="property-feature">
                                            <i class="fas fa-bath"></i>
                                            <?= $property['bathrooms'] ?? 0 ?> baths
                                        </div>
                                        <div class="property-feature">
                                            <i class="fas fa-ruler-combined"></i>
                                            <?= $property['area_sqft'] ? number_format($property['area_sqft']) : 'N/A' ?> sqft
                                        </div>
                                    </div>
                                    <?php if ($property['average_rating']): ?>
                                        <div class="property-rating">
                                            <div class="stars">
                                                <?php
                                                $fullStars = floor($property['average_rating']);
                                                $halfStar = ceil($property['average_rating'] - $fullStars);
                                                $emptyStars = 5 - $fullStars - $halfStar;
                                                
                                                for ($i = 0; $i < $fullStars; $i++) {
                                                    echo '<i class="fas fa-star"></i>';
                                                }
                                                if ($halfStar) {
                                                    echo '<i class="fas fa-star-half-alt"></i>';
                                                }
                                                for ($i = 0; $i < $emptyStars; $i++) {
                                                    echo '<i class="far fa-star"></i>';
                                                }
                                                ?>
                                            </div>
                                            <span class="rating-count">
                                                (<?= $property['average_rating'] ?>)
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="property-actions">
                                        <a href="details.php?id=<?= $property['id'] ?>" class="btn btn-outline">
                                            <i class="far fa-eye"></i> View Details
                                        </a>
                                        <?php if ($total_available_spots > 0): ?>
                                            <a href="../bookings/create.php?property_id=<?= $property['id'] ?>" class="btn btn-primary">
                                                <i class="far fa-calendar-plus"></i> Quick Book
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p class="mt-2">Loading properties...</p>
            </div>
        </section>
    </main>

    <!-- Mobile Filter Button -->
    <button class="mobile-filter-btn" id="mobileFilterBtn">
        <i class="fas fa-filter"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <script>
        // Initialize Bootstrap components
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })
        
        const collapseElements = [].slice.call(document.querySelectorAll('.filter-content.collapse'))
        collapseElements.forEach(function (collapseEl) {
            new bootstrap.Collapse(collapseEl, { toggle: false })
        })

        // Toggle filter sidebar on mobile
        document.getElementById('mobileFilterBtn').addEventListener('click', function() {
            document.getElementById('filterSidebar').classList.toggle('active');
        });

        // Toggle between list and map view
        const listViewBtn = document.getElementById('listViewBtn');
        const mapViewBtn = document.getElementById('mapViewBtn');
        const listView = document.getElementById('list-view');
        const mapView = document.getElementById('map-view');
        const toggleViewBtn = document.getElementById('toggleView');

        function showListView() {
            listView.style.display = 'block';
            mapView.style.display = 'none';
            listViewBtn.classList.add('active');
            mapViewBtn.classList.remove('active');
            toggleViewBtn.innerHTML = '<i class="fas fa-map-marked-alt"></i>';
        }

        function showMapView() {
            listView.style.display = 'none';
            mapView.style.display = 'block';
            listViewBtn.classList.remove('active');
            mapViewBtn.classList.add('active');
            toggleViewBtn.innerHTML = '<i class="fas fa-list"></i>';
            
            // Initialize map if not already done
            if (!window.mapInitialized) {
                initMap();
                window.mapInitialized = true;
            }
        }

        // Set initial view
        showListView();

        listViewBtn.addEventListener('click', showListView);
        mapViewBtn.addEventListener('click', showMapView);
        toggleViewBtn.addEventListener('click', function() {
            if (listView.style.display === 'block') {
                showMapView();
            } else {
                showListView();
            }
        });

        // Initialize Leaflet map
        function initMap() {
            const map = L.map('map-view').setView([5.6037, -0.1870], 13); // Default to Accra coordinates
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            // Add property markers
            <?php foreach ($properties as $property): ?>
                <?php if ($property['latitude'] && $property['longitude']): ?>
                    L.marker([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>])
                        .addTo(map)
                        .bindPopup(`
                            <b><?= addslashes($property['property_name']) ?></b><br>
                            GHS <?= number_format($property['per_person_price'], 2) ?>/year (per student)<br>
                            <i class="fas fa-check-circle text-success"></i> Levy Paid<br>
                            <a href="details.php?id=<?= $property['id'] ?>" target="_blank">View Details</a>
                        `);
                <?php endif; ?>
            <?php endforeach; ?>
        }

        // Price range slider
        const priceRange = document.getElementById('priceRange');
        const minPriceInput = document.getElementById('minPrice');
        const maxPriceInput = document.getElementById('maxPrice');

        // Initialize slider value
        priceRange.value = <?= $filters['max_price'] ?>;

        priceRange.addEventListener('input', function() {
            maxPriceInput.value = this.value;
        });

        minPriceInput.addEventListener('change', function() {
            if (parseInt(this.value) > parseInt(maxPriceInput.value)) {
                this.value = maxPriceInput.value;
            }
            priceRange.value = maxPriceInput.value;
        });

        maxPriceInput.addEventListener('change', function() {
            if (parseInt(this.value) < parseInt(minPriceInput.value)) {
                this.value = minPriceInput.value;
            }
            priceRange.value = this.value;
        });

        // Filter form submission
        document.getElementById('searchFilters').addEventListener('submit', function(e) {
            // Show loading spinner
            document.getElementById('loadingSpinner').style.display = 'block';
            document.getElementById('list-view').style.opacity = '0.5';
        });

        // Reset filters
        document.getElementById('resetFilters').addEventListener('click', function() {
            window.location.href = 'index.php';
        });

        // Collapsible filter sections
        document.querySelectorAll('.filter-title').forEach(title => {
            title.addEventListener('click', function() {
                const target = this.getAttribute('data-bs-target');
                const collapse = new bootstrap.Collapse(document.querySelector(target));
                collapse.toggle();
                this.classList.toggle('collapsed');
            });
        });

        // Initialize carousels
        document.querySelectorAll('.carousel').forEach(carousel => {
            new bootstrap.Carousel(carousel);
        });
    </script>
</body>
</html>