<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Database configuration
require_once 'config/database.php';

// Initialize variables
$search_results = [];
$search_query = '';
$search_filters = [
    'location' => '',
    'min_price' => '',
    'max_price' => '',
    'property_type' => '',
    'amenities' => []
];
$pagination = [
    'page' => 1,
    'per_page' => 10,
    'total' => 0
];

// Process search form if submitted
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    // Sanitize search query
    $search_query = trim(htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8'));
    
    // Get filters from query string
    $search_filters = [
        'location' => trim(htmlspecialchars($_GET['location'] ?? '', ENT_QUOTES, 'UTF-8')),
        'min_price' => filter_input(INPUT_GET, 'min_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
        'max_price' => filter_input(INPUT_GET, 'max_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
        'property_type' => trim(htmlspecialchars($_GET['property_type'] ?? '', ENT_QUOTES, 'UTF-8')),
        'amenities' => isset($_GET['amenities']) ? array_map('intval', $_GET['amenities']) : []
    ];
    
    // Pagination
    $pagination['page'] = max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1);
    
    try {
        $pdo = Database::getInstance();
        
        // Base query
        $sql = "SELECT 
                p.id, 
                p.property_name, 
                p.description, 
                p.price, 
                p.location, 
                p.bedrooms, 
                p.bathrooms,
                p.latitude,
                p.longitude,
                pi.image_url as thumbnail,
                c.name as category,
                AVG(r.rating) as average_rating,
                COUNT(r.id) as review_count
            FROM property p
            LEFT JOIN property_images pi ON p.id = pi.property_id AND pi.is_virtual_tour = 0
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN reviews r ON p.id = r.property_id
            WHERE p.status = 'available' AND p.approved = 1";
        
        // Add search conditions
        $conditions = [];
        $params = [];
        
        if (!empty($search_query)) {
            $conditions[] = "(p.property_name LIKE :search OR p.description LIKE :search OR p.location LIKE :search)";
            $params[':search'] = "%$search_query%";
        }
        
        if (!empty($search_filters['location'])) {
            $conditions[] = "p.location LIKE :location";
            $params[':location'] = "%{$search_filters['location']}%";
        }
        
        if (!empty($search_filters['min_price'])) {
            $conditions[] = "p.price >= :min_price";
            $params[':min_price'] = $search_filters['min_price'];
        }
        
        if (!empty($search_filters['max_price'])) {
            $conditions[] = "p.price <= :max_price";
            $params[':max_price'] = $search_filters['max_price'];
        }
        
        if (!empty($search_filters['property_type'])) {
            $conditions[] = "c.name = :property_type";
            $params[':property_type'] = $search_filters['property_type'];
        }
        
        // Add conditions to query
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        // Group by property
        $sql .= " GROUP BY p.id";
        
        // Count total results for pagination
        $count_sql = "SELECT COUNT(*) as total FROM ($sql) as total_results";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $pagination['total'] = $stmt->fetch()['total'];
        
        // Add sorting and pagination
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'price_asc';
        switch ($sort) {
            case 'price_desc':
                $sql .= " ORDER BY p.price DESC";
                break;
            case 'rating_desc':
                $sql .= " ORDER BY average_rating DESC";
                break;
            case 'newest':
                $sql .= " ORDER BY p.created_at DESC";
                break;
            default:
                $sql .= " ORDER BY p.price ASC";
        }
        
        $sql .= " LIMIT :offset, :limit";
        $params[':offset'] = ($pagination['page'] - 1) * $pagination['per_page'];
        $params[':limit'] = $pagination['per_page'];
        
        // Execute main query
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $param_type);
        }
        
        $stmt->execute();
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Database error in search: " . $e->getMessage());
        $search_error = "An error occurred while searching. Please try again later.";
    }
}

// Get all property types for filter dropdown
try {
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SELECT id, name FROM categories");
    $property_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch property types: " . $e->getMessage());
    $property_types = [];
}

