<?php
// Start session and check admin authentication
if (session_status() === PHP_SESSION_NONE) session_start();

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit();
}

// Database connection
require_once(__DIR__ . '../../../config/database.php');
$db = Database::getInstance();


// Pagination setup
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Search and filter functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Get filtered properties count for pagination
try {
    $count_query = "SELECT COUNT(*) as total FROM property p 
                   JOIN users u ON p.owner_id = u.id 
                   WHERE p.deleted = 0";
    
    if (!empty($search)) {
        $count_query .= " AND (p.property_name LIKE :search OR p.location LIKE :search OR u.username LIKE :search)";
    }
    if (!empty($status_filter)) {
        $count_query .= " AND p.status = :status";
    }
    
    $count_stmt = $db->prepare($count_query);
    
    if (!empty($search)) {
        $count_stmt->bindValue(':search', "%$search%");
    }
    if (!empty($status_filter)) {
        $count_stmt->bindValue(':status', $status_filter);
    }
    
    $count_stmt->execute();
    $total_items = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_items / $items_per_page);
} catch (PDOException $e) {
    die("Error counting properties: " . $e->getMessage());
}

// Get filtered properties with pagination
try {
    $query = "SELECT p.*, u.username as owner_name, c.name as category_name 
             FROM property p
             JOIN users u ON p.owner_id = u.id
             JOIN categories c ON p.category_id = c.id
             WHERE p.deleted = 0";
    
    if (!empty($search)) {
        $query .= " AND (p.property_name LIKE :search OR p.location LIKE :search OR u.username LIKE :search)";
    }
    if (!empty($status_filter)) {
        $query .= " AND p.status = :status";
    }
    
    $query .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    if (!empty($search)) {
        $stmt->bindValue(':search', "%$search%");
    }
    if (!empty($status_filter)) {
        $stmt->bindValue(':status', $status_filter);
    }
    
    $stmt->execute();
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching properties: " . $e->getMessage());
}

