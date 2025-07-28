<?php
session_start();
require_once __DIR__ . '../../../config/database.php';

$pdo = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['status'] ?? null;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Get conversation ID from query parameter
$conversation_id = $_GET['conversation_id'] ?? null;

if (!$conversation_id) {
    header("Location: index.php");
    exit();
}

// Verify user has access to this conversation
$stmt = $pdo->prepare("SELECT c.*, 
                             u1.username as student_name, 
                             u2.username as owner_name,
                             p.property_name,
                             p.id as property_id
                      FROM chat_conversations c
                      JOIN users u1 ON c.student_id = u1.id
                      JOIN users u2 ON c.owner_id = u2.id
                      LEFT JOIN property p ON c.property_id = p.id
                      WHERE c.id = ? AND (c.student_id = ? OR c.owner_id = ?)");
$stmt->execute([$conversation_id, $user_id, $user_id]);
$conversation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conversation) {
    header("Location: index.php");
    exit();
}

// Get all messages for this conversation
$stmt = $pdo->prepare("SELECT m.*, u.username, u.profile_picture 
                      FROM chat_messages m
                      JOIN users u ON m.sender_id = u.id
                      WHERE m.conversation_id = ?
                      ORDER BY m.created_at DESC
                      LIMIT 100");
$stmt->execute([$conversation_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get other user info
$other_user_id = ($user_id == $conversation['student_id']) ? 
                $conversation['owner_id'] : $conversation['student_id'];

$stmt = $pdo->prepare("SELECT id, username, profile_picture, status FROM users WHERE id = ?");
$stmt->execute([$other_user_id]);
$other_user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat History | UniHomes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* Main content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            flex: 1;
            padding: 2rem;
            transition: all var(--transition-speed);
        }

        /* Message styling */
        .message {
            max-width: 70%;
            margin-bottom: 15px;
            position: relative;
            word-wrap: break-word;
        }

        .message-sent {
            align-self: flex-end;
            background-color: #dcf8c6;
            border-radius: 15px 15px 0 15px;
            padding: 10px 15px;
            margin-left: 30%;
        }

        .message-received {
            align-self: flex-start;
            background-color: white;
            border-radius: 15px 15px 15px 0;
            padding: 10px 15px;
            margin-right: 30%;
            box-shadow: var(--card-shadow);
        }

        /* Message info */
        .message-info {
            font-size: 0.8em;
            color: #666;
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
        }

        /* User avatar */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            background-size: cover;
            background-position: center;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .message {
                max-width: 85%;
            }
            
            .message-sent {
                margin-left: 15%;
            }
            
            .message-received {
                margin-right: 15%;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>UniHomes</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="/property_owner/dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="/property_owner/properties/index.php">
                <i class="fas fa-building"></i>
                <span>Properties</span>
            </a>
            <a href="/property_owner/bookings/index.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="/property_owner/payments/index.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
            <a href="/property_owner/reviews.php">
                <i class="fas fa-star"></i>
                <span>Reviews</span>
            </a>
            <a href="/property_owner/chat/index.php" class="active">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="/property_owner/settings.php">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Top Navigation Bar -->
    <nav class="top-nav" id="topNav">
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <h5 class="mb-0 d-none d-md-block"><i class="fas fa-history me-2"></i>Chat History</h5>
        
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
                    <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>
                    <i class="fas fa-comments me-2"></i>
                    Chat History with <?= htmlspecialchars($other_user['username']) ?>
                    <?php if ($conversation['property_name']): ?>
                        <small class="text-muted">about <?= htmlspecialchars($conversation['property_name']) ?></small>
                    <?php endif; ?>
                </h4>
                <a href="index.php?conversation_id=<?= $conversation_id ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Chat
                </a>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <?php if (empty($messages)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-comment-slash fa-3x text-muted mb-3"></i>
                            <h5>No messages found in this conversation</h5>
                            <p class="text-muted">Start the conversation by sending a message</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" id="exportChat">
                                    <i class="fas fa-download me-1"></i> Export Chat
                                </button>
                            </div>
                            <div>
                                <small class="text-muted">
                                    Showing last <?= count($messages) ?> messages
                                </small>
                            </div>
                        </div>
                        
                        <div class="chat-history">
                            <?php foreach ($messages as $message): ?>
                                <div class="d-flex mb-3 <?= $message['sender_id'] == $user_id ? 'justify-content-end' : 'justify-content-start' ?>">
                                    <?php if ($message['sender_id'] != $user_id): ?>
                                        <div class="user-avatar me-2" style="background-image: url('<?= $message['profile_picture'] ?? '' ?>')">
                                            <?= empty($message['profile_picture']) ? strtoupper(substr($message['username'], 0, 1)) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="message <?= $message['sender_id'] == $user_id ? 'message-sent' : 'message-received' ?>">
                                        <?= nl2br(htmlspecialchars($message['message'])) ?>
                                        <div class="message-info">
                                            <span>
                                                <?= $message['sender_id'] == $user_id ? 'You' : htmlspecialchars($message['username']) ?>
                                            </span>
                                            <span>
                                                <?= date('M j, Y H:i', strtotime($message['created_at'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($message['sender_id'] == $user_id): ?>
                                        <div class="user-avatar ms-2" style="background-image: url('<?= $_SESSION['profile_picture'] ?? '' ?>')">
                                            <?= empty($_SESSION['profile_picture']) ? strtoupper(substr($_SESSION['username'], 0, 1)) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('sidebar-open');
            });
        }

        // Export chat functionality
        const exportChat = document.getElementById('exportChat');
        if (exportChat) {
            exportChat.addEventListener('click', function() {
                // In a real implementation, this would make an AJAX call to generate a PDF or text file
                alert('Export functionality would generate a downloadable file with the chat history');
            });
        }
    });
    </script>
</body>
</html>