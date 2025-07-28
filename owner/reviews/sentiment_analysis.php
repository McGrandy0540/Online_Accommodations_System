<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/TextAnalysis.php';

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['status'] ?? null;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// Initialize text analysis class
$textAnalyzer = new TextAnalysis();

// Handle form submission for new reviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $property_id = $_POST['property_id'];
    $rating = $_POST['rating'];
    $comment = trim($_POST['comment']);
    
    // Validate input
    if (empty($comment)) {
        $_SESSION['error_message'] = "Please enter your review comment";
        header("Location: sentiment_analysis.php");
        exit();
    }
    
    // Analyze sentiment
    $sentiment = $textAnalyzer->analyzeSentiment($comment);
    $keywords = $textAnalyzer->extractKeywords($comment);
    $keywordsString = implode(', ', array_keys($keywords));
    
    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (property_id, user_id, rating, comment, sentiment_score, sentiment_label, keywords) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $property_id,
            $user_id,
            $rating,
            $comment,
            $sentiment['score'],
            $sentiment['label'],
            $keywordsString
        ]);
        
        // Update property average rating
        updatePropertyRating($pdo, $property_id);
        
        $_SESSION['success_message'] = "Review submitted successfully!";
        header("Location: sentiment_analysis.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error submitting review: " . $e->getMessage();
    }
}

