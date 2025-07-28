<?php
// Start session and check admin status
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

// Get reviews data
try {
    $stmt = $pdo->query("
        SELECT r.*, u.username as student_name, p.property_name 
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        ORDER BY r.created_at DESC
    ");
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $reviews = [];
}

// Handle review actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed.');
    }

    $action = $_POST['action'] ?? '';
    $reviewId = $_POST['review_id'] ?? 0;

    try {
        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);
        } elseif ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
            $stmt->execute([$reviewId]);
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$reviewId]);
        }
        
        // Refresh the page to show changes
        header("Location: reviews.php");
        exit();
    } catch (PDOException $e) {
        error_log("Review Action Error: " . $e->getMessage());
        $error = "Failed to process the action. Please try again.";
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews Management | UniHomes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --sidebar-width: 250px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--light-color);
            color: var(--dark-color);
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            position: fixed;
            height: 100vh;
            transition: var(--transition-speed);
            z-index: 1000;
        }

        .sidebar-header {
            padding: 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .sidebar-menu ul {
            list-style: none;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 0.8rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all var(--transition-speed);
        }

        .sidebar-menu li a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .sidebar-menu li a.active {
            background-color: var(--primary-color);
            color: white;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
        }

        /* Top Navigation */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 1.5rem;
            background-color: white;
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            font-size: 1.2rem;
            cursor: pointer;
            display: none;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-profile img {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
        }

        .admin-badge {
            background-color: var(--accent-color);
            color: white;
            font-size: 0.7rem;
            padding: 2px 5px;
            border-radius: 4px;
            margin-left: 5px;
        }

        /* Content Area */
        .content-area {
            padding: 1.5rem;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .breadcrumb {
            display: flex;
            list-style: none;
            padding: 0.5rem 0;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin: 0 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        /* Reviews Table */
        .reviews-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .reviews-table th, 
        .reviews-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .reviews-table th {
            background-color: var(--secondary-color);
            color: white;
            font-weight: 500;
        }

        .reviews-table tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .review-status {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-pending {
            background-color: var(--warning-color);
            color: var(--dark-color);
        }

        .status-approved {
            background-color: var(--success-color);
            color: white;
        }

        .status-rejected {
            background-color: var(--accent-color);
            color: white;
        }

        .review-rating {
            color: var(--warning-color);
            font-weight: bold;
        }

        .review-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.8rem;
            transition: all var(--transition-speed);
        }

        .approve-btn {
            background-color: var(--success-color);
            color: white;
        }

        .reject-btn {
            background-color: var(--accent-color);
            color: white;
        }

        .delete-btn {
            background-color: #dc3545;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .empty-state i {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .reviews-table {
                display: block;
                overflow-x: auto;
            }

            .review-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.3rem;
            }
        }
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
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../properties/"><i class="fas fa-home"></i> Properties</a></li>
                    <li><a href="../users/"><i class="fas fa-users"></i> Students</a></li>
                    <li><a href="reviews/" class="active"><i class="fas fa-star"></i> Reviews</a></li>
                    <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> Bookings</a></li>
                    <li><a href="../payments/"><i class="fas fa-wallet"></i> Payments</a></li>
                    <li><a href="../settings/"><i class="fas fa-cog"></i> Settings</a></li>
                    <li>
                        <form action="logout.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="dropdown-item" style="background: none; border: none; color: inherit; cursor: pointer; width: 100%; text-align: left;">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </button>
                        </form>
                    </li>
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
                    <img src="<?= htmlspecialchars($_SESSION['avatar'] ?? 'https://randomuser.me/api/portraits/men/32.jpg') ?>" alt="User Profile">
                    <span><?= htmlspecialchars($_SESSION['username']) ?> <span class="admin-badge">ADMIN</span></span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <div class="page-title">
                        <h1>Reviews Management</h1>
                    </div>
                    <ul class="breadcrumb">
                        <li><a href="../">Home</a></li>
                        <li><a href="dashboard.php">Admin</a></li>
                        <li>Reviews</li>
                    </ul>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="background-color: #f8d7da; color: #721c24; padding: 1rem; margin-bottom: 1.5rem; border-radius: var(--border-radius);">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($reviews)): ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <h3>No Reviews Found</h3>
                        <p>There are currently no reviews to display.</p>
                    </div>
                <?php else: ?>
                    <table class="reviews-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Property</th>
                                <th>Student</th>
                                <th>Rating</th>
                                <th>Comment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviews as $review): ?>
                            <tr>
                                <td>#RV-<?= $review['id'] ?></td>
                                <td><?= htmlspecialchars($review['property_name']) ?></td>
                                <td><?= htmlspecialchars($review['student_name']) ?></td>
                                <td class="review-rating">
                                    <?= str_repeat('★', $review['rating']) ?><?= str_repeat('☆', 5 - $review['rating']) ?>
                                </td>
                                <td><?= htmlspecialchars(substr($review['comment'], 0, 50)) ?><?= strlen($review['comment']) > 50 ? '...' : '' ?></td>
                                <td>
                                    <span class="review-status status-<?= $review['status'] ?>">
                                        <?= ucfirst($review['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($review['created_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                        
                                        <div class="review-actions">
                                            <?php if ($review['status'] !== 'approved'): ?>
                                                <button type="submit" name="action" value="approve" class="action-btn approve-btn">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($review['status'] !== 'rejected'): ?>
                                                <button type="submit" name="action" value="reject" class="action-btn reject-btn">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button type="submit" name="action" value="delete" class="action-btn delete-btn">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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

        // Confirm before deleting
        document.querySelectorAll('button[value="delete"]').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this review? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>