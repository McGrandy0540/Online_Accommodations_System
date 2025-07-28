<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

$pdo = Database::getInstance();
$owner_id = $_SESSION['user_id'];

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM activity_logs 
    WHERE user_id = ? OR entity_type = 'property' AND entity_id IN (
        SELECT id FROM property WHERE owner_id = ?
    )
");
$stmt->execute([$owner_id, $owner_id]);
$total = $stmt->fetchColumn();
$pages = ceil($total / $perPage);

// Get logs
$stmt = $pdo->prepare("
    SELECT al.*, 
           CASE 
               WHEN al.entity_type = 'property' THEN p.property_name
               WHEN al.entity_type = 'booking' THEN CONCAT('Booking #', b.id)
               WHEN al.entity_type = 'payment' THEN CONCAT('Payment #', py.id)
               ELSE NULL
           END as entity_name
    FROM activity_logs al
    LEFT JOIN property p ON al.entity_type = 'property' AND al.entity_id = p.id
    LEFT JOIN bookings b ON al.entity_type = 'booking' AND al.entity_id = b.id
    LEFT JOIN payments py ON al.entity_type = 'payment' AND al.entity_id = py.id
    WHERE al.user_id = ? OR (al.entity_type = 'property' AND al.entity_id IN (
        SELECT id FROM property WHERE owner_id = ?
    ))
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$owner_id, $owner_id, $perPage, $offset]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity types for filter
$activityTypes = $pdo->query("SELECT DISTINCT action FROM activity_logs")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Audit Log | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vanilla-datatables@1.6.16/dist/vanilla-dataTables.min.css" rel="stylesheet">
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-color);
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Top Navigation Bar */
        .top-nav {
            background: var(--secondary-color);
            color: white;
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            z-index: 1000;
            transition: all var(--transition-speed);
            box-shadow: var(--box-shadow);
        }

        .top-nav-collapsed {
            left: var(--sidebar-collapsed-width);
        }

        .top-nav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.75rem;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        /* Sidebar Styles */
        .sidebar {
            background: var(--secondary-color);
            color: white;
            width: var(--sidebar-width);
            min-height: 100vh;
            transition: all var(--transition-speed);
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
        }

        .sidebar-header {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: var(--header-height);
            display: flex;
            align-items: center;
        }

        .sidebar-menu {
            padding: 1rem 0;
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }

        .sidebar-menu a {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 0;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all var(--transition-speed);
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            color: white;
            background: rgba(0, 0, 0, 0.2);
            border-left: 3px solid var(--primary-color);
        }

        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 1.5rem;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            flex: 1;
            padding: 2rem;
            transition: all var(--transition-speed);
        }

        /* Log Cards */
        .log-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1rem;
            transition: all var(--transition-speed);
        }

        .log-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
        }

        .log-action {
            font-weight: 600;
        }

        .log-timestamp {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .log-body {
            padding: 1.5rem;
        }

        .log-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .log-detail h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .log-detail p {
            margin-bottom: 0;
        }

        .log-entity {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: rgba(52, 152, 219, 0.1);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Pagination */
        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }

        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .page-link {
            color: var(--primary-color);
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-header span, .sidebar-menu a span {
                display: none;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 0.75rem;
            }
            
            .sidebar-menu a i {
                margin-right: 0;
                font-size: 1.25rem;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }

            .top-nav {
                left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .log-details {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
            }

            .log-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-right: 1rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .top-nav {
                left: 0;
            }
            
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }
            
            .sidebar-overlay-open {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>Landlords&Tenant</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="../../property_owner/dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../../property_owner/properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="../../property_owner/bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="../../property_owner/payments/index.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="../../property_owner/audit_log.php" class="active">
                <i class="fas fa-clipboard-list"></i>
                <span>Audit Log</span>
            </a>
            <a href="../../property_owner/chat/">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../../property_owner/maintenance/">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="../../property_owner/settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav" id="topNav">
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-clipboard-list me-2"></i>Audit Log</h5>
        
        <div class="top-nav-right">
            <div class="dropdown">
                <div class="user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <i class="fas fa-chevron-down ms-2 d-none d-md-inline"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../../property_owner/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="../../property_owner/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="filter-section">
                <h4 class="mb-4"><i class="fas fa-filter me-2"></i>Filter Logs</h4>
                <form method="get" id="filterForm">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="filterAction" class="form-label">Action Type</label>
                            <select id="filterAction" name="action" class="form-select">
                                <option value="">All Actions</option>
                                <?php foreach ($activityTypes as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= isset($_GET['action']) && $_GET['action'] === $type ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="filterEntity" class="form-label">Entity Type</label>
                            <select id="filterEntity" name="entity_type" class="form-select">
                                <option value="">All Entities</option>
                                <option value="property" <?= isset($_GET['entity_type']) && $_GET['entity_type'] === 'property' ? 'selected' : '' ?>>Property</option>
                                <option value="booking" <?= isset($_GET['entity_type']) && $_GET['entity_type'] === 'booking' ? 'selected' : '' ?>>Booking</option>
                                <option value="payment" <?= isset($_GET['entity_type']) && $_GET['entity_type'] === 'payment' ? 'selected' : '' ?>>Payment</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="filterDate" class="form-label">Date Range</label>
                            <input type="text" id="filterDate" name="date_range" class="form-control" placeholder="Select date range" 
                                   value="<?= isset($_GET['date_range']) ? htmlspecialchars($_GET['date_range']) : '' ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="audit_log.php" class="btn btn-outline-secondary">
                                <i class="fas fa-undo me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (empty($logs)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No activity logs found.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($logs as $log): ?>
                        <div class="col-lg-6">
                            <div class="log-card card">
                                <div class="log-header">
                                    <div class="log-action">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))) ?>
                                    </div>
                                    <div class="log-timestamp">
                                        <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
                                    </div>
                                </div>
                                <div class="log-body">
                                    <div class="log-details">
                                        <div class="log-detail">
                                            <h6><i class="fas fa-user me-2"></i>User</h6>
                                            <p><?= htmlspecialchars($log['user_id'] === $owner_id ? 'You' : 'System') ?></p>
                                        </div>
                                        <div class="log-detail">
                                            <h6><i class="fas fa-cube me-2"></i>Entity</h6>
                                            <p>
                                                <?php if ($log['entity_type'] && $log['entity_name']): ?>
                                                    <span class="log-entity">
                                                        <i class="fas fa-<?= 
                                                            $log['entity_type'] === 'property' ? 'home' : 
                                                            ($log['entity_type'] === 'booking' ? 'calendar-check' : 'money-bill-wave')
                                                        ?> me-1"></i>
                                                        <?= htmlspecialchars($log['entity_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <em>None</em>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="log-detail">
                                            <h6><i class="fas fa-network-wired me-2"></i>IP Address</h6>
                                            <p><?= htmlspecialchars($log['ip_address'] ?? 'Not recorded') ?></p>
                                        </div>
                                    </div>
                                    
                                    <?php if ($log['user_agent']): ?>
                                        <div class="mb-3">
                                            <h6><i class="fas fa-desktop me-2"></i>Device Info</h6>
                                            <p class="small text-muted"><?= htmlspecialchars($log['user_agent']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['details']): ?>
                                        <div>
                                            <h6><i class="fas fa-info-circle me-2"></i>Details</h6>
                                            <div class="bg-light p-2 rounded small">
                                                <?= nl2br(htmlspecialchars($log['details'])) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    // Mobile menu toggle
    document.getElementById('mobileMenuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
        document.getElementById('sidebarOverlay').classList.toggle('sidebar-overlay-open');
    });

    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('sidebar-open');
        this.classList.remove('sidebar-overlay-open');
    });

    // Initialize date range picker
    flatpickr("#filterDate", {
        mode: "range",
        dateFormat: "Y-m-d",
        allowInput: true
    });

    // Filter form submission
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        // You can add additional client-side validation here if needed
    });
    </script>
</body>
</html>