// Function to update property average rating
function updatePropertyRating($pdo, $property_id) {
    $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating FROM reviews WHERE property_id = ?");
    $stmt->execute([$property_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $avg_rating = $result['avg_rating'] ?? 0;
    
    $updateStmt = $pdo->prepare("UPDATE property SET average_rating = ? WHERE id = ?");
    $updateStmt->execute([$avg_rating, $property_id]);
}

// Fetch reviews based on user type
if ($user_type === 'property_owner') {
    // Property owners see reviews for their properties with sentiment analysis
    $query = "
        SELECT r.*, u.username, u.profile_picture, p.property_name,
               CONCAT(u.username, ' reviewed ', p.property_name) as review_title,
               CASE 
                   WHEN r.sentiment_label = 'positive' THEN 'Positive'
                   WHEN r.sentiment_label = 'negative' THEN 'Negative'
                   ELSE 'Neutral'
               END as sentiment_display,
               IFNULL(r.sentiment_score, 0) * 100 as sentiment_percentage
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        WHERE p.owner_id = ?
        ORDER BY r.created_at DESC
    ";
    $params = [$user_id];
} else if ($user_type === 'admin') {
    // Admins see all reviews with detailed sentiment analysis
    $query = "
        SELECT r.*, u.username, u.profile_picture, p.property_name, po.username as owner_name,
               CONCAT(u.username, ' reviewed ', p.property_name) as review_title,
               CASE 
                   WHEN r.sentiment_label = 'positive' THEN 'Positive'
                   WHEN r.sentiment_label = 'negative' THEN 'Negative'
                   ELSE 'Neutral'
               END as sentiment_display,
               IFNULL(r.sentiment_score, 0) * 100 as sentiment_percentage,
               p.owner_id
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        JOIN users po ON p.owner_id = po.id
        ORDER BY r.created_at DESC
    ";
    $params = [];
} else {
    // Students see all reviews for properties they can review
    $query = "
        SELECT r.*, u.username, u.profile_picture, p.property_name,
               CONCAT(u.username, ' reviewed ', p.property_name) as review_title,
               CASE 
                   WHEN r.sentiment_label = 'positive' THEN 'Positive'
                   WHEN r.sentiment_label = 'negative' THEN 'Negative'
                   ELSE 'Neutral'
               END as sentiment_display,
               IFNULL(r.sentiment_score, 0) * 100 as sentiment_percentage
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        JOIN property p ON r.property_id = p.id
        WHERE p.id IN (SELECT property_id FROM bookings WHERE user_id = ? AND status = 'paid')
        ORDER BY r.created_at DESC
    ";
    $params = [$user_id];
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_reviews = count($reviews);
$average_rating = $total_reviews > 0 ? 
    array_sum(array_column($reviews, 'rating')) / $total_reviews : 0;

// Sentiment distribution
$sentiment_counts = [
    'positive' => 0,
    'negative' => 0,
    'neutral' => 0
];

foreach ($reviews as $review) {
    $sentiment = $review['sentiment_label'] ?? 'neutral';
    $sentiment_counts[$sentiment]++;
}

// Rating distribution
$rating_counts = [0, 0, 0, 0, 0];
foreach ($reviews as $review) {
    $rating = (int)$review['rating'];
    if ($rating >= 1 && $rating <= 5) {
        $rating_counts[$rating - 1]++;
    }
}

// Fetch properties for review form (only for students)
$properties = [];
if ($user_type === 'student') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.id, p.property_name 
        FROM property p
        JOIN bookings b ON p.id = b.property_id
        WHERE b.user_id = ? AND b.status = 'paid'
        AND NOT EXISTS (
            SELECT 1 FROM reviews r 
            WHERE r.property_id = p.id AND r.user_id = ?
        )
    ");
    $stmt->execute([$user_id, $user_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch top keywords from all reviews
$all_comments = array_column($reviews, 'comment');
$top_keywords = $textAnalyzer->extractKeywords(implode(' ', $all_comments), 10);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentiment Analysis | Landlords&Tenant</title>
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

        /* Navigation styles */
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

        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            flex: 1;
            padding: 2rem;
            transition: all var(--transition-speed);
        }

        /* Stats cards */
        .stats-card {
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }

        .stats-card:hover {
            transform: translateY(-5px);
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

        /* Review cards */
        .review-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            transition: all var(--transition-speed);
            overflow: hidden;
        }

        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .sentiment-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .sentiment-positive {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }

        .sentiment-negative {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--accent-color);
        }

        .sentiment-neutral {
            background-color: rgba(23, 162, 184, 0.2);
            color: var(--info-color);
        }

        /* Keyword tags */
        .keyword-tag {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            margin: 0.25rem;
            font-size: 0.875rem;
            transition: all 0.3s;
        }

        .keyword-tag:hover {
            background-color: var(--primary-hover);
            transform: scale(1.05);
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            .sidebar .nav-link span {
                display: none;
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
            .chart-container {
                margin-bottom: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }
            .top-nav h5 {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="d-flex flex-column h-100">
            <div class="sidebar-header p-3 text-center">
                <h4 class="mb-0 text-white"><i class="fas fa-home me-2"></i>Landlords&Tenant</h4>
            </div>
            <nav class="sidebar-menu p-3 flex-grow-1">
                <a href="/dashboard.php" class="nav-link text-white mb-2">
                    <i class="fas fa-tachometer-alt me-2"></i>
                    <span>Dashboard</span>
                </a>
                <a href="/properties/" class="nav-link text-white mb-2">
                    <i class="fas fa-building me-2"></i>
                    <span>Properties</span>
                </a>
                <a href="/bookings/" class="nav-link text-white mb-2">
                    <i class="fas fa-calendar-check me-2"></i>
                    <span>Bookings</span>
                </a>
                <a href="/reviews/" class="nav-link text-white mb-2 active">
                    <i class="fas fa-star me-2"></i>
                    <span>Reviews</span>
                </a>
                <a href="/sentiment_analysis.php" class="nav-link text-white mb-2">
                    <i class="fas fa-chart-line me-2"></i>
                    <span>Sentiment Analysis</span>
                </a>
                <a href="/settings.php" class="nav-link text-white mb-2">
                    <i class="fas fa-cog me-2"></i>
                    <span>Settings</span>
                </a>
            </nav>
        </div>
    </div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-white"><i class="fas fa-chart-line me-2"></i>Sentiment Analysis Dashboard</h5>
            <div class="dropdown">
                <button class="btn btn-transparent dropdown-toggle text-white" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stats-card stats-card-primary">
                        <h3 class="display-5"><?= $total_reviews ?></h3>
                        <p class="mb-0">Total Reviews</p>
                        <i class="fas fa-comments fa-2x opacity-25 float-end"></i>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stats-card stats-card-warning">
                        <h3 class="display-5"><?= number_format($average_rating, 1) ?></h3>
                        <p class="mb-0">Average Rating</p>
                        <i class="fas fa-star fa-2x opacity-25 float-end"></i>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stats-card stats-card-success">
                        <h3 class="display-5"><?= $sentiment_counts['positive'] ?></h3>
                        <p class="mb-0">Positive Reviews</p>
                        <i class="fas fa-smile fa-2x opacity-25 float-end"></i>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stats-card stats-card-danger">
                        <h3 class="display-5"><?= $sentiment_counts['negative'] ?></h3>
                        <p class="mb-0">Negative Reviews</p>
                        <i class="fas fa-frown fa-2x opacity-25 float-end"></i>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row mb-4">
                <div class="col-lg-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Sentiment Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="sentimentChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="fas fa-star-half-alt me-2"></i>Rating Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="ratingChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Keywords and Review Form Row -->
            <div class="row mb-4">
                <div class="col-lg-4 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="fas fa-tags me-2"></i>Top Keywords</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($top_keywords)): ?>
                                <div class="d-flex flex-wrap">
                                    <?php foreach ($top_keywords as $keyword => $count): ?>
                                        <span class="keyword-tag">
                                            <?= htmlspecialchars($keyword) ?> 
                                            <span class="badge bg-white text-primary ms-1"><?= $count ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">No keywords extracted yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($user_type === 'student' && !empty($properties)): ?>
                <div class="col-lg-8 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Write a Review</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="sentiment_analysis.php">
                                <div class="mb-3">
                                    <label for="property_id" class="form-label">Property</label>
                                    <select class="form-select" id="property_id" name="property_id" required>
                                        <option value="">Select a property you've stayed at</option>
                                        <?php foreach ($properties as $property): ?>
                                            <option value="<?= $property['id'] ?>"><?= htmlspecialchars($property['property_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Rating</label>
                                    <div class="rating-input">
                                        <div class="stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star star" data-rating="<?= $i ?>" style="cursor: pointer; font-size: 1.5rem; color: #ddd;"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <input type="hidden" name="rating" id="rating" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="comment" class="form-label">Your Review</label>
                                    <textarea class="form-control" id="comment" name="comment" rows="4" required placeholder="Share your detailed experience..."></textarea>
                                    <small class="text-muted">Your review will be analyzed for sentiment automatically.</small>
                                </div>
                                <button type="submit" name="submit_review" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Review
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reviews List -->
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Recent Reviews</h5>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-2" id="filterPositive">
                            <i class="fas fa-smile me-1"></i> Positive
                        </button>
                        <button class="btn btn-sm btn-outline-danger me-2" id="filterNegative">
                            <i class="fas fa-frown me-1"></i> Negative
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="filterAll">
                            <i class="fas fa-filter me-1"></i> All
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($reviews)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-comment-slash fa-3x text-muted mb-3"></i>
                            <h5>No reviews found</h5>
                            <p class="text-muted">There are no reviews to display yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" id="reviewsContainer">
                            <?php foreach ($reviews as $review): ?>
                                <div class="col-md-6 mb-4 review-item" data-sentiment="<?= $review['sentiment_label'] ?? 'neutral' ?>">
                                    <div class="review-card card h-100">
                                        <div class="card-header d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <?php if (!empty($review['profile_picture'])): ?>
                                                    <img src="<?= htmlspecialchars($review['profile_picture']) ?>" class="rounded-circle" width="50" height="50" alt="User avatar">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                        <?= strtoupper(substr($review['username'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0"><?= htmlspecialchars($review['username']) ?></h6>
                                                <small class="text-muted">Reviewed <?= htmlspecialchars($review['property_name']) ?></small>
                                            </div>
                                            <?php if ($user_type === 'admin'): ?>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link text-secondary" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item" href="#"><i class="fas fa-edit me-2"></i>Edit</a></li>
                                                        <li><a class="dropdown-item text-danger" href="#"><i class="fas fa-trash me-2"></i>Delete</a></li>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="rating">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-empty' ?> text-warning"></i>
                                                    <?php endfor; ?>
                                                    <span class="ms-2"><?= $review['rating'] ?>.0</span>
                                                </div>
                                                <?php if (isset($review['sentiment_label'])): ?>
                                                <span class="sentiment-badge sentiment-<?= $review['sentiment_label'] ?>">
                                                    <?= ucfirst($review['sentiment_label']) ?> 
                                                    <?php if (isset($review['sentiment_percentage'])): ?>
                                                    (<?= number_format($review['sentiment_percentage'], 0) ?>%)
                                                    <?php endif; ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="review-text"><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                                            <?php if (!empty($review['keywords'])): ?>
                                            <div class="mt-2 mb-3">
                                                <small class="text-muted">Keywords:</small>
                                                <div class="d-flex flex-wrap mt-1">
                                                    <?php 
                                                    $keywords = explode(',', $review['keywords']);
                                                    foreach ($keywords as $keyword): 
                                                        $keyword = trim($keyword);
                                                        if (!empty($keyword)):
                                                    ?>
                                                        <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars($keyword) ?></span>
                                                    <?php 
                                                        endif;
                                                    endforeach; 
                                                    ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="far fa-clock me-1"></i>
                                                    <?= date('M j, Y', strtotime($review['created_at'])) ?>
                                                </small>
                                                <?php if ($user_type === 'admin'): ?>
                                                    <small class="text-muted">
                                                        Owner: <?= htmlspecialchars($review['owner_name'] ?? '') ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Sentiment Chart
        const sentimentCtx = document.getElementById('sentimentChart').getContext('2d');
        const sentimentChart = new Chart(sentimentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Positive', 'Negative', 'Neutral'],
                datasets: [{
                    data: [
                        <?= $sentiment_counts['positive'] ?>, 
                        <?= $sentiment_counts['negative'] ?>, 
                        <?= $sentiment_counts['neutral'] ?>
                    ],
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
                },
                cutout: '70%'
            }
        });

        // Rating Distribution Chart
        const ratingCtx = document.getElementById('ratingChart').getContext('2d');
        const ratingChart = new Chart(ratingCtx, {
            type: 'bar',
            data: {
                labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                datasets: [{
                    label: 'Number of Reviews',
                    data: [<?= implode(',', $rating_counts) ?>],
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

        // Star rating functionality
        document.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.getAttribute('data-rating'));
                const stars = this.parentElement.querySelectorAll('.star');
                
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('text-warning');
                    } else {
                        s.classList.remove('text-warning');
                    }
                });
                
                document.getElementById('rating').value = rating;
            });
        });

        // Filter reviews by sentiment
        document.getElementById('filterPositive').addEventListener('click', function() {
            filterReviews('positive');
        });

        document.getElementById('filterNegative').addEventListener('click', function() {
            filterReviews('negative');
        });

        document.getElementById('filterAll').addEventListener('click', function() {
            filterReviews('all');
        });

        function filterReviews(sentiment) {
            const reviews = document.querySelectorAll('.review-item');
            
            reviews.forEach(review => {
                if (sentiment === 'all' || review.getAttribute('data-sentiment') === sentiment) {
                    review.style.display = 'block';
                } else {
                    review.style.display = 'none';
                }
            });
        }
    });
    </script>
</body>
</html>