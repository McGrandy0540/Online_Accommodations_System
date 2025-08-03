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
}// Get all conversations for the current user
$query = "SELECT c.*, 
                 u1.username as student_name, 
                 u2.username as owner_name,
                 u3.username as admin_name,
                 p.property_name,
                 p.id as property_id,
                 c.conversation_type,
                 (SELECT COUNT(*) FROM chat_messages m 
                  WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_id != ?) as unread_count
          FROM chat_conversations c
          LEFT JOIN users u1 ON c.student_id = u1.id
          LEFT JOIN users u2 ON c.owner_id = u2.id
          LEFT JOIN users u3 ON c.admin_id = u3.id
          LEFT JOIN property p ON c.property_id = p.id
          WHERE (c.student_id = ? OR c.owner_id = ? OR c.admin_id = ?)
          ORDER BY c.updated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current conversation if specified
$current_conversation = null;
$messages = [];
$other_user = null;

if (isset($_GET['conversation_id'])) {
    $conversation_id = $_GET['conversation_id'];// Verify user has access to this conversation
$stmt = $pdo->prepare("SELECT * FROM chat_conversations 
                      WHERE id = ? AND (student_id = ? OR owner_id = ? OR admin_id = ?)");
$stmt->execute([$conversation_id, $user_id, $user_id, $user_id]);
$current_conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($current_conversation) {
        // Get messages for this conversation
        $stmt = $pdo->prepare("SELECT m.*, u.username, u.profile_picture 
                              FROM chat_messages m
                              JOIN users u ON m.sender_id = u.id
                              WHERE m.conversation_id = ?
                              ORDER BY m.created_at ASC");
        $stmt->execute([$conversation_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark messages as read
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 
                      WHERE conversation_id = ? AND sender_id != ? AND is_read = 0")
           ->execute([$conversation_id, $user_id]);
        
        // Get other user info based on conversation type
        if ($current_conversation['conversation_type'] === 'owner_admin') {
            // For owner-admin conversations
            $other_user_id = ($user_id == $current_conversation['owner_id']) ? 
                            $current_conversation['admin_id'] : $current_conversation['owner_id'];
        } else {
            // For student-owner conversations
            $other_user_id = ($user_id == $current_conversation['student_id']) ? 
                            $current_conversation['owner_id'] : $current_conversation['student_id'];
        }
        
        $stmt = $pdo->prepare("SELECT id, username, profile_picture, status FROM users WHERE id = ?");
        $stmt->execute([$other_user_id]);
        $other_user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Get potential new conversation partners based on user type
$potential_partners = [];
if ($user_type === 'student') {
    // Students can chat with property owners they've booked with
    $query = "SELECT DISTINCT u.id, u.username, u.profile_picture, p.id as property_id, p.property_name
              FROM bookings b
              JOIN property p ON b.property_id = p.id
              JOIN users u ON p.owner_id = u.id
              WHERE b.user_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM chat_conversations c 
                  WHERE c.student_id = ? AND c.owner_id = u.id
              )";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id, $user_id]);
    $potential_partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_type === 'property_owner') {
    // Owners can chat with students who've booked their properties
    $query = "SELECT DISTINCT u.id, u.username, u.profile_picture, p.id as property_id, p.property_name, 'student' as user_type
              FROM bookings b
              JOIN users u ON b.user_id = u.id
              JOIN property p ON b.property_id = p.id
              WHERE p.owner_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM chat_conversations c 
                  WHERE c.owner_id = ? AND c.student_id = u.id
              )
              UNION
              SELECT DISTINCT u.id, u.username, u.profile_picture, NULL as property_id, 'Admin Support' as property_name, 'admin' as user_type
              FROM users u
              WHERE u.status = 'admin'
              AND NOT EXISTS (
                  SELECT 1 FROM chat_conversations c 
                  WHERE c.owner_id = ? AND c.admin_id = u.id
              )";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id, $user_id, $user_id]);
    $potential_partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat | Landloards&Tenant</title>
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
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar styles */
        .sidebar {
            background: var(--secondary-color);
            color: white;
            width: var(--sidebar-width);
            min-height: 100vh;
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
            transition: all var(--transition-speed);
        }

        .sidebar-header {
            padding: 1rem 1.5rem;
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

        /* Top navigation */
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
            z-index: 999;
            transition: all var(--transition-speed);
            box-shadow: var(--box-shadow);
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

        /* Chat container */
        .chat-container {
            display: flex;
            height: calc(100vh - var(--header-height));
            margin-top: var(--header-height);
            margin-left: var(--sidebar-width);
            transition: all var(--transition-speed);
        }

        /* Conversation list */
        .conversation-list {
            width: 350px;
            border-right: 1px solid #e0e0e0;
            background-color: white;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        /* Chat area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background-color: #f5f5f5;
        }

        /* Chat header */
        .chat-header {
            padding: 1rem;
            background-color: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
        }

        /* Messages container */
        .messages-container {
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            background-color: #e5ddd5;
            background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4AkEEjIZJp1M4QAAAB1pVFh0Q29tbWVudAAAAAAAQ3JlYXRlZCB3aXRoIEdJTVBkLmUHAAAAJklEQVQ4y2NgGAWjYBSMglEwCkbBKBgFQw4wMjAwMP7//x9VwQAA5V0Hn2T5X5IAAAAASUVORK5CYII=');
            display: flex;
            flex-direction: column;
        }

        /* Message styling */
        .message {
            max-width: 70%;
            margin-bottom: 1rem;
            position: relative;
            word-wrap: break-word;
        }

        .message-sent {
            align-self: flex-end;
            background-color: #dcf8c6;
            border-radius: 15px 15px 0 15px;
            padding: 0.75rem 1rem;
        }

        .message-received {
            align-self: flex-start;
            background-color: white;
            border-radius: 15px 15px 15px 0;
            padding: 0.75rem 1rem;
            box-shadow: var(--card-shadow);
        }

        /* Message info */
        .message-info {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.5rem;
            display: flex;
            justify-content: space-between;
        }

        /* Input area */
        .input-area {
            padding: 1rem;
            background-color: white;
            border-top: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
        }

        /* Conversation item */
        .conversation-item {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }

        .conversation-item:hover {
            background-color: #f5f5f5;
        }

        .conversation-item.active {
            background-color: #e3f2fd;
        }

        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 1rem;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            background-size: cover;
            background-position: center;
        }

        .conversation-details {
            flex: 1;
            min-width: 0;
        }

        .conversation-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-preview {
            color: #666;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-time {
            font-size: 0.75rem;
            color: #999;
        }

        .unread-badge {
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }

        /* New conversation modal */
        .new-conversation-item {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }

        .new-conversation-item:hover {
            background-color: #f5f5f5;
        }

        /* Typing indicator */
        .typing-indicator {
            display: flex;
            padding: 0.75rem 1rem;
            background-color: white;
            border-radius: 15px 15px 15px 0;
            margin-bottom: 1rem;
            align-self: flex-start;
            box-shadow: var(--card-shadow);
        }

        .typing-indicator span {
            height: 8px;
            width: 8px;
            background-color: #666;
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: bounce 1.5s infinite ease-in-out;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes bounce {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-5px);
            }
        }

        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ccc;
        }

        /* Mobile menu toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            margin-right: 1rem;
            cursor: pointer;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .conversation-list {
                width: 300px;
            }
            
            .top-nav {
                left: var(--sidebar-collapsed-width);
            }
            
            .chat-container {
                margin-left: var(--sidebar-collapsed-width);
            }
            
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
        }

        @media (max-width: 768px) {
            .chat-container {
                margin-left: 0;
            }
            
            .top-nav {
                left: 0;
            }
            
            .conversation-list {
                position: fixed;
                left: 0;
                top: var(--header-height);
                bottom: 0;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                width: 280px;
            }
            
            .conversation-list.open {
                transform: translateX(0);
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .back-to-conversations {
                display: block !important;
            }
            
            .message {
                max-width: 85%;
            }
        }

        @media (max-width: 576px) {
            .top-nav {
                padding: 0 1rem;
            }
            
            .user-dropdown span {
                display: none;
            }
            
            .chat-header {
                flex-wrap: wrap;
                padding: 0.75rem;
            }
            
            .chat-header > div {
                margin-bottom: 0.5rem;
            }
            
            .message {
                max-width: 90%;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-home"></i> <span>Landloards&Tenant</span></h4>
        </div>
        <div class="sidebar-menu">
            <a href="../dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="../poperty_dashboard.php">
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
            <a href="../reviews/index.php">
                <i class="fas fa-star"></i>
                <span>Reviews</span>
            </a>
            <a href="../chat/index.php" class="active">
                <i class="fas fa-comments"></i>
                <span>Live Chat</span>
            </a>
            <a href="../settings.php">
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
        
        <button class="btn btn-sm btn-outline-light back-to-conversations d-none" id="backToConversations">
            <i class="fas fa-arrow-left me-1"></i> Conversations
        </button>
        
        <h5 class="mb-0 d-none d-md-block flex-grow-1 text-center">
            <i class="fas fa-comments me-2"></i>Live Chat
        </h5>
        
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
                   
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Chat Container -->
    <div class="chat-container">
        <!-- Conversation List -->
        <div class="conversation-list" id="conversationList">
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <h5 class="mb-0">Conversations</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newConversationModal">
                    <i class="fas fa-plus"></i> New
                </button>
            </div>
            
            <?php if (empty($conversations)): ?>
                <div class="empty-state">
                    <i class="fas fa-comment-slash"></i>
                    <h5>No conversations yet</h5>
                    <p>Start a new conversation by clicking the button above</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    // Determine the other user's name and avatar based on conversation type
                    if ($conv['conversation_type'] === 'owner_admin') {
                        $other_name = ($user_id == $conv['owner_id']) ? $conv['admin_name'] : $conv['owner_name'];
                        $conversation_type_label = ($user_id == $conv['owner_id']) ? 'Admin Support' : 'Property Owner';
                    } else {
                        $other_name = ($user_id == $conv['student_id']) ? $conv['owner_name'] : $conv['student_name'];
                        $conversation_type_label = ($user_id == $conv['student_id']) ? 'Property Owner' : 'Student';
                    }
                    ?>
                    <a href="?conversation_id=<?= $conv['id'] ?>" class="conversation-item <?= isset($current_conversation) && $current_conversation['id'] == $conv['id'] ? 'active' : '' ?>">
                        <div class="conversation-avatar" style="background-image: url('<?= $conv['profile_picture'] ?? '' ?>')">
                            <?= strtoupper(substr($other_name, 0, 1)) ?>
                        </div>
                        <div class="conversation-details">
                            <div class="d-flex justify-content-between">
                                <div class="conversation-title">
                                    <?= htmlspecialchars($other_name) ?>
                                </div>
                                <div class="conversation-time">
                                    <?= date('H:i', strtotime($conv['updated_at'])) ?>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <div class="conversation-preview">
                                    <?php if ($conv['conversation_type'] === 'owner_admin'): ?>
                                        <small class="text-muted"><i class="fas fa-shield-alt"></i> <?= $conversation_type_label ?></small>
                                    <?php elseif ($conv['property_name']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($conv['property_name']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">General inquiry</small>
                                    <?php endif; ?>
                                </div>
                                <?php if ($conv['unread_count'] > 0): ?>
                                    <div class="unread-badge"><?= $conv['unread_count'] ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Chat Area -->
        <div class="chat-area" id="chatArea">
            <?php if (isset($current_conversation) && $current_conversation): ?>
                <!-- Chat Header -->
                <div class="chat-header">
                    <button class="btn btn-sm btn-outline-secondary me-2 d-md-none" id="toggleConversationList">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="user-avatar me-3" style="background-image: url('<?= $other_user['profile_picture'] ?? '' ?>')">
                        <?= empty($other_user['profile_picture']) ? strtoupper(substr($other_user['username'], 0, 1)) : '' ?>
                    </div>
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($other_user['username']) ?></h6>
                        <small class="text-muted">
                            <?php if ($other_user['status'] === 'admin'): ?>
                                <i class="fas fa-shield-alt"></i> Administrator
                            <?php elseif ($other_user['status'] === 'property_owner'): ?>
                                <i class="fas fa-home"></i> Property Owner
                            <?php else: ?>
                                <i class="fas fa-user-graduate"></i> Student
                            <?php endif; ?>
                            <?php if ($current_conversation['conversation_type'] === 'owner_admin'): ?>
                                | <span class="text-primary">Admin Support</span>
                            <?php elseif ($current_conversation['property_id']): ?>
                                | <a href="/property.php?id=<?= $current_conversation['property_id'] ?>">
                                    <?= htmlspecialchars($current_conversation['property_name']) ?>
                                </a>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="ms-auto">
                        <button class="btn btn-sm btn-outline-secondary" id="viewChatHistory">
                            <i class="fas fa-history"></i> History
                        </button>
                    </div>
                </div>

                <!-- Messages Container -->
                <div class="messages-container" id="messagesContainer">
                    <?php foreach ($messages as $message): ?>
                        <div class="message <?= $message['sender_id'] == $user_id ? 'message-sent' : 'message-received' ?>">
                            <?= nl2br(htmlspecialchars($message['message'])) ?>
                            <div class="message-info">
                                <span><?= date('H:i', strtotime($message['created_at'])) ?></span>
                                <?php if ($message['sender_id'] == $user_id): ?>
                                    <span>
                                        <?php if ($message['is_read']): ?>
                                            <i class="fas fa-check-double text-primary"></i>
                                        <?php else: ?>
                                            <i class="fas fa-check text-muted"></i>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="typingIndicatorContainer"></div>
                </div>

                <!-- Input Area -->
                <div class="input-area">
                    <form id="messageForm" class="w-100">
                        <input type="hidden" name="conversation_id" value="<?= $current_conversation['id'] ?>">
                        <div class="input-group">
                            <input type="text" name="message" class="form-control" placeholder="Type your message..." autocomplete="off" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Empty Chat State -->
                <div class="empty-state w-100">
                    <i class="fas fa-comments"></i>
                    <h4>No conversation selected</h4>
                    <p>Select a conversation from the list or start a new one</p>
                    <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#newConversationModal">
                        <i class="fas fa-plus me-2"></i> Start New Conversation
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Conversation Modal -->
    <div class="modal fade" id="newConversationModal" tabindex="-1" aria-labelledby="newConversationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newConversationModalLabel">New Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($potential_partners)): ?>
                        <div class="alert alert-info">
                            No available contacts to start a conversation with.
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($potential_partners as $partner): ?>
                                <a href="api/start_conversation.php?user_id=<?= $partner['id'] ?>&property_id=<?= $partner['property_id'] ?? '' ?>" class="new-conversation-item">
                                    <div class="conversation-avatar me-3" style="background-image: url('<?= $partner['profile_picture'] ?? '' ?>')">
                                        <?= empty($partner['profile_picture']) ? strtoupper(substr($partner['username'], 0, 1)) : '' ?>
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($partner['username']) ?></h6>
                                        <small class="text-muted">
                                            <?php if ($partner['property_name']): ?>
                                                <?= htmlspecialchars($partner['property_name']) ?>
                                            <?php else: ?>
                                                General inquiry
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

        // Toggle conversation list on mobile
        const toggleConversationList = document.getElementById('toggleConversationList');
        const conversationList = document.getElementById('conversationList');
        const backToConversations = document.getElementById('backToConversations');
        const chatArea = document.getElementById('chatArea');
        
        if (toggleConversationList && conversationList) {
            toggleConversationList.addEventListener('click', function() {
                conversationList.classList.toggle('open');
            });
        }
        
        if (backToConversations) {
            backToConversations.addEventListener('click', function() {
                conversationList.classList.add('open');
                chatArea.style.display = 'flex';
            });
        }

        // Auto-scroll to bottom of messages
        const messagesContainer = document.getElementById('messagesContainer');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Handle message form submission
        const messageForm = document.getElementById('messageForm');
        if (messageForm) {
            messageForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const messageInput = this.querySelector('input[name="message"]');
                
                fetch('api/send.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Add message to UI
                        const messagesContainer = document.getElementById('messagesContainer');
                        const newMessage = document.createElement('div');
                        newMessage.className = 'message message-sent';
                        newMessage.innerHTML = `
                            ${data.message.message.replace(/\n/g, '<br>')}
                            <div class="message-info">
                                <span>${new Date(data.message.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                                <span><i class="fas fa-check text-muted"></i></span>
                            </div>
                        `;
                        messagesContainer.appendChild(newMessage);
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                        
                        // Clear input
                        messageInput.value = '';
                    } else {
                        alert('Failed to send message: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to send message');
                });
            });
        }

        // View chat history button
        const viewChatHistory = document.getElementById('viewChatHistory');
        if (viewChatHistory) {
            viewChatHistory.addEventListener('click', function() {
                window.location.href = 'history.php?conversation_id=<?= $current_conversation['id'] ?? '' ?>';
            });
        }

        // Real-time updates with EventSource
        if (typeof(EventSource) !== "undefined" && <?= isset($current_conversation) ? 'true' : 'false' ?>) {
            const eventSource = new EventSource(`api/receive.php?conversation_id=<?= $current_conversation['id'] ?? '' ?>&user_id=<?= $user_id ?>`);
            
            eventSource.onmessage = function(e) {
                const data = JSON.parse(e.data);
                
                if (data.type === 'message') {
                    const messagesContainer = document.getElementById('messagesContainer');
                    const typingIndicatorContainer = document.getElementById('typingIndicatorContainer');
                    
                    // Remove typing indicator if present
                    if (typingIndicatorContainer) {
                        typingIndicatorContainer.innerHTML = '';
                    }
                    
                    // Add new message
                    const newMessage = document.createElement('div');
                    newMessage.className = 'message message-received';
                    newMessage.innerHTML = `
                        ${data.message.message.replace(/\n/g, '<br>')}
                        <div class="message-info">
                            <span>${new Date(data.message.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                        </div>
                    `;
                    messagesContainer.appendChild(newMessage);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // Update read status of sent messages
                    if (data.message.sender_id != <?= $user_id ?>) {
                        document.querySelectorAll('.message-sent .fa-check').forEach(icon => {
                            icon.classList.remove('fa-check', 'text-muted');
                            icon.classList.add('fa-check-double', 'text-primary');
                        });
                    }
                } else if (data.type === 'typing') {
                    const typingIndicatorContainer = document.getElementById('typingIndicatorContainer');
                    if (typingIndicatorContainer) {
                        typingIndicatorContainer.innerHTML = `
                            <div class="typing-indicator">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                        `;
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                } else if (data.type === 'stop_typing') {
                    const typingIndicatorContainer = document.getElementById('typingIndicatorContainer');
                    if (typingIndicatorContainer) {
                        typingIndicatorContainer.innerHTML = '';
                    }
                }
            };
            
            // Typing indicator
            const messageInput = document.querySelector('input[name="message"]');
            let typingTimeout;
            
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    // Send typing indicator
                    fetch('api/typing.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `conversation_id=<?= $current_conversation['id'] ?? '' ?>&user_id=<?= $user_id ?>`
                    });
                    
                    // Clear previous timeout
                    clearTimeout(typingTimeout);
                    
                    // Set timeout to send stop typing after 3 seconds of inactivity
                    typingTimeout = setTimeout(() => {
                        fetch('api/stop_typing.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `conversation_id=<?= $current_conversation['id'] ?? '' ?>&user_id=<?= $user_id ?>`
                        });
                    }, 3000);
                });
            }
            
            // Close connection when leaving page
            window.addEventListener('beforeunload', function() {
                eventSource.close();
            });
        }
    });
    </script>
</body>
</html>