$page_title = "Property Management";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | Landlords&Tenant Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-material-ui/material-ui.css" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

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
            --top-nav-height: 70px;
        }
        
        /* Carousel-specific styles */
        .carousel-container {
            height: 200px;
            overflow: hidden;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .carousel-item img {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }
        
        .carousel-control-prev, .carousel-control-next {
            background-color: rgba(0, 0, 0, 0.3);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .carousel-indicators {
            bottom: 10px;
        }
        
        .carousel-indicators button {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin: 0 5px;
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
            overflow-x: hidden;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            transition: margin-left var(--transition-speed);
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--secondary);
            color: var(--white);
            height: 100vh;
            position: fixed;
            transition: width var(--transition-speed);
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-collapsed .sidebar {
            width: var(--sidebar-collapsed-width);
        }
        
        .sidebar-collapsed .sidebar-brand h2,
        .sidebar-collapsed .sidebar-nav li a span,
        .sidebar-collapsed .sidebar-footer .logout-btn span {
            display: none;
        }
        
        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-left: 10px;
            transition: opacity var(--transition-speed);
        }
        
        .toggle-btn {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .toggle-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav {
            padding: 20px 0;
            height: calc(100vh - 120px);
            overflow-y: auto;
        }
        
        .sidebar-nav::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }
        
        .sidebar-nav ul {
            list-style: none;
        }
        
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--white);
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }
        
        .sidebar-nav li a:hover,
        .sidebar-nav li a.active {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav li a i {
            font-size: 1.1rem;
            min-width: 24px;
            text-align: center;
        }
        
        .sidebar-nav li a span {
            margin-left: 12px;
            transition: opacity var(--transition-speed);
        }
        
        .sidebar-nav li a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--white);
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
        }
        
        .logout-btn {
            display: flex;
            align-items: center;
            color: var(--white);
            text-decoration: none;
            transition: all 0.3s;
            width: 100%;
        }
        
        .logout-btn:hover {
            opacity: 0.8;
        }
        
        .logout-btn i {
            font-size: 1.2rem;
        }
        
        .logout-btn span {
            margin-left: 10px;
            transition: opacity var(--transition-speed);
        }
        
        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            transition: margin-left var(--transition-speed), width var(--transition-speed);
            padding: 20px;
            padding-top: calc(var(--top-nav-height) + 20px);
        }
        
        .sidebar-collapsed .main-content {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }
        
        /* Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            right: 0;
            left: var(--sidebar-width);
            height: var(--top-nav-height);
            background: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            z-index: 999;
            transition: left var(--transition-speed);
        }
        
        .sidebar-collapsed .top-nav {
            left: var(--sidebar-collapsed-width);
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--light-gray);
            border-radius: 30px;
            width: 250px;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        .search-box i {
            position: absolute;
            left: 15px;
            color: var(--gray);
        }
        
        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .notification-btn, .user-profile-btn {
            position: relative;
            cursor: pointer;
            color: var(--dark);
            transition: all 0.3s;
        }
        
        .notification-btn:hover, .user-profile-btn:hover {
            color: var(--primary);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: var(--white);
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--light-gray);
        }
        
        .user-name {
            font-weight: 500;
        }
        
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--white);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            width: 220px;
            padding: 10px 0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .dropdown-menu a:hover {
            background: var(--light);
            color: var(--primary);
        }
        
        .dropdown-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }


         .carousel-item img {
            height: 180px;
            object-fit: cover;
            width: 100%;
        }
        
        .carousel-indicators button {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin: 0 5px;
        }
        
        /* Card Styles */
        .card {
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .card-tools {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 20px;
            overflow-x: auto;
        }
        
        /* Filter Section */
        .filter-section {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-label {
            font-weight: 500;
            color: var(--dark);
        }
        
        .filter-select {
            padding: 8px 15px;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        table th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
            text-align: left;
            padding: 15px;
            position: sticky;
            top: 0;
        }
        
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }
        
        table tr:last-child td {
            border-bottom: none;
        }
        
        table tr:hover {
            background: rgba(67, 97, 238, 0.05);
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
        
        .status.occupied {
            background: #fff0f0;
            color: #f5222d;
        }
        
        .status.maintenance {
            background: #fff7e6;
            color: #fa8c16;
        }
        
        .status.pending {
            background: #e6f7ff;
            color: #1890ff;
        }
        
        /* Property Image Thumbnail */
        .property-image-thumb {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
            transition: transform 0.3s;
        }
        
        .property-image-thumb:hover {
            transform: scale(1.5);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 10;
            position: relative;
        }
        
        .no-image {
            width: 80px;
            height: 60px;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* Button Styles */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
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
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.3);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }
        
        .btn-info {
            background: var(--info);
            color: var(--white);
        }
        
        .btn-info:hover {
            background: #3d85e4;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(72, 149, 239, 0.3);
        }
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background: #e5177b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(247, 37, 133, 0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray);
            color: var(--gray);
        }
        
        .btn-outline:hover {
            background: var(--light-gray);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        
        .page-item {
            list-style: none;
        }
        
        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 6px;
            background: var(--white);
            border: 1px solid var(--light-gray);
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .page-link:hover {
            background: var(--light);
            border-color: var(--gray);
        }
        
        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--white);
        }
        
        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Property Grid */
        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .property-card {
            background: var(--white);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        .property-image {
            height: 200px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--light-gray);
        }
        
        .property-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .property-card:hover .property-image img {
            transform: scale(1.05);
        }
        
        .no-image {
            padding: 20px;
            color: var(--gray);
            text-align: center;
        }
        
        .property-details {
            padding: 20px;
        }
        
        .property-details h3 {
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .property-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .category {
            background: var(--light);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .property-price {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .property-price small {
            display: block;
            font-size: 0.85rem;
            font-weight: normal;
            color: var(--gray);
        }
        
        .property-info {
            margin-bottom: 20px;
        }
        
        .property-info p {
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
        }
        
        .property-info i {
            margin-right: 10px;
            color: var(--primary);
            min-width: 20px;
        }
        
        .property-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        .property-actions .btn {
            padding: 8px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            grid-column: 1 / -1;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--gray);
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--gray);
            margin-bottom: 20px;
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-brand h2,
            .sidebar-nav li a span,
            .sidebar-footer .logout-btn span {
                display: none;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
                width: calc(100% - var(--sidebar-collapsed-width));
            }
            
            .top-nav {
                left: var(--sidebar-collapsed-width);
            }
        }
        
        @media (max-width: 992px) {
            .search-box input {
                width: 200px;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .card-tools {
                width: 100%;
                justify-content: flex-end;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1001;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .top-nav {
                left: 0;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .search-box input {
                width: 150px;
            }
            
            .user-name {
                display: none;
            }
        }
        
        @media (max-width: 576px) {
            .filter-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                width: 100%;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .pagination {
                flex-wrap: wrap;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        /* Utility Classes */
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .mb-3 {
            margin-bottom: 1rem;
        }
        
        .mt-3 {
            margin-top: 1rem;
        }
        
        .d-flex {
            display: flex;
        }
        
        .align-items-center {
            align-items: center;
        }
        
        .justify-content-between {
            justify-content: space-between;
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true' ? 'sidebar-collapsed' : ''; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="d-flex align-items-center">
                    <i class="fas fa-home"></i>
                    <h2>Landlords&Tenant</h2>
                </div>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="../dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                            <i class="fas fa-building"></i>
                            <span>Properties</span>
                        </a>
                    </li>
                    <li>
                        <a href="../users/index.php" class="<?php echo strpos($_SERVER['REQUEST_URI'], 'users') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            <span>Users</span>
                        </a>
                    </li>
                    <li>
                        <a href="../payments/index.php" class="<?php echo strpos($_SERVER['REQUEST_URI'], 'payments') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-credit-card"></i>
                            <span>Payments</span>
                        </a>
                    </li>
                    <li>
                        <a href="../approvals/index.php" class="<?php echo strpos($_SERVER['REQUEST_URI'], 'approvals') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-check-circle"></i>
                            <span>Approvals</span>
                        </a>
                    </li>
                    
                </ul>
            </nav>
            <div class="sidebar-footer">
                <ul>
                    <li>
                        <form action="../logout.php" method="POST">
                           <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" class="dropdown-item">
                              <i class="fas fa-sign-out-alt "></i> Logout
                           </button>
                        </form>
                   </li>
                </ul>

            </div>
        </aside>
        
        <!-- Top Navigation -->
        <nav class="top-nav">
            <div class="nav-left">
                <button class="mobile-menu-btn d-lg-none" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <form method="get" class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search properties..." value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>
            <div class="nav-right">
                <div class="notification-btn">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge"></span>
                </div>
                <div class="user-profile-btn" id="userProfileBtn">
                    <div class="user-profile">
                        <img src="../profiles/<?php echo htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg'); ?>" class="user-avatar" alt="User Avatar">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="../users/view.php?id=<?php echo $_SESSION['user_id']; ?>">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="../settings/notifications.php">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                      
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="card fade-in">
                <div class="card-header">
                    <h2 class="card-title">All Properties</h2>
                    <div class="card-tools">
                        <a href="add.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Property
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="filter-section">
                        <div class="filter-group">
                            <span class="filter-label">Status:</span>
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $status_filter === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <span class="filter-label">Sort By:</span>
                            <select name="sort" class="filter-select" onchange="this.form.submit()">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="price_high">Price (High to Low)</option>
                                <option value="price_low">Price (Low to High)</option>
                            </select>
                        </div>
                    </div>
                    
                    <?php if (empty($properties)): ?>
                        <div class="empty-state">
                            <i class="fas fa-building"></i>
                            <h3>No Properties Found</h3>
                            <p>There are currently no properties matching your criteria.</p>
                            <a href="add.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add New Property
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Image</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Owner</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                        <div class="property-grid">
                            <?php foreach ($properties as $property): ?>
                            <div class="property-card fade-in">
                                <!-- Property Images Carousel -->
                                <?php
                                $image_query = "SELECT image_url FROM property_images WHERE property_id = ?";
                                $image_stmt = $db->prepare($image_query);
                                $image_stmt->execute([$property['id']]);
                                $images = $image_stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                            <div class="carousel-container" >
                                <!-- Carousel Container -->
                                 <div id="carousel-<?= $property['id'] ?>" class="carousel slide">
                                                        <div class="carousel-inner">
                                                            <?php if (!empty($images)): ?>
                                                                <?php foreach ($images as $index => $image): ?>
                                                                    <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                                                        <img src="../../uploads/<?= htmlspecialchars($image['image_url']) ?>" 
                                                                             class="d-block w-100 property-img" 
                                                                             alt="Property image">
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <div class="carousel-item active">
                                                                    <img src="../../assets/images/default-property.jpg" 
                                                                         class="d-block w-100 property-img" 
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
                                </div>
                                <div class="property-details">
                                    <h3><?php echo htmlspecialchars($property['property_name']); ?></h3>
                                    <div class="property-meta">
                                        <span class="category"><?php echo htmlspecialchars($property['category_name']); ?></span>
                                        <span class="status <?php echo strtolower($property['status']); ?>">
                                            <?php echo ucfirst($property['status']); ?>
                                        </span>
                                    </div>
                                    <div class="property-price">
                                        GHS<?php echo number_format($property['price'], 2); ?>
                                        <small>Per student per room</small>
                                    </div>
                                    <div class="property-info">
                                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($property['location']); ?></p>
                                        <p><i class="fas fa-door-open"></i> <?php echo $property['num_rooms']; ?> rooms (<?php echo $property['capacity']; ?> students/room)</p>
                                        <?php if ($property['status'] === 'booked'): ?>
                                            <p><i class="fas fa-bed"></i> <?php echo $property['occupied_rooms']; ?> rooms occupied</p>
                                        <?php endif; ?>
                                        <p><i class="fas fa-user"></i> Owner: <?php echo htmlspecialchars($property['owner_name']); ?></p>
                                    </div>
                                    <div class="property-actions">
                                        <a href="view.php?id=<?php echo $property['id']; ?>" class="btn btn-info">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="edits.php?id=<?php echo $property['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button onclick="confirmDelete(<?php echo $property['id']; ?>)" class="btn btn-danger">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav class="pagination">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status='.urlencode($status_filter) : ''; ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status='.urlencode($status_filter) : ''; ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php 
                            // Show page numbers
                            $start = max(1, $current_page - 2);
                            $end = min($total_pages, $current_page + 2);
                            
                            if ($start > 1) {
                                echo '<li class="page-item"><span class="page-link">...</span></li>';
                            }
                            
                            for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item">
                                    <a class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status='.urlencode($status_filter) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor;
                            
                            if ($end < $total_pages) {
                                echo '<li class="page-item"><span class="page-link">...</span></li>';
                            }
                            ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status='.urlencode($status_filter) : ''; ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status='.urlencode($status_filter) : ''; ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<!-- Add Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Toggle sidebar
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const body = document.body;
    
    sidebarToggle.addEventListener('click', function() {
        body.classList.toggle('sidebar-collapsed');
        // Save state to cookie
        document.cookie = `sidebarCollapsed=${body.classList.contains('sidebar-collapsed')}; path=/; max-age=${60*60*24*30}`;
    });
    
    mobileMenuBtn.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    
    // User dropdown
    const userProfileBtn = document.getElementById('userProfileBtn');
    const userDropdown = document.getElementById('userDropdown');
    
    userProfileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        userDropdown.classList.toggle('show');
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
        userDropdown.classList.remove('show');
    });
    
    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#4361ee',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'delete.php?id=' + id;
            }
        });
    }
    
    // Initialize carousels
   document.addEventListener('DOMContentLoaded', function() {
            const sidebarState = document.cookie.split('; ').find(row => row.startsWith('sidebarCollapsed='));
            if (sidebarState && sidebarState.split('=')[1] === 'true') {
                body.classList.add('sidebar-collapsed');
            }
            
            // // Initialize carousels with 5-second interval
            // var carousels = document.querySelectorAll('.carousel');
            // carousels.forEach(function(carousel) {
            //     new bootstrap.Carousel(carousel, {
            //         interval: 5000,
            //         ride: 'carousel'
            //     });
            // });
    });




            
        
        // Initialize carousels
        document.addEventListener('DOMContentLoaded', function() {
            var carousels = document.querySelectorAll('.carousel');
            carousels.forEach(function(carousel) {
                new bootstrap.Carousel(carousel, {
                    interval: 5000,
                    ride: 'carousel'
                });
            });
        });


        



    
</script>
</body>
</html>



