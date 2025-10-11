<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

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
    header('Location: ../../auth/login.php');
    exit();
}

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $video_id = $_GET['delete'];
    
    // Verify ownership and get video details
    $verify_stmt = $pdo->prepare("SELECT video_path, thumbnail_path FROM property_owner_videos WHERE id = ? AND owner_id = ?");
    $verify_stmt->execute([$video_id, $owner_id]);
    $video = $verify_stmt->fetch();
    
    if ($video) {
        try {
            // Delete physical files
            if (!empty($video['video_path']) && file_exists('../../' . $video['video_path'])) {
                unlink('../../' . $video['video_path']);
            }
            if (!empty($video['thumbnail_path']) && file_exists('../../' . $video['thumbnail_path'])) {
                unlink('../../' . $video['thumbnail_path']);
            }
            
            // Delete from database
            $delete_stmt = $pdo->prepare("DELETE FROM property_owner_videos WHERE id = ? AND owner_id = ?");
            $delete_stmt->execute([$video_id, $owner_id]);
            
            $_SESSION['success_message'] = "Video deleted successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error deleting video: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Video not found or you don't have permission to delete it.";
    }
    
    header("Location: index.php");
    exit();
}

// Handle toggle featured action
if (isset($_GET['toggle_featured']) && is_numeric($_GET['toggle_featured'])) {
    $video_id = $_GET['toggle_featured'];
    
    // Verify ownership
    $verify_stmt = $pdo->prepare("SELECT id FROM property_owner_videos WHERE id = ? AND owner_id = ?");
    $verify_stmt->execute([$video_id, $owner_id]);
    
    if ($verify_stmt->fetch()) {
        $toggle_stmt = $pdo->prepare("UPDATE property_owner_videos SET is_featured = NOT is_featured WHERE id = ? AND owner_id = ?");
        $toggle_stmt->execute([$video_id, $owner_id]);
        
        $_SESSION['success_message'] = "Video featured status updated!";
    } else {
        $_SESSION['error_message'] = "Video not found or you don't have permission to modify it.";
    }
    
    header("Location: index.php");
    exit();
}

// Handle status toggle
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $video_id = $_GET['toggle_status'];
    
    $verify_stmt = $pdo->prepare("SELECT id, status FROM property_owner_videos WHERE id = ? AND owner_id = ?");
    $verify_stmt->execute([$video_id, $owner_id]);
    $video = $verify_stmt->fetch();
    
    if ($video) {
        $new_status = $video['status'] === 'active' ? 'inactive' : 'active';
        $status_stmt = $pdo->prepare("UPDATE property_owner_videos SET status = ? WHERE id = ? AND owner_id = ?");
        $status_stmt->execute([$new_status, $video_id, $owner_id]);
        
        $_SESSION['success_message'] = "Video status updated to " . $new_status . "!";
    } else {
        $_SESSION['error_message'] = "Video not found or you don't have permission to modify it.";
    }
    
    header("Location: index.php");
    exit();
}

// Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_video'])) {
    $video_id = $_POST['video_id'];
    $video_title = trim($_POST['video_title']);
    $video_description = trim($_POST['video_description'] ?? '');
    $video_type = $_POST['video_type'] ?? 'property_tour';
    
    // Verify ownership
    $verify_stmt = $pdo->prepare("SELECT id FROM property_owner_videos WHERE id = ? AND owner_id = ?");
    $verify_stmt->execute([$video_id, $owner_id]);
    
    if ($verify_stmt->fetch()) {
        try {
            $update_stmt = $pdo->prepare("UPDATE property_owner_videos SET video_title = ?, video_description = ?, video_type = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND owner_id = ?");
            $update_stmt->execute([$video_title, $video_description, $video_type, $video_id, $owner_id]);
            
            $_SESSION['success_message'] = "Video details updated successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating video: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Video not found or you don't have permission to edit it.";
    }
    
    header("Location: index.php");
    exit();
}

