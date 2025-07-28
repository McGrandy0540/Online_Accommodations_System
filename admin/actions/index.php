<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../../auth/login.php");
    exit();
}

// Database connection
require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get user data from session
$username = $_SESSION['username'] ?? 'Admin';
$email = $_SESSION['email'] ?? 'admin@example.com';

function getAvatarPath($profilePicture) {
    if (empty($profilePicture)) {
        return 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['username'] ?? 'Admin').'&background=4a6bff&color=fff&size=128';
    }
    
    if (filter_var($profilePicture, FILTER_VALIDATE_URL)) {
        return $profilePicture;
    }
    
    // Handle relative paths - adjust based on your directory structure
    if (strpos($profilePicture, '../') === 0) {
        return $profilePicture;
    }
    
    // Check if file exists in the uploads directory
    $filePath = '../uploads/avatars/' . ltrim($profilePicture, '/');
    if (file_exists($filePath)) {
        return $filePath;
    }
    
    // Fallback to default avatar
    return 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['username'] ?? 'Admin').'&background=4a6bff&color=fff&size=128';
}

// Get the avatar path from session
$avatar = getAvatarPath($_SESSION['profile_picture'] ?? '');

// Handle filters and pagination
$actionType = $_GET['type'] ?? '';
$adminId = $_GET['admin_id'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

// Build query
$where = "1=1";
$params = [];

if (!empty($actionType)) {
    $where .= " AND a.action_type = ?";
    $params[] = $actionType;
}

if (!empty($adminId) && is_numeric($adminId)) {
    $where .= " AND a.admin_id = ?";
    $params[] = $adminId;
}

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM admin_actions a
    WHERE $where
");
$stmt->execute($params);
$total = $stmt->fetchColumn();

// Get paginated actions
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("
    SELECT a.*, u.username as admin_name, u.profile_picture as admin_avatar
    FROM admin_actions a
    JOIN users u ON a.admin_id = u.id
    WHERE $where
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique action types for filter dropdown
$actionTypes = [];
$stmt = $pdo->query("SELECT DISTINCT action_type FROM admin_actions ORDER BY action_type");
$actionTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get admin users for filter dropdown
$adminUsers = [];
$stmt = $pdo->query("
    SELECT u.id, u.username, u.profile_picture 
    FROM users u
    JOIN admin a ON u.id = a.user_id
    ORDER BY u.username
");
$adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total pages
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Actions - UniHomes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        :root {
            --primary-color: #4a6bff;
            --primary-hover: #3a56e8;
            --secondary-color: #3a4b8a;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --white: #ffffff;
            --gray-light: #e9ecef;
            --gray: #6c757d;
            --gray-dark: #495057;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            color: var(--gray-dark);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            text-align: center;
        }

        .sidebar-header h2 {
            color: var(--secondary-color);
            margin: 0;
            font-size: 1.25rem;
        }

        .sidebar-menu {
            padding: 15px 0;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--gray-dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .sidebar-menu li a:hover {
            background-color: rgba(74, 107, 255, 0.1);
            color: var(--primary-color);
        }

        .sidebar-menu li a.active {
            background-color: rgba(74, 107, 255, 0.2);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            transition: var(--transition);
        }

        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            cursor: pointer;
            font-size: 1.25rem;
            color: var(--gray-dark);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-profile img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--gray-light);
        }

        .admin-badge {
            background-color: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }

        .content-area {
            padding: 20px;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-title h1 {
            color: var(--secondary-color);
            margin: 0;
            font-size: 1.5rem;
        }

        .breadcrumb {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 10px 0 0;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin: 0 8px;
            color: var(--gray);
        }

        .breadcrumb a {
            color: var(--gray);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: var(--primary-color);
        }

        .actions-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-dark);
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            transition: var(--transition);
            background-color: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.2);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            gap: 8px;
        }

        .btn i {
            font-size: 0.875rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .actions-list {
            margin-top: 20px;
        }

        .action-item {
            padding: 16px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: var(--transition);
        }

        .action-item:last-child {
            border-bottom: none;
        }

        .action-item:hover {
            background-color: rgba(74, 107, 255, 0.03);
        }

        .action-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .action-title {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark-color);
            text-transform: capitalize;
            margin: 0;
        }

        .action-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.8125rem;
            color: var(--gray);
        }

        .action-admin {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            font-weight: 500;
        }

        .action-details {
            margin-top: 5px;
            color: var(--gray-dark);
            white-space: pre-wrap;
            background: var(--light-color);
            padding: 12px;
            border-radius: var(--border-radius);
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.8125rem;
            line-height: 1.5;
            overflow-x: auto;
        }

        .action-target {
            display: inline-block;
            margin-top: 5px;
            font-size: 0.8125rem;
            color: var(--gray);
            background: rgba(108, 117, 125, 0.1);
            padding: 3px 6px;
            border-radius: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--gray-light);
        }

        .empty-state h3 {
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--gray-dark);
        }

        .empty-state p {
            margin-bottom: 20px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            color: var(--primary-color);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .page-link:hover {
            background-color: rgba(74, 107, 255, 0.1);
        }

        .page-link.active {
            background-color: var(--primary-color);
            color: var(--white);
            border-color: var(--primary-color);
        }

        .page-link.disabled {
            color: var(--gray);
            pointer-events: none;
            background-color: var(--gray-light);
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                gap: 12px;
            }

            .filter-group {
                width: 100%;
                min-width: auto;
            }

            .filter-group:last-child {
                display: flex;
                gap: 10px;
                margin-top: 5px;
            }

            .action-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .action-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .content-area {
                padding: 15px;
            }

            .page-title h1 {
                font-size: 1.25rem;
            }
        }

        @media (max-width: 576px) {
            .btn {
                padding: 8px 12px;
                font-size: 0.8125rem;
            }

            .action-item {
                padding: 12px;
            }

            .action-details {
                padding: 10px;
                font-size: 0.75rem;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .action-item {
            animation: fadeIn 0.3s ease forwards;
        }

        .action-item:nth-child(1) { animation-delay: 0.05s; }
        .action-item:nth-child(2) { animation-delay: 0.1s; }
        .action-item:nth-child(3) { animation-delay: 0.15s; }
        .action-item:nth-child(4) { animation-delay: 0.2s; }
        .action-item:nth-child(5) { animation-delay: 0.25s; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>UniHomes Admin</h2>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../admin/"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../admin/properties/"><i class="fas fa-home"></i> Manage Accommodations</a></li>
                    <li><a href="../admin/users/"><i class="fas fa-users"></i> Manage Students</a></li>
                    <li><a href="../admin/payments/"><i class="fas fa-wallet"></i> Payment Management</a></li>
                    <li><a href="../admin/reports/"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a></li>
                    <li><a href="../admin/approvals/"><i class="fas fa-calendar-alt"></i> Booking Approvals</a></li>
                    <li><a href="../admin/admins/"><i class="fas fa-user-shield"></i> Admin Users</a></li>
                    <li><a href="../admin/settings/"><i class="fas fa-cog"></i> System Settings</a></li>
                    <li><a href="../admin/announcement.php"><i class="fa-solid fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="../admin/actions/" class="active"><i class="fas fa-history"></i> Admin Actions</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </div>
                <div class="user-profile">
                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="User Profile" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($username); ?>&background=4a6bff&color=fff&size=128'">
                    <span><?php echo htmlspecialchars($username); ?> <span class="admin-badge">ADMIN</span></span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <div class="page-title">
                        <h1>Admin Actions Log</h1>
                    </div>
                    <ul class="breadcrumb">
                        <li><a href="../admin/">Home</a></li>
                        <li>Admin Actions</li>
                    </ul>
                </div>

                <div class="actions-container">
                    <div class="filters">
                        <div class="filter-group">
                            <label for="action_type">Action Type</label>
                            <select id="action_type" name="action_type" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($actionTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $actionType === $type ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(htmlspecialchars($type)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="admin_id">Admin User</label>
                            <select id="admin_id" name="admin_id" class="form-control">
                                <option value="">All Admins</option>
                                <?php foreach ($adminUsers as $admin): ?>
                                    <option value="<?php echo $admin['id']; ?>" <?php echo $adminId == $admin['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($admin['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group" style="align-self: flex-end;">
                            <button type="button" id="applyFilters" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <button type="button" id="resetFilters" class="btn btn-outline">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </div>
                    
                    <div class="actions-list">
                        <?php if (!empty($actions)): ?>
                            <?php foreach ($actions as $action): ?>
                                <div class="action-item">
                                    <div class="action-header">
                                        <div class="action-title">
                                            <?php echo ucfirst(htmlspecialchars($action['action_type'])); ?>
                                            <?php if ($action['target_type']): ?>
                                                <span class="action-target">
                                                    (Target: <?php echo htmlspecialchars($action['target_type']); ?> #<?php echo htmlspecialchars($action['target_id']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="action-meta">
                                            <span><?php echo date('M j, Y g:i a', strtotime($action['created_at'])); ?></span>
                                            <span class="action-admin">
                                                <img src="<?php echo getAvatarPath($action['admin_avatar'] ?? ''); ?>" alt="<?php echo htmlspecialchars($action['admin_name']); ?>" 
                                                     style="width:20px;height:20px;border-radius:50%;margin-right:5px;" 
                                                     onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($action['admin_name']); ?>&background=4a6bff&color=fff&size=128'">
                                                <?php echo htmlspecialchars($action['admin_name']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($action['details'])): ?>
                                        <div class="action-details">
                                            <?php echo htmlspecialchars($action['details']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No Admin Actions Found</h3>
                                <p>No administrative actions have been recorded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <li class="page-item">
                                <a class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" 
                                   href="?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($actionType); ?>&admin_id=<?php echo urlencode($adminId); ?>">
                                    &laquo;
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item">
                                    <a class="page-link <?php echo $i === $page ? 'active' : ''; ?>" 
                                       href="?page=<?php echo $i; ?>&type=<?php echo urlencode($actionType); ?>&admin_id=<?php echo urlencode($adminId); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item">
                                <a class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" 
                                   href="?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($actionType); ?>&admin_id=<?php echo urlencode($adminId); ?>">
                                    &raquo;
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // Resize handler
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
            }
        });

        // Apply filters
        document.getElementById('applyFilters').addEventListener('click', function() {
            const actionType = document.getElementById('action_type').value;
            const adminId = document.getElementById('admin_id').value;
            
            let url = '?';
            if (actionType) url += `type=${encodeURIComponent(actionType)}&`;
            if (adminId) url += `admin_id=${encodeURIComponent(adminId)}`;
            
            window.location.href = url;
        });

        // Reset filters
        document.getElementById('resetFilters').addEventListener('click', function() {
            window.location.href = '?';
        });
    </script>
</body>
</html>