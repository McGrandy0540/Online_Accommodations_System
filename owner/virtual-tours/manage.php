<?php
session_start();
require_once __DIR__. '../../../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

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
    header('Location: ../../../auth/login.php');
    exit();
}

// Get all virtual tours with property info
$tours_stmt = $pdo->prepare("SELECT pi.*, p.property_name, p.location, 
                            (SELECT COUNT(*) FROM availability_calendar ac WHERE ac.property_id = p.id AND ac.status = 'booked' AND ac.date >= CURDATE()) as scheduled_viewings,
                            (SELECT COUNT(*) FROM reviews r WHERE r.property_id = p.id) as review_count
                            FROM property_images pi
                            JOIN property p ON pi.property_id = p.id
                            JOIN property_owners po ON p.id = po.property_id
                            WHERE po.owner_id = ? AND pi.is_virtual_tour = 1
                            ORDER BY pi.created_at DESC");
$tours_stmt->execute([$owner_id]);
$virtual_tours = $tours_stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Virtual Tours |Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark-color);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Header Styles */
        .main-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 0.75rem 0;
            box-shadow: var(--box-shadow);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1030;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: white;
            font-weight: 600;
            font-size: 1.4rem;
        }

        .logo img {
            height: 36px;
            margin-right: 10px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            cursor: pointer;
        }

        .user-profile img, .user-profile .avatar-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-profile .avatar-placeholder {
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Dropdown Menu */
        .dropdown-menu {
            border: none;
            box-shadow: var(--box-shadow);
            border-radius: var(--border-radius);
        }

        .dropdown-item {
            padding: 0.5rem 1rem;
            transition: all var(--transition-speed);
        }

        .dropdown-item:hover {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
        }

        .dropdown-divider {
            border-color: rgba(0, 0, 0, 0.05);
        }

        /* Sidebar Styles */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            padding-top: var(--header-height);
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: white;
            color: var(--dark-color);
            position: fixed;
            height: calc(100vh - var(--header-height));
            transition: all var(--transition-speed);
            z-index: 1020;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            border-right: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-header {
            padding: 1.25rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            background-color: var(--primary-color);
            color: white;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            white-space: nowrap;
            margin: 0;
            font-weight: 600;
        }

        .sidebar.collapsed .sidebar-header h2 {
            display: none;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1rem;
            transition: transform var(--transition-speed);
            padding: 0.25rem;
        }

        .sidebar.collapsed .toggle-btn i {
            transform: rotate(180deg);
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--dark-color);
            text-decoration: none;
            transition: all var(--transition-speed);
            white-space: nowrap;
            font-size: 0.95rem;
        }

        .sidebar.collapsed .sidebar-menu li a {
            justify-content: center;
            padding: 0.75rem 0.5rem;
        }

        .sidebar-menu li a:hover {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
        }

        .sidebar-menu li a i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            color: var(--dark-color);
        }

        .sidebar.collapsed .sidebar-menu li a i {
            margin-right: 0;
            font-size: 1.2rem;
        }

        .sidebar-menu li a:hover i {
            color: var(--primary-color);
        }

        .menu-text {
            transition: opacity var(--transition-speed);
        }

        .sidebar.collapsed .menu-text {
            display: none;
        }

        .sidebar-menu li a.active {
            background-color: rgba(var(--primary-color), 0.1);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .sidebar-menu li a.active i {
            color: var(--primary-color);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 1.5rem;
            background-color: #f5f7fa;
        }

        .sidebar.collapsed + .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Dashboard Content */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
            color: var(--secondary-color);
            font-weight: 600;
            margin: 0;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            font-size: 1.2rem;
            color: var(--secondary-color);
            background: none;
            border: none;
            padding: 0.5rem;
        }

        .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background-color: white;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1rem 1.25rem;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: all var(--transition-speed);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--accent-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 1.5rem;
        }

        .table th {
            background-color: var(--light-color);
            color: var(--secondary-color);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }

        .table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover td {
            background-color: rgba(var(--primary-color), 0.05);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 50rem;
        }

        .badge-primary {
            background-color: var(--primary-color);
        }

        .badge-success {
            background-color: var(--success-color);
        }

        .badge-warning {
            background-color: var(--warning-color);
        }

        .badge-danger {
            background-color: var(--accent-color);
        }

        /* Media Preview */
        .media-preview {
            width: 80px;
            height: 60px;
            border-radius: var(--border-radius);
            object-fit: cover;
            transition: transform var(--transition-speed);
        }

        .media-preview:hover {
            transform: scale(1.5);
            z-index: 10;
            position: relative;
            box-shadow: var(--box-shadow);
        }

        /* Schedule Form */
        .schedule-form {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-top: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            transition: all var(--transition-speed);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        /* Footer Styles */
        .main-footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 2.5rem 0 1.5rem;
            margin-top: auto;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .footer-column h3 {
            margin-bottom: 1.25rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .footer-column ul {
            list-style: none;
            padding: 0;
        }

        .footer-column li {
            margin-bottom: 0.75rem;
        }

        .footer-column a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .footer-column a:hover {
            color: white;
            padding-left: 5px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .social-links a {
            color: white;
            font-size: 1.1rem;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            grid-column: 1 / -1;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            background: none;
            border: none;
            padding: 0.5rem;
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1.25rem;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
                box-shadow: none;
            }

            .sidebar.active {
                left: 0;
                box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            }

            .sidebar.collapsed {
                left: calc(-1 * var(--sidebar-collapsed-width));
            }

            .sidebar.collapsed.active {
                left: 0;
                width: var(--sidebar-collapsed-width);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .table th, .table td {
                padding: 0.75rem;
            }
            
            .dashboard-header h1 {
                font-size: 1.5rem;
            }

            .footer-container {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }
            
            .footer-container {
                grid-template-columns: 1fr;
            }
            
            .header-container {
                padding: 0 15px;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .logo img {
                height: 30px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-container">
            <a href="../../" class="logo">
                <img src="../../assets/images/ktu logo.png" alt="UniHomes Logo">
                <span>Landlords&Tenant</span>
            </a>
            
            <div class="user-controls">
                <div class="dropdown">
                    <div class="user-profile dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profile_pic_path)): ?>
                            <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="User Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?= substr($owner['username'], 0, 1) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($owner['username']) ?></span>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="../../auth/logout.php" method="POST">
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Owner Dashboard</h2>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Dashboard</span></a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> <span class="menu-text">My Properties</span></a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Bookings</span></a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> <span class="menu-text">Payments</span></a></li>
                    <li><a href="../reviews/"><i class="fas fa-star"></i> <span class="menu-text">Reviews</span></a></li>
                    <li><a href="../chat/"><i class="fas fa-comments"></i> <span class="menu-text">Messages</span></a></li>
                    <li><a href="../maintenance/"><i class="fas fa-tools"></i> <span class="menu-text">Maintenance</span></a></li>
                    <li><a href="upload.php"><i class="fas fa-video"></i> <span class="menu-text">Virtual Tours</span></a></li>
                    <li><a href="../settings.php"><i class="fas fa-cog"></i> <span class="menu-text">Settings</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Manage Virtual Tours</h1>
                <div>
                    <a href="upload.php" class="btn">
                        <i class="fas fa-plus me-2"></i>New Tour
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-photo-video me-2"></i>Your Virtual Tours</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Preview</th>
                                    <th>Property</th>
                                    <th>Location</th>
                                    <th>Type</th>
                                    <th>Uploaded</th>
                                    <th>Viewings</th>
                                    <th>Reviews</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($virtual_tours as $tour): ?>
                                <tr>
                                    <td>
                                        <?php if (strpos($tour['image_url'], '.mp4') !== false || strpos($tour['image_url'], '.webm') !== false || strpos($tour['image_url'], '.mov') !== false): ?>
                                            <video src="../../<?= htmlspecialchars($tour['image_url']) ?>" class="media-preview" style="object-fit: cover;"></video>
                                        <?php else: ?>
                                            <img src="../../<?= htmlspecialchars($tour['image_url']) ?>" class="media-preview" alt="Tour Preview">
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($tour['property_name']) ?></td>
                                    <td><?= htmlspecialchars($tour['location']) ?></td>
                                    <td>
                                        <span class="badge <?= strpos($tour['image_url'], '.mp4') !== false || strpos($tour['image_url'], '.webm') !== false || strpos($tour['image_url'], '.mov') !== false ? 'badge-primary' : 'badge-success' ?>">
                                            <?= strpos($tour['image_url'], '.mp4') !== false || strpos($tour['image_url'], '.webm') !== false || strpos($tour['image_url'], '.mov') !== false ? 'Video' : 'Image' ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($tour['created_at'])) ?></td>
                                    <td><?= $tour['scheduled_viewings'] ?></td>
                                    <td><?= $tour['review_count'] ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-outline btn-sm schedule-btn" data-id="<?= $tour['id'] ?>" data-property="<?= htmlspecialchars($tour['property_name']) ?>">
                                                <i class="fas fa-calendar-plus"></i> Schedule
                                            </button>
                                            <a href="../../<?= htmlspecialchars($tour['image_url']) ?>" class="btn btn-outline btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <button class="btn btn-outline btn-sm delete-btn" data-id="<?= $tour['id'] ?>">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Schedule Tour Modal -->
            <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Schedule Virtual Tour Viewing</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="scheduleForm">
                                <input type="hidden" id="tour_id" name="tour_id">
                                <div class="mb-3">
                                    <label for="property_name" class="form-label">Property</label>
                                    <input type="text" class="form-control" id="property_name" readonly>
                                </div>
                                <div class="mb-3">
                                    <label for="viewing_date" class="form-label">Viewing Date & Time</label>
                                    <input type="text" class="form-control" id="viewing_date" name="viewing_date" placeholder="Select date and time">
                                </div>
                                <div class="mb-3">
                                    <label for="duration" class="form-label">Duration (minutes)</label>
                                    <select class="form-select" id="duration" name="duration">
                                        <option value="15">15 minutes</option>
                                        <option value="30" selected>30 minutes</option>
                                        <option value="45">45 minutes</option>
                                        <option value="60">60 minutes</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn" id="confirmSchedule">Schedule Viewing</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Delete Virtual Tour</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this virtual tour? This action cannot be undone.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-column">
                <h3>About UniHomes</h3>
                <p>Providing quality student accommodation with modern amenities and secure living spaces.</p>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="../../">Home</a></li>
                    <li><a href="../../properties/">Properties</a></li>
                    <li><a href="../../about/">About Us</a></li>
                    <li><a href="../../contact/">Contact</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support</h3>
                <ul>
                    <li><a href="../../faq/">FAQ</a></li>
                    <li><a href="../../help/">Help Center</a></li>
                    <li><a href="../../privacy/">Privacy Policy</a></li>
                    <li><a href="../../terms/">Terms of Service</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Contact Us</h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt me-2"></i> 123 Campus Drive, University Town</li>
                    <li><i class="fas fa-phone me-2"></i> +233 240687599</li>
                    <li><i class="fas fa-envelope me-2"></i> owners@unihomes.com</li>
                </ul>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            
            <div class="copyright">
                &copy; <?= date('Y') ?> UniHomes. All rights reserved.
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Toggle sidebar collapse
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });

        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 992 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // Initialize date picker
        flatpickr("#viewing_date", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            minDate: "today",
            time_24hr: true
        });

        // Schedule tour modal
        const scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));
        let currentTourId = null;
        
        document.querySelectorAll('.schedule-btn').forEach(button => {
            button.addEventListener('click', function() {
                currentTourId = this.getAttribute('data-id');
                document.getElementById('property_name').value = this.getAttribute('data-property');
                document.getElementById('tour_id').value = currentTourId;
                scheduleModal.show();
            });
        });

        // Confirm schedule
        document.getElementById('confirmSchedule').addEventListener('click', function() {
            const formData = new FormData(document.getElementById('scheduleForm'));
            
            fetch('api/schedule.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Viewing scheduled successfully!');
                    scheduleModal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while scheduling the viewing.');
            });
        });

        // Delete tour modal
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                currentTourId = this.getAttribute('data-id');
                deleteModal.show();
            });
        });

        // Confirm delete
        document.getElementById('confirmDelete').addEventListener('click', function() {
            fetch(`api/delete.php?id=${currentTourId}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tour deleted successfully!');
                    deleteModal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the tour.');
            });
        });

        // Responsive adjustments
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>