// Get current page and build base URL for pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM property_owner_videos WHERE owner_id = ?");
$count_stmt->execute([$owner_id]);
$total_videos = $count_stmt->fetch()['total'];
$total_pages = ceil($total_videos / $limit);

// Get videos with pagination
$videos_stmt = $pdo->prepare("SELECT * FROM property_owner_videos 
                            WHERE owner_id = :owner_id
                            ORDER BY is_featured DESC, created_at DESC 
                            LIMIT :limit OFFSET :offset");
$videos_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$videos_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$videos_stmt->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
$videos_stmt->execute();
$videos = $videos_stmt->fetchAll();

// Get statistics
$stats_stmt = $pdo->prepare("SELECT 
                            COUNT(*) as total_videos,
                            SUM(views_count) as total_views,
                            SUM(file_size) as total_size,
                            COUNT(CASE WHEN is_featured = 1 THEN 1 END) as featured_count,
                            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count
                            FROM property_owner_videos 
                            WHERE owner_id = ?");
$stats_stmt->execute([$owner_id]);
$stats = $stats_stmt->fetch();

// Get property count
$properties_stmt = $pdo->prepare("SELECT COUNT(*) as property_count
                                FROM property p
                                JOIN property_owners po ON p.id = po.property_id
                                WHERE po.owner_id = ? AND p.deleted = 0");
$properties_stmt->execute([$owner_id]);
$property_count = $properties_stmt->fetch()['property_count'];

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

function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function formatDuration($seconds) {
    if ($seconds === 0 || $seconds === null) return 'N/A';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
    } else {
        return sprintf("%d:%02d", $minutes, $seconds);
    }
}

$profile_pic_path = getProfilePicturePath($owner['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Property Videos | Landlords&Tenant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-hover: #2980b9;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #343a40;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, var(--secondary-color), #1a252f);
            color: white;
            padding: 1rem 0;
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 15px;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
        }

        .stat-icon.success {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            color: white;
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, var(--warning-color), #d39e00);
            color: white;
        }

        .stat-icon.info {
            background: linear-gradient(135deg, var(--info-color), #138496);
            color: white;
        }

        .stat-info h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            color: var(--secondary-color);
        }

        .stat-info p {
            margin: 0;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            background-color: white;
            margin-bottom: 2rem;
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
            padding: 1.5rem;
        }

        @media (max-width: 768px) {
            .video-grid {
                grid-template-columns: 1fr;
                padding: 1rem;
            }
        }

        .video-card {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
            background: white;
            border: 1px solid #e9ecef;
        }

        .video-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .video-thumbnail {
            position: relative;
            height: 220px;
            background: #000;
            overflow: hidden;
        }

        .video-thumbnail video,
        .video-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .video-card:hover .video-overlay {
            opacity: 1;
        }

        .play-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .play-btn:hover {
            transform: scale(1.1);
        }

        .video-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .featured-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--warning-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-badge {
            position: absolute;
            bottom: 10px;
            left: 10px;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: var(--success-color);
            color: white;
        }

        .status-inactive {
            background: #6c757d;
            color: white;
        }

        .video-info {
            padding: 1.25rem;
        }

        .video-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
            font-size: 1.1rem;
            line-height: 1.4;
        }

        .video-description {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.5;
        }

        .video-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .video-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .video-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            flex: 1;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
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

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background-color: #e0a800;
            color: white;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
            color: white;
        }

        .btn-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
            color: white;
        }

        .btn-info {
            background-color: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
            color: white;
        }

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            flex: 0 1 auto;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 2rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .alert {
            border-radius: var(--border-radius);
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

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
            border: 1px solid #dee2e6;
            padding: 0.5rem 0.75rem;
        }

        .page-link:hover {
            color: var(--primary-hover);
            background-color: #e9ecef;
            border-color: #dee2e6;
        }

        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            border-bottom: none;
        }

        .modal-title {
            font-weight: 600;
        }

        .btn-close {
            filter: invert(1);
        }

        .search-box {
            max-width: 300px;
        }

        .video-type-icon {
            width: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="../dashboard.php" class="text-white text-decoration-none d-flex align-items-center">
                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                </a>
                <h1 class="h4 ms-5">Manage Property Videos</h1>
                <a href="upload_property_video.php" class="btn btn-outline-light">
                    <i class="fas fa-plus ms-2"></i>Upload New Video
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-2"><i class="fas fa-film me-2"></i>Your Property Videos</h2>
                    <p class="text-muted mb-0">Manage and organize your property virtual tours and video content</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="upload_property_video.php" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Upload New Video
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-video"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['total_videos'] ?? 0 ?></h3>
                    <p>Total Videos</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?= number_format($stats['total_views'] ?? 0) ?></h3>
                    <p>Total Views</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $stats['featured_count'] ?? 0 ?></h3>
                    <p>Featured Videos</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $property_count ?></h3>
                    <p>Your Properties</p>
                </div>
            </div>
        </div>

        <!-- Videos List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-film me-2"></i>Your Property Virtual Tours</h5>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-primary"><?= $total_videos ?> videos</span>
                    <?php if ($total_videos > 0): ?>
                        <span class="badge bg-success"><?= $stats['active_count'] ?? 0 ?> active</span>
                        <span class="badge bg-warning"><?= $stats['featured_count'] ?? 0 ?> featured</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($videos)): ?>
                <div class="empty-state">
                    <i class="fas fa-video-slash"></i>
                    <h3>No Videos Uploaded Yet</h3>
                    <p>Upload your first property tour video to showcase your accommodations to potential students.</p>
                    <a href="upload_property_video.php" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Upload Your First Video
                    </a>
                </div>
            <?php else: ?>
                <div class="video-grid">
                    <?php foreach ($videos as $video): ?>
                        <div class="video-card">
                            <div class="video-thumbnail">
                                <?php if ($video['thumbnail_path']): ?>
                                    <img src="../../<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Thumbnail for <?= htmlspecialchars($video['video_title']) ?>">
                                <?php else: ?>
                                    <video src="../../<?= htmlspecialchars($video['video_path']) ?>" muted></video>
                                <?php endif; ?>
                                
                                <div class="video-overlay">
                                    <div class="play-btn" onclick="playVideo('<?= htmlspecialchars($video['video_path']) ?>')">
                                        <i class="fas fa-play"></i>
                                    </div>
                                </div>
                                
                                <span class="video-badge">
                                    <i class="fas fa-tag me-1"></i>
                                    <?= ucfirst(str_replace('_', ' ', $video['video_type'])) ?>
                                </span>
                                
                                <?php if ($video['is_featured']): ?>
                                    <span class="featured-badge">
                                        <i class="fas fa-star"></i> Featured
                                    </span>
                                <?php endif; ?>

                                <span class="status-badge <?= $video['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                    <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                                    <?= ucfirst($video['status']) ?>
                                </span>
                            </div>
                            
                            <div class="video-info">
                                <div class="video-title"><?= htmlspecialchars($video['video_title']) ?></div>
                                
                                <?php if ($video['video_description']): ?>
                                    <div class="video-description"><?= htmlspecialchars($video['video_description']) ?></div>
                                <?php endif; ?>
                                
                                <div class="video-meta">
                                    <div class="video-meta-item">
                                        <i class="fas fa-eye"></i>
                                        <span><?= number_format($video['views_count']) ?> views</span>
                                    </div>
                                    <div class="video-meta-item">
                                        <i class="fas fa-file"></i>
                                        <span><?= formatFileSize($video['file_size']) ?></span>
                                    </div>
                                    <?php if ($video['duration'] > 0): ?>
                                        <div class="video-meta-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?= formatDuration($video['duration']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="video-meta">
                                    <div class="video-meta-item">
                                        <i class="fas fa-calendar"></i>
                                        <span><?= date('M j, Y', strtotime($video['created_at'])) ?></span>
                                    </div>
                                    <div class="video-meta-item">
                                        <i class="fas fa-sync-alt"></i>
                                        <span><?= date('M j, Y', strtotime($video['updated_at'])) ?></span>
                                    </div>
                                </div>
                                
                                <div class="video-actions">
                                    <button class="btn btn-outline btn-sm" onclick="editVideo(<?= $video['id'] ?>, '<?= htmlspecialchars($video['video_title']) ?>', '<?= htmlspecialchars($video['video_description']) ?>', '<?= $video['video_type'] ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?toggle_featured=<?= $video['id'] ?><?= $page > 1 ? '&page=' . $page : '' ?>" class="btn btn-warning btn-sm" 
                                       title="<?= $video['is_featured'] ? 'Remove from featured' : 'Mark as featured' ?>">
                                        <i class="fas fa-star"></i> <?= $video['is_featured'] ? 'Unfeature' : 'Feature' ?>
                                    </a>
                                    <a href="?toggle_status=<?= $video['id'] ?><?= $page > 1 ? '&page=' . $page : '' ?>" class="btn btn-<?= $video['status'] === 'active' ? 'secondary' : 'success' ?> btn-sm">
                                        <i class="fas fa-power-off"></i> <?= $video['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </a>
                                    <button class="btn btn-danger btn-sm" 
                                       onclick="confirmDelete(<?= $video['id'] ?>, '<?= htmlspecialchars(addslashes($video['video_title'])) ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Video pagination" class="my-4">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
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
    </main>

    <!-- Video Player Modal -->
    <div class="modal fade" id="videoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Video Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <video id="modalVideo" controls style="width: 100%; height: auto;">
                        <source id="videoSource" src="" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Video Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Video Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="video_id" id="editVideoId">
                        <input type="hidden" name="edit_video" value="1">
                        
                        <div class="mb-3">
                            <label for="editVideoTitle" class="form-label">Video Title *</label>
                            <input type="text" class="form-control" id="editVideoTitle" name="video_title" required maxlength="255">
                        </div>
                        
                        <div class="mb-3">
                            <label for="editVideoDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editVideoDescription" name="video_description" rows="4" maxlength="1000"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editVideoType" class="form-label">Video Type</label>
                            <select class="form-select" id="editVideoType" name="video_type">
                                <option value="property_tour">🏠 Full Property Tour</option>
                                <option value="room_tour">🛏️ Room Tour</option>
                                <option value="amenities">🏊 Amenities Showcase</option>
                                <option value="surrounding">📍 Surrounding Area</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const videoModal = new bootstrap.Modal(document.getElementById('videoModal'));
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));
        const modalVideo = document.getElementById('modalVideo');
        const videoSource = document.getElementById('videoSource');

        function playVideo(videoPath) {
            videoSource.src = '../../' + videoPath;
            modalVideo.load();
            videoModal.show();
            modalVideo.play();
        }

        // Stop video when modal is closed
        document.getElementById('videoModal').addEventListener('hidden.bs.modal', function () {
            modalVideo.pause();
            modalVideo.currentTime = 0;
        });

        function editVideo(videoId, title, description, type) {
            document.getElementById('editVideoId').value = videoId;
            document.getElementById('editVideoTitle').value = title;
            document.getElementById('editVideoDescription').value = description;
            document.getElementById('editVideoType').value = type;
            editModal.show();
        }

        function confirmDelete(videoId, videoTitle) {
            if (confirm('Are you sure you want to delete the video "' + videoTitle + '"? This action cannot be undone.')) {
                window.location.href = '?delete=' + videoId + '<?= $page > 1 ? '&page=' . $page : '' ?>';
            }
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
    </script>
</body>
</html>