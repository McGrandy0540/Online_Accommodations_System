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

// Get all properties owned by this user
$properties_stmt = $pdo->prepare("SELECT COUNT(*) as property_count
                                FROM property p
                                JOIN property_owners po ON p.id = po.property_id
                                WHERE po.owner_id = ? AND p.deleted = 0");
$properties_stmt->execute([$owner_id]);
$property_count = $properties_stmt->fetch()['property_count'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $video_title = trim($_POST['video_title']);
        $video_description = trim($_POST['video_description'] ?? '');
        $video_type = $_POST['video_type'] ?? 'property_tour';
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        
        // Validate inputs
        if (empty($video_title)) {
            throw new Exception("Video title is required");
        }
        
        // Handle file upload
        $video_path = '';
        $thumbnail_path = '';
        $file_size = 0;
        
        if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/property-videos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_ext = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['mp4', 'webm', 'mov', 'avi', 'mkv'];
            
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Invalid file type. Only video files (MP4, WEBM, MOV, AVI, MKV) are allowed.");
            }
            
            // Check file size (max 100MB)
            if ($_FILES['video_file']['size'] > 100 * 1024 * 1024) {
                throw new Exception("File size too large. Maximum size is 100MB.");
            }
            
            $filename = 'video_' . $owner_id . '_' . time() . '.' . $file_ext;
            $destination = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $destination)) {
                throw new Exception("Failed to upload file");
            }
            
            $video_path = 'uploads/property-videos/' . $filename;
            $file_size = $_FILES['video_file']['size'];
        } else {
            throw new Exception("Please select a video file to upload");
        }
        
        // Handle thumbnail upload (optional)
        if (isset($_FILES['thumbnail_file']) && $_FILES['thumbnail_file']['error'] === UPLOAD_ERR_OK) {
            $thumbnail_dir = '../../uploads/property-videos/thumbnails/';
            if (!file_exists($thumbnail_dir)) {
                mkdir($thumbnail_dir, 0755, true);
            }
            
            $thumb_ext = strtolower(pathinfo($_FILES['thumbnail_file']['name'], PATHINFO_EXTENSION));
            $allowed_thumb_ext = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array($thumb_ext, $allowed_thumb_ext)) {
                $thumb_filename = 'thumb_' . $owner_id . '_' . time() . '.' . $thumb_ext;
                $thumb_destination = $thumbnail_dir . $thumb_filename;
                
                if (move_uploaded_file($_FILES['thumbnail_file']['tmp_name'], $thumb_destination)) {
                    $thumbnail_path = 'uploads/property-videos/thumbnails/' . $thumb_filename;
                }
            }
        }
        
        // Insert into database
        $insert_stmt = $pdo->prepare("INSERT INTO property_owner_videos 
                                    (owner_id, video_title, video_description, video_path, video_type, thumbnail_path, file_size, is_featured, status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        
        $insert_stmt->execute([
            $owner_id,
            $video_title,
            $video_description,
            $video_path,
            $video_type,
            $thumbnail_path ?: null,
            $file_size,
            $is_featured
        ]);
        
        $video_id = $pdo->lastInsertId();
        
        // Log activity
        $activity_stmt = $pdo->prepare("INSERT INTO activity_logs 
                                      (user_id, action, entity_type, entity_id, created_at)
                                      VALUES (?, ?, ?, ?, NOW())");
        $activity_stmt->execute([
            $owner_id,
            'upload_property_video',
            'property_video',
            $video_id
        ]);
        
        $_SESSION['success_message'] = "Video uploaded successfully! This video will be shown to students viewing all your properties.";
        header("Location: index.php");
        exit();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get existing videos
$videos_stmt = $pdo->prepare("SELECT * FROM property_owner_videos 
                            WHERE owner_id = ? 
                            ORDER BY is_featured DESC, created_at DESC");
$videos_stmt->execute([$owner_id]);
$existing_videos = $videos_stmt->fetchAll();

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
    <title>Upload Property Video | Landlords&Tenant</title>
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
            background-color: #f5f7fa;
            color: #343a40;
        }

        .header {
            background-color: var(--secondary-color);
            color: white;
            padding: 1rem 0;
            box-shadow: var(--box-shadow);
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 15px;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-header h1 {
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
            font-size: 2rem;
        }

        .page-header p {
            color: #6c757d;
            font-size: 1.1rem;
        }

        .info-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .info-card ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .info-card li {
            margin-bottom: 0.5rem;
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
            font-weight: 600;
            color: var(--secondary-color);
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .file-upload-area {
            border: 2px dashed #ddd;
            border-radius: var(--border-radius);
            padding: 3rem;
            text-align: center;
            background-color: #f9f9f9;
            transition: all 0.3s;
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }

        .file-upload-area.drag-over {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.1);
        }

        .file-upload-area i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .file-info {
            margin-top: 1rem;
            padding: 1rem;
            background-color: #e9ecef;
            border-radius: var(--border-radius);
            display: none;
        }

        .file-info.show {
            display: block;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border: none;
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
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

        .alert {
            border-radius: var(--border-radius);
            border: none;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .video-card {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }

        .video-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .video-thumbnail {
            position: relative;
            height: 200px;
            background: #000;
        }

        .video-thumbnail video,
        .video-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
        }

        .video-info {
            padding: 1rem;
            background: white;
        }

        .video-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .video-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="../dashboard.php" class="text-white text-decoration-none">
                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                </a>
                <h1 class="h4 mb-0">Upload Property Video</h1>
                <a href="index.php" class="text-white text-decoration-none">
                    Manage Videos <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <div class="page-header">
            <h1><i class="fas fa-video me-2"></i>Upload Property Virtual Tour</h1>
            <p>Upload a video that will be shown to students viewing all <?= $property_count ?> of your properties</p>
        </div>

        <!-- Info Card -->
        <div class="info-card">
            <h3><i class="fas fa-info-circle"></i> How It Works</h3>
            <ul>
                <li><strong>One Video, All Properties:</strong> Videos uploaded here will be displayed to students when they view ANY of your properties</li>
                <li><strong>Perfect for Landlords:</strong> If you manage multiple properties (like "Lord's Hostel" branches), upload one tour video</li>
                <li><strong>Showcase Your Brand:</strong> Give students a comprehensive view of your accommodation style and quality</li>
                <li><strong>Increase Bookings:</strong> Properties with video tours receive 3x more booking inquiries</li>
                <li><strong>File Requirements:</strong> MP4, WEBM, MOV (Max 100MB) | Recommended: 1080p, landscape orientation</li>
            </ul>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload New Video</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <label for="video_title" class="form-label">Video Title *</label>
                            <input type="text" class="form-control" id="video_title" name="video_title" 
                                   placeholder="e.g., Lord's Hostel - Full Property Tour" required>
                            <small class="text-muted">Give your video a descriptive title</small>
                        </div>
                        <div class="col-md-4">
                            <label for="video_type" class="form-label">Video Type *</label>
                            <select class="form-select" id="video_type" name="video_type" required>
                                <option value="property_tour">Property Tour</option>
                                <option value="room_tour">Room Tour</option>
                                <option value="amenities">Amenities</option>
                                <option value="surrounding">Surrounding Area</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="video_description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="video_description" name="video_description" rows="3" 
                                  placeholder="Provide additional details about the video..."></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Video File *</label>
                        <div class="file-upload-area" id="videoUploadArea">
                            <input type="file" class="d-none" id="video_file" name="video_file" 
                                   accept="video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska" required>
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h5>Click to upload or drag and drop</h5>
                            <p class="mb-0">MP4, WEBM, MOV, AVI, MKV (Max 100MB)</p>
                        </div>
                        <div class="file-info" id="videoFileInfo">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-file-video me-2"></i>
                                    <span id="videoFileName"></span>
                                </div>
                                <div>
                                    <span id="videoFileSize" class="badge bg-primary"></span>
                                    <button type="button" class="btn btn-sm btn-danger ms-2" id="removeVideo">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="progress mt-2" id="uploadProgress" style="display: none;">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Video Thumbnail (Optional)</label>
                        <div class="file-upload-area" id="thumbnailUploadArea">
                            <input type="file" class="d-none" id="thumbnail_file" name="thumbnail_file" 
                                   accept="image/jpeg,image/png,image/webp">
                            <i class="fas fa-image"></i>
                            <h6>Click to upload thumbnail image</h6>
                            <p class="mb-0 small">JPG, PNG, WEBP (Recommended: 1280x720)</p>
                        </div>
                        <div class="file-info" id="thumbnailFileInfo">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-image me-2"></i>
                                    <span id="thumbnailFileName"></span>
                                </div>
                                <button type="button" class="btn btn-sm btn-danger" id="removeThumbnail">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured">
                            <label class="form-check-label" for="is_featured">
                                <i class="fas fa-star text-warning me-1"></i> Mark as Featured Video
                            </label>
                            <small class="d-block text-muted">Featured videos appear first in the video gallery</small>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2">
                        <a href="manage_property_videos.php" class="btn btn-outline">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-upload me-2"></i>Upload Video
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Existing Videos Preview -->
        <?php if (!empty($existing_videos)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-video me-2"></i>Your Uploaded Videos (<?= count($existing_videos) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="video-grid">
                    <?php foreach (array_slice($existing_videos, 0, 3) as $video): ?>
                        <div class="video-card">
                            <div class="video-thumbnail">
                                <?php if ($video['thumbnail_path']): ?>
                                    <img src="../../<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Thumbnail">
                                <?php else: ?>
                                    <video src="../../<?= htmlspecialchars($video['video_path']) ?>"></video>
                                <?php endif; ?>
                                <?php if ($video['is_featured']): ?>
                                    <span class="video-badge"><i class="fas fa-star"></i> Featured</span>
                                <?php endif; ?>
                            </div>
                            <div class="video-info">
                                <div class="video-title"><?= htmlspecialchars($video['video_title']) ?></div>
                                <div class="video-meta">
                                    <i class="fas fa-eye me-1"></i> <?= $video['views_count'] ?> views
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-clock me-1"></i> <?= date('M j, Y', strtotime($video['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($existing_videos) > 3): ?>
                    <div class="text-center mt-3">
                        <a href="manage_property_videos.php" class="btn btn-outline">
                            View All <?= count($existing_videos) ?> Videos <i class="fas fa-arrow-right ms-2"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Video file upload handling
        const videoUploadArea = document.getElementById('videoUploadArea');
        const videoFileInput = document.getElementById('video_file');
        const videoFileInfo = document.getElementById('videoFileInfo');
        const videoFileName = document.getElementById('videoFileName');
        const videoFileSize = document.getElementById('videoFileSize');
        const removeVideoBtn = document.getElementById('removeVideo');

        videoUploadArea.addEventListener('click', () => videoFileInput.click());

        videoUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            videoUploadArea.classList.add('drag-over');
        });

        videoUploadArea.addEventListener('dragleave', () => {
            videoUploadArea.classList.remove('drag-over');
        });

        videoUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            videoUploadArea.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                videoFileInput.files = files;
                displayVideoInfo(files[0]);
            }
        });

        videoFileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                displayVideoInfo(e.target.files[0]);
            }
        });

        removeVideoBtn.addEventListener('click', () => {
            videoFileInput.value = '';
            videoFileInfo.classList.remove('show');
        });

        function displayVideoInfo(file) {
            videoFileName.textContent = file.name;
            videoFileSize.textContent = formatFileSize(file.size);
            videoFileInfo.classList.add('show');
        }

        // Thumbnail file upload handling
        const thumbnailUploadArea = document.getElementById('thumbnailUploadArea');
        const thumbnailFileInput = document.getElementById('thumbnail_file');
        const thumbnailFileInfo = document.getElementById('thumbnailFileInfo');
        const thumbnailFileName = document.getElementById('thumbnailFileName');
        const removeThumbnailBtn = document.getElementById('removeThumbnail');

        thumbnailUploadArea.addEventListener('click', () => thumbnailFileInput.click());

        thumbnailFileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                thumbnailFileName.textContent = e.target.files[0].name;
                thumbnailFileInfo.classList.add('show');
            }
        });

        removeThumbnailBtn.addEventListener('click', () => {
            thumbnailFileInput.value = '';
            thumbnailFileInfo.classList.remove('show');
        });

        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        // Form submission handling
        const uploadForm = document.getElementById('uploadForm');
        const submitBtn = document.getElementById('submitBtn');

        uploadForm.addEventListener('submit', function(e) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
        });
    </script>
</body>
</html>
