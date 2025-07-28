<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
require_once 'sentiment_analysis.php'; // AI sentiment analysis functions

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['status'] ?? null;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// Function to analyze sentiment using AI
function analyzeSentiment($text) {
    // In a real implementation, you would call an AI service API here
    // For demo purposes, we'll use our local sentiment analysis function
    return performSentimentAnalysis($text);
}

// Handle form submission for new reviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $property_id = $_POST['property_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $_SESSION['error_message'] = "Please select a valid rating between 1 and 5 stars.";
        header("Location: reviews.php");
        exit();
    }
    
    // Validate comment
    if (empty(trim($comment))) {
        $_SESSION['error_message'] = "Please write your review comment.";
        header("Location: reviews.php");
        exit();
    }
    
    // Analyze sentiment
    $sentiment = analyzeSentiment($comment);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (property_id, user_id, rating, comment, sentiment_score, sentiment_label) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $property_id,
            $user_id,
            $rating,
            $comment,
            $sentiment['score'],
            $sentiment['label']
        ]);
        
        $_SESSION['success_message'] = "Review submitted successfully!";
        header("Location: reviews.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error submitting review: " . $e->getMessage();
        header("Location: reviews.php");
        exit();
    }
}

// Fetch reviews based on user type
if ($user_type === 'property_owner') {
    // Property owners see reviews for their properties
    $query = "
        SELECT r.*, u.username, u.profile_picture, p.property_name,
               CONCAT(u.username, ' reviewed ', p.property_name) as review_title
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        WHERE p.owner_id = ?
        ORDER BY r.created_at DESC
    ";
    $params = [$user_id];
} else {
    // Students and admins see all reviews
    $query = "
        SELECT r.*, u.username, u.profile_picture, p.property_name,
               CONCAT(u.username, ' reviewed ', p.property_name) as review_title
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        ORDER BY r.created_at DESC
    ";
    $params = [];
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_reviews = count($reviews);
$average_rating = $total_reviews > 0 ? 
    array_sum(array_column($reviews, 'rating')) / $total_reviews : 0;
$positive_reviews = array_filter($reviews, fn($r) => $r['sentiment_label'] === 'positive');
$negative_reviews = array_filter($reviews, fn($r) => $r['sentiment_label'] === 'negative');
$neutral_reviews = array_filter($reviews, fn($r) => $r['sentiment_label'] === 'neutral');

// Fetch properties for review form (only for students)
$properties = [];
if ($user_type === 'student') {
    $stmt = $pdo->prepare("
        SELECT p.id, p.property_name 
        FROM property p
        JOIN bookings b ON p.id = b.property_id
        WHERE b.user_id = ? AND b.status = 'paid'
    ");
    $stmt->execute([$user_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reviews | Landlords&Tenants</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css" rel="stylesheet">
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
            background-size: cover;
            background-position: center;
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

        /* Stats Cards */
        .stats-card {
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--card-shadow);
        }

        .stats-card-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
        }

        .stats-card-success {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
        }

        .stats-card-warning {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
        }

        .stats-card-danger {
            background: linear-gradient(135deg, var(--accent-color), #c0392b);
        }

        .stats-card-info {
            background: linear-gradient(135deg, var(--info-color), #138496);
        }

        .stats-card h3 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stats-card p {
            margin-bottom: 0;
            opacity: 0.9;
        }

        /* Review Cards */
        .review-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            transition: all var(--transition-speed);
            overflow: hidden;
        }

        .review-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .review-card .card-header {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: none;
            background-color: rgba(var(--primary-color), 0.1);
        }

        .review-card .user-avatar {
            width: 50px;
            height: 50px;
            margin-right: 1rem;
        }

        .review-card .user-info {
            flex: 1;
        }

        .review-card .user-info h5 {
            margin-bottom: 0.25rem;
        }

        .review-card .user-info .text-muted {
            font-size: 0.875rem;
        }

        .review-card .property-name {
            color: var(--primary-color);
            font-weight: 600;
        }

        .review-card .rating {
            color: var(--warning-color);
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .review-card .sentiment {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .sentiment-positive {
            background-color: rgba(var(--success-color), 0.2);
            color: var(--success-color);
        }

        .sentiment-negative {
            background-color: rgba(var(--accent-color), 0.2);
            color: var(--accent-color);
        }

        .sentiment-neutral {
            background-color: rgba(var(--info-color), 0.2);
            color: var(--info-color);
        }

        .review-card .card-body {
            padding: 1.5rem;
        }

        .review-card .review-text {
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .review-card .review-date {
            font-size: 0.875rem;
            color: var(--dark-color);
            opacity: 0.7;
        }

        /* Review Form */
        .review-form {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .review-form .form-group {
            margin-bottom: 1.5rem;
        }

        .review-form label {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .rating-input {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .rating-input .stars {
            display: flex;
            gap: 0.25rem;
        }

        .rating-input .star {
            cursor: pointer;
            font-size: 1.5rem;
            color: #ddd;
            transition: color 0.2s;
        }

        .rating-input .star.active {
            color: var(--warning-color);
        }

        /* Charts */
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            height: 100%;
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
            .stats-card {
                margin-bottom: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .review-card .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .review-card .user-avatar {
                margin-bottom: 1rem;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }

            .user-dropdown span {
                display: none;
            }

            .stats-card h3 {
                font-size: 1.5rem;
            }

            .review-form .rating-input {
                flex-direction: column;
                align-items: flex-start;
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
            <a href="../dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="../bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="../payments/index.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="reviews.php" class="active">
                <i class="fas fa-star"></i>
                <span>Reviews</span>
            </a>
            <a href="../chat/index.php">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../settings.php">
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
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-star me-2"></i>Reviews & Ratings</h5>
        
        <div class="top-nav-right">
            <div class="dropdown">
                <div class="user-dropdown" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar" style="background-image: url('<?= $_SESSION['profile_picture'] ?? '' ?>')">
                        <?= empty($_SESSION['profile_picture']) ? strtoupper(substr($_SESSION['username'], 0, 1)) : '' ?>
                    </div>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <i class="fas fa-chevron-down ms-2 d-none d-md-inline"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="/property_owner/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="/property_owner/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
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
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= $_SESSION['error_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <div class="row mb-4">
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-primary">
                        <h3><?= $total_reviews ?></h3>
                        <p>Total Reviews</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-warning">
                        <h3><?= number_format($average_rating, 1) ?>/5</h3>
                        <p>Average Rating</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-success">
                        <h3><?= count($positive_reviews) ?></h3>
                        <p>Positive Reviews</p>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card stats-card-danger">
                        <h3><?= count($negative_reviews) ?></h3>
                        <p>Negative Reviews</p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Sentiment Analysis</h5>
                        <canvas id="sentimentChart"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-star me-2"></i>Rating Distribution</h5>
                        <canvas id="ratingChart"></canvas>
                    </div>
                </div>
            </div>

            <?php if ($user_type === 'student' && !empty($properties)): ?>
                <div class="review-form mb-4">
                    <h4 class="mb-4"><i class="fas fa-edit me-2"></i>Write a Review</h4>
                    <form method="POST" action="reviews.php">
                        <div class="form-group">
                            <label for="property_id">Property</label>
                            <select class="form-control" id="property_id" name="property_id" required>
                                <option value="">Select a property</option>
                                <?php foreach ($properties as $property): ?>
                                    <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['property_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Rating</label>
                            <div class="rating-input">
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star star" data-rating="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating" id="rating" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="comment">Your Review</label>
                            <textarea class="form-control" id="comment" name="comment" rows="5" required 
                                      placeholder="Share your experience with this property..."></textarea>
                        </div>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Submit Review
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <h4 class="mb-4"><i class="fas fa-list me-2"></i>Recent Reviews</h4>
            
            <?php if (empty($reviews)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No reviews found.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($reviews as $review): ?>
                        <div class="col-lg-6 col-12 mb-4">
                            <div class="review-card card">
                                <div class="card-header">
                                    <div class="user-avatar" style="background-image: url('<?= $review['profile_picture'] ?? '' ?>')">
                                        <?= empty($review['profile_picture']) ? strtoupper(substr($review['username'], 0, 1)) : '' ?>
                                    </div>
                                    <div class="user-info">
                                        <h5><?= htmlspecialchars($review['username']) ?></h5>
                                        <small class="text-muted">Reviewed <span class="property-name"><?= htmlspecialchars($review['property_name']) ?></span></small>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-empty' ?>"></i>
                                        <?php endfor; ?>
                                        <span class="ms-2"><?= $review['rating'] ?>.0</span>
                                    </div>
                                    
                                    <div class="sentiment sentiment-<?= $review['sentiment_label'] ?>">
                                        <?= ucfirst($review['sentiment_label']) ?> (<?= number_format($review['sentiment_score'] * 100, 0) ?>%)
                                    </div>
                                    
                                    <div class="review-text">
                                        <?= nl2br(htmlspecialchars($review['comment'])) ?>
                                    </div>
                                    
                                    <div class="review-date">
                                        <i class="far fa-clock me-1"></i>
                                        <?= date('F j, Y', strtotime($review['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        if (mobileMenuToggle && sidebar && sidebarOverlay) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('sidebar-open');
                sidebarOverlay.classList.toggle('sidebar-overlay-open');
            });

            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('sidebar-open');
                this.classList.remove('sidebar-overlay-open');
            });
        }

        // Initialize Bootstrap dropdowns
        const dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
        const dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl);
        });

        // Initialize Bootstrap alerts
        const alertList = document.querySelectorAll('.alert');
        alertList.forEach(function (alert) {
            new bootstrap.Alert(alert);
        });

        // Star rating functionality
        document.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                const stars = this.parentElement.querySelectorAll('.star');
                
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
                
                document.getElementById('rating').value = rating;
            });
        });

        // Initialize charts
        // Sentiment Chart
        const sentimentCtx = document.getElementById('sentimentChart');
        if (sentimentCtx) {
            new Chart(sentimentCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Positive', 'Negative', 'Neutral'],
                    datasets: [{
                        data: [<?= count($positive_reviews) ?>, <?= count($negative_reviews) ?>, <?= count($neutral_reviews) ?>],
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.8)',
                            'rgba(231, 76, 60, 0.8)',
                            'rgba(23, 162, 184, 0.8)'
                        ],
                        borderColor: [
                            'rgba(40, 167, 69, 1)',
                            'rgba(231, 76, 60, 1)',
                            'rgba(23, 162, 184, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const value = context.raw;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${context.label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Rating Distribution Chart
        const ratingCtx = document.getElementById('ratingChart');
        if (ratingCtx) {
            // Calculate rating distribution
            const ratingCounts = [0, 0, 0, 0, 0];
            <?php foreach ($reviews as $review): ?>
                ratingCounts[<?= $review['rating'] ?> - 1]++;
            <?php endforeach; ?>
            
            new Chart(ratingCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                    datasets: [{
                        label: 'Number of Reviews',
                        data: ratingCounts,
                        backgroundColor: 'rgba(255, 193, 7, 0.7)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.parsed.y} reviews`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Form validation
        const reviewForm = document.querySelector('form[method="POST"]');
        if (reviewForm) {
            reviewForm.addEventListener('submit', function(e) {
                const rating = document.getElementById('rating').value;
                const comment = document.getElementById('comment').value.trim();
                
                if (!rating || rating < 1 || rating > 5) {
                    e.preventDefault();
                    alert('Please select a valid rating between 1 and 5 stars.');
                    return false;
                }
                
                if (!comment) {
                    e.preventDefault();
                    alert('Please write your review comment.');
                    return false;
                }
                
                return true;
            });
        }
    });
    </script>
</body>
</html>