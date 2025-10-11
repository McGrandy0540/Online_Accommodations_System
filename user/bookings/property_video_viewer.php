<?php
// This component displays property owner's videos in the booking interface
// Include this file in create.php or details.php to show videos

if (!isset($property_id)) {
    return; // Exit if property_id is not set
}

// Get the property owner
$owner_stmt = $pdo->prepare("SELECT DISTINCT po.owner_id, u.username 
                            FROM property_owners po
                            JOIN users u ON po.owner_id = u.id
                            WHERE po.property_id = ?
                            LIMIT 1");
$owner_stmt->execute([$property_id]);
$property_owner = $owner_stmt->fetch();

if (!$property_owner) {
    return; // No owner found
}

// Get owner's videos
$videos_stmt = $pdo->prepare("SELECT * FROM property_owner_videos 
                            WHERE owner_id = ? AND status = 'active'
                            ORDER BY is_featured DESC, created_at DESC
                            LIMIT 5");
$videos_stmt->execute([$property_owner['owner_id']]);
$owner_videos = $videos_stmt->fetchAll();

if (empty($owner_videos)) {
    return; // No videos to display
}
?>

<!-- Property Virtual Tours Section -->
<div class="card property-tours-card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-video me-2"></i>Virtual Property Tours
        </h5>
        <small>Explore this property from <?= htmlspecialchars($property_owner['username']) ?></small>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Take a virtual tour!</strong> Watch these videos to see what the property looks like before you book.
        </div>
        
        <div class="video-tour-grid">
            <?php foreach ($owner_videos as $index => $video): ?>
                <div class="video-tour-item">
                    <div class="video-thumbnail" onclick="openVideoModal(<?= $index ?>, '<?= htmlspecialchars($video['video_path']) ?>', '<?= htmlspecialchars($video['video_title']) ?>', <?= $video['id'] ?>)">
                        <?php if ($video['thumbnail_path']): ?>
                            <img src="../../<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Video Thumbnail">
                        <?php else: ?>
                            <video src="../../<?= htmlspecialchars($video['video_path']) ?>" muted></video>
                        <?php endif; ?>
                        
                        <div class="video-play-overlay">
                            <div class="play-button">
                                <i class="fas fa-play"></i>
                            </div>
                        </div>
                        
                        <?php if ($video['is_featured']): ?>
                            <span class="featured-tag">
                                <i class="fas fa-star"></i> Featured
                            </span>
                        <?php endif; ?>
                        
                        <span class="video-type-tag">
                            <?= ucfirst(str_replace('_', ' ', $video['video_type'])) ?>
                        </span>
                    </div>
                    
                    <div class="video-tour-info">
                        <h6 class="video-tour-title"><?= htmlspecialchars($video['video_title']) ?></h6>
                        <?php if ($video['video_description']): ?>
                            <p class="video-tour-desc"><?= htmlspecialchars(substr($video['video_description'], 0, 80)) ?><?= strlen($video['video_description']) > 80 ? '...' : '' ?></p>
                        <?php endif; ?>
                        <small class="text-muted">
                            <i class="fas fa-eye me-1"></i><?= number_format($video['views_count']) ?> views
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Video Modal -->
<div class="modal fade" id="propertyVideoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="videoModalTitle">Virtual Tour</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <video id="propertyVideoPlayer" controls style="width: 100%; max-height: 70vh; background: #000;">
                    <source id="propertyVideoSource" src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="nextVideoBtn">
                    Next Video <i class="fas fa-forward ms-2"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.property-tours-card {
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.property-tours-card .card-header {
    background: linear-gradient(135deg, #3498db, #2980b9) !important;
    padding: 1.25rem;
}

.video-tour-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1.5rem;
}

.video-tour-item {
    cursor: pointer;
    transition: transform 0.3s;
}

.video-tour-item:hover {
    transform: translateY(-5px);
}

.video-thumbnail {
    position: relative;
    height: 180px;
    border-radius: 8px;
    overflow: hidden;
    background: #000;
}

.video-thumbnail video,
.video-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-play-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.video-tour-item:hover .video-play-overlay {
    opacity: 1;
}

.play-button {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #3498db;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    transition: transform 0.3s;
}

.play-button:hover {
    transform: scale(1.1);
}

.featured-tag {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #ffc107;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.video-type-tag {
    position: absolute;
    top: 10px;
    left: 10px;
    background: #3498db;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.video-tour-info {
    padding: 1rem 0.5rem;
}

.video-tour-title {
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #2c3e50;
    font-size: 1rem;
}

.video-tour-desc {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

@media (max-width: 768px) {
    .video-tour-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Video player management
let currentVideoIndex = 0;
let videoPlaylist = <?= json_encode(array_map(function($v) {
    return [
        'path' => $v['video_path'],
        'title' => $v['video_title'],
        'id' => $v['id']
    ];
}, $owner_videos)) ?>;

const videoModal = new bootstrap.Modal(document.getElementById('propertyVideoModal'));
const videoPlayer = document.getElementById('propertyVideoPlayer');
const videoSource = document.getElementById('propertyVideoSource');
const videoModalTitle = document.getElementById('videoModalTitle');
const nextVideoBtn = document.getElementById('nextVideoBtn');

function openVideoModal(index, videoPath, title, videoId) {
    currentVideoIndex = index;
    videoSource.src = '../../' + videoPath;
    videoModalTitle.textContent = title;
    videoPlayer.load();
    videoModal.show();
    videoPlayer.play();
    
    // Track video view
    trackVideoView(videoId);
    
    // Update next button visibility
    if (currentVideoIndex >= videoPlaylist.length - 1) {
        nextVideoBtn.style.display = 'none';
    } else {
        nextVideoBtn.style.display = 'inline-block';
    }
}

function playNextVideo() {
    if (currentVideoIndex < videoPlaylist.length - 1) {
        currentVideoIndex++;
        const nextVideo = videoPlaylist[currentVideoIndex];
        openVideoModal(currentVideoIndex, nextVideo.path, nextVideo.title, nextVideo.id);
    }
}

nextVideoBtn.addEventListener('click', playNextVideo);

// Stop video when modal is closed
document.getElementById('propertyVideoModal').addEventListener('hidden.bs.modal', function () {
    videoPlayer.pause();
    videoPlayer.currentTime = 0;
});

// Track video views
function trackVideoView(videoId) {
    fetch('track_video_view.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ video_id: videoId })
    }).catch(err => console.log('Error tracking view:', err));
}
</script>