// Get all amenities for filter checkboxes
try {
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SELECT id, feature_name as name FROM property_features GROUP BY feature_name");
    $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch amenities: " . $e->getMessage());
    $amenities = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Accommodation - Landlords&Tenants</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }
        
        .search-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .property-card {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            height: 100%;
        }
        
        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .property-img {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }
        
        .property-price {
            color: var(--accent-color);
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .property-rating {
            color: #ffc107;
        }
        
        #map {
            height: 500px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filter-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .amenities-checkbox {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .pagination .page-link {
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            #map {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <!-- Header (Same as index.php) -->
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <!-- Main Content -->
    <div class="container py-5 mt-4">
        <div class="row">
            <div class="col-md-12">
                <h1 class="mb-4">Search Accommodation</h1>
                
                <!-- Search Form -->
                <div class="search-container">
                    <form action="search.php" method="GET">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="search" placeholder="Search by name, location or description" value="<?= htmlspecialchars($search_query) ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="property_type">
                                    <option value="">All Property Types</option>
                                    <?php foreach ($property_types as $type): ?>
                                        <option value="<?= htmlspecialchars($type['name']) ?>" <?= $search_filters['property_type'] === $type['name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i> Search
                                </button>
                            </div>
                        </div>
                        
                        <!-- Advanced Filters (Collapsible) -->
                        <div class="mt-3">
                            <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#advancedFilters" role="button">
                                <i class="fas fa-sliders-h me-1"></i> Advanced Filters
                            </a>
                            
                            <div class="collapse mt-3" id="advancedFilters">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Location</label>
                                        <input type="text" class="form-control" name="location" placeholder="Specific location" value="<?= htmlspecialchars($search_filters['location']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Min Price (GHS)</label>
                                        <input type="number" class="form-control" name="min_price" placeholder="Min price" value="<?= htmlspecialchars($search_filters['min_price']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Max Price (GHS)</label>
                                        <input type="number" class="form-control" name="max_price" placeholder="Max price" value="<?= htmlspecialchars($search_filters['max_price']) ?>">
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <label class="form-label">Amenities</label>
                                        <div class="amenities-checkbox">
                                            <div class="row">
                                                <?php foreach ($amenities as $amenity): ?>
                                                    <div class="col-md-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="amenities[]" value="<?= $amenity['id'] ?>" 
                                                                <?= in_array($amenity['id'], $search_filters['amenities']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label">
                                                                <?= htmlspecialchars($amenity['name']) ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Results and Map -->
                <div class="row">
                    <!-- Filters Sidebar -->
                    <div class="col-md-3">
                        <div class="filter-section">
                            <h5><i class="fas fa-filter me-2"></i> Refine Results</h5>
                            <hr>
                            
                            <div class="mb-3">
                                <label class="form-label">Sort By</label>
                                <form id="sortForm" method="GET">
                                    <input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>">
                                    <input type="hidden" name="location" value="<?= htmlspecialchars($search_filters['location']) ?>">
                                    <input type="hidden" name="min_price" value="<?= htmlspecialchars($search_filters['min_price']) ?>">
                                    <input type="hidden" name="max_price" value="<?= htmlspecialchars($search_filters['max_price']) ?>">
                                    <input type="hidden" name="property_type" value="<?= htmlspecialchars($search_filters['property_type']) ?>">
                                    
                                    <select class="form-select" name="sort" onchange="document.getElementById('sortForm').submit()">
                                        <option value="price_asc" <?= ($_GET['sort'] ?? 'price_asc') === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                                        <option value="price_desc" <?= ($_GET['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                                        <option value="rating_desc" <?= ($_GET['sort'] ?? '') === 'rating_desc' ? 'selected' : '' ?>>Highest Rating</option>
                                        <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>Newest Listings</option>
                                    </select>
                                </form>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Price Range</label>
                                <div class="d-flex justify-content-between">
                                    <span>GHS <?= $search_filters['min_price'] ?: '0' ?></span>
                                    <span>GHS <?= $search_filters['max_price'] ?: 'Any' ?></span>
                                </div>
                                <input type="range" class="form-range" min="0" max="5000" step="50" disabled>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Property Type</label>
                                <div class="list-group">
                                    <a href="search.php?search=<?= urlencode($search_query) ?>" class="list-group-item list-group-item-action <?= empty($search_filters['property_type']) ? 'active' : '' ?>">
                                        All Types
                                    </a>
                                    <?php foreach ($property_types as $type): ?>
                                        <a href="search.php?search=<?= urlencode($search_query) ?>&property_type=<?= urlencode($type['name']) ?>" 
                                           class="list-group-item list-group-item-action <?= $search_filters['property_type'] === $type['name'] ? 'active' : '' ?>">
                                            <?= htmlspecialchars($type['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Results Column -->
                    <div class="col-md-6">
                        <?php if (isset($search_error)): ?>
                            <div class="alert alert-danger">
                                <?= $search_error ?>
                            </div>
                        <?php elseif (!empty($search_results)): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5><?= $pagination['total'] ?> properties found</h5>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-secondary active" id="listViewBtn">
                                        <i class="fas fa-list"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="mapViewBtn">
                                        <i class="fas fa-map"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div id="listView">
                                <?php foreach ($search_results as $property): ?>
                                    <div class="property-card mb-4">
                                        <div class="row g-0">
                                            <div class="col-md-4">
                                                <img src="<?= htmlspecialchars($property['thumbnail'] ?: 'assets/images/property-placeholder.jpg') ?>" class="property-img" alt="<?= htmlspecialchars($property['property_name']) ?>">
                                            </div>
                                            <div class="col-md-8">
                                                <div class="p-3">
                                                    <div class="d-flex justify-content-between">
                                                        <h5><?= htmlspecialchars($property['property_name']) ?></h5>
                                                        <span class="property-price">GHS <?= number_format($property['price'], 2) ?></span>
                                                    </div>
                                                    <p class="text-muted mb-2">
                                                        <i class="fas fa-map-marker-alt text-primary me-1"></i>
                                                        <?= htmlspecialchars($property['location']) ?>
                                                    </p>
                                                    <p class="mb-2">
                                                        <i class="fas fa-home me-1"></i>
                                                        <?= htmlspecialchars($property['category']) ?>
                                                    </p>
                                                    <p class="mb-2">
                                                        <i class="fas fa-bed me-1"></i> <?= $property['bedrooms'] ?> beds | 
                                                        <i class="fas fa-bath me-1"></i> <?= $property['bathrooms'] ?> baths
                                                    </p>
                                                    
                                                    <?php if ($property['average_rating']): ?>
                                                        <div class="property-rating mb-2">
                                                            <?php 
                                                            $full_stars = floor($property['average_rating']);
                                                            $half_star = ceil($property['average_rating'] - $full_stars);
                                                            
                                                            for ($i = 1; $i <= 5; $i++):
                                                                if ($i <= $full_stars): ?>
                                                                    <i class="fas fa-star"></i>
                                                                <?php elseif ($i == $full_stars + 1 && $half_star): ?>
                                                                    <i class="fas fa-star-half-alt"></i>
                                                                <?php else: ?>
                                                                    <i class="far fa-star"></i>
                                                                <?php endif;
                                                            endfor; ?>
                                                            <small class="text-muted">(<?= $property['review_count'] ?> reviews)</small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted mb-2">No reviews yet</div>
                                                    <?php endif; ?>
                                                    
                                                    <a href="property.php?id=<?= $property['id'] ?>" class="btn btn-sm btn-primary">
                                                        View Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <!-- Pagination -->
                                <?php if ($pagination['total'] > $pagination['per_page']): ?>
                                    <nav aria-label="Search results pagination">
                                        <ul class="pagination justify-content-center">
                                            <?php if ($pagination['page'] > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" 
                                                       href="?search=<?= urlencode($search_query) ?>&page=<?= $pagination['page'] - 1 ?>" 
                                                       aria-label="Previous">
                                                        <span aria-hidden="true">&laquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            $total_pages = ceil($pagination['total'] / $pagination['per_page']);
                                            $start_page = max(1, $pagination['page'] - 2);
                                            $end_page = min($total_pages, $pagination['page'] + 2);
                                            
                                            if ($start_page > 1): ?>
                                                <li class="page-item"><a class="page-link" href="?search=<?= urlencode($search_query) ?>&page=1">1</a></li>
                                                <?php if ($start_page > 2): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                                <li class="page-item <?= $i == $pagination['page'] ? 'active' : '' ?>">
                                                    <a class="page-link" href="?search=<?= urlencode($search_query) ?>&page=<?= $i ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($end_page < $total_pages): ?>
                                                <?php if ($end_page < $total_pages - 1): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                                <li class="page-item"><a class="page-link" href="?search=<?= urlencode($search_query) ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a></li>
                                            <?php endif; ?>
                                            
                                            <?php if ($pagination['page'] < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" 
                                                       href="?search=<?= urlencode($search_query) ?>&page=<?= $pagination['page'] + 1 ?>" 
                                                       aria-label="Next">
                                                        <span aria-hidden="true">&raquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                            
                            <div id="mapView" style="display: none;">
                                <div id="map"></div>
                            </div>
                            
                        <?php elseif (!empty($search_query)): ?>
                            <div class="alert alert-info">
                                No properties found matching your search criteria. Try adjusting your filters.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                Enter search criteria to find available accommodations.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Map Column (Visible on larger screens) -->
                    <div class="col-md-3 d-none d-md-block">
                        <div id="sideMap" style="height: 500px; border-radius: 8px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer (Same as index.php) -->
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    
    <script>
        // Initialize side map
        const sideMap = L.map('sideMap').setView([6.5244, 3.3792], 13); // Default to Lagos coordinates
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(sideMap);
        
        // Add markers to side map if results exist
        <?php if (!empty($search_results)): ?>
            <?php foreach ($search_results as $property): ?>
                <?php if ($property['latitude'] && $property['longitude']): ?>
                    L.marker([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>])
                        .addTo(sideMap)
                        .bindPopup("<b><?= addslashes($property['property_name']) ?></b><br>GHS <?= number_format($property['price'], 2) ?>");
                <?php endif; ?>
            <?php endforeach; ?>
            
            // Fit bounds to show all markers
            <?php 
            $has_coords = array_filter($search_results, function($p) { 
                return !empty($p['latitude']) && !empty($p['longitude']); 
            });
            if (!empty($has_coords)): ?>
                const bounds = new L.LatLngBounds([
                    <?php foreach ($has_coords as $property): ?>
                        [<?= $property['latitude'] ?>, <?= $property['longitude'] ?>],
                    <?php endforeach; ?>
                ]);
                sideMap.fitBounds(bounds);
            <?php endif; ?>
        <?php endif; ?>
        
        // Toggle between list and map view
        document.getElementById('listViewBtn').addEventListener('click', function() {
            document.getElementById('listView').style.display = 'block';
            document.getElementById('mapView').style.display = 'none';
            this.classList.add('active');
            document.getElementById('mapViewBtn').classList.remove('active');
        });
        
        document.getElementById('mapViewBtn').addEventListener('click', function() {
            document.getElementById('listView').style.display = 'none';
            document.getElementById('mapView').style.display = 'block';
            this.classList.add('active');
            document.getElementById('listViewBtn').classList.remove('active');
            
            // Initialize main map if not already done
            if (!window.mainMap) {
                window.mainMap = L.map('map').setView([6.5244, 3.3792], 13);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(window.mainMap);
                
                <?php foreach ($search_results as $property): ?>
                    <?php if ($property['latitude'] && $property['longitude']): ?>
                        L.marker([<?= $property['latitude'] ?>, <?= $property['longitude'] ?>])
                            .addTo(window.mainMap)
                            .bindPopup("<b><?= addslashes($property['property_name']) ?></b><br>GHS <?= number_format($property['price'], 2) ?>");
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <?php if (!empty($has_coords)): ?>
                    window.mainMap.fitBounds(bounds);
                <?php endif; ?>
            }
        });
        
        // Mobile menu toggle (same as index.php)
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('mainMenu').classList.toggle('show');
        });
    </script>
</body>
</html>