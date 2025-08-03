<?php
require_once __DIR__ . '../../../config/database.php';
$db = Database::getInstance();

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$userId = intval($_GET['id']);

// Fetch user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['error'] = "User not found";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: index.php");
    exit();
}

// Fetch additional user data based on their status
$userProperties = [];
$userBookings = [];
$userReviews = [];

if ($user['status'] === 'property_owner') {
    // Get properties owned by this user
    $stmt = $db->prepare("SELECT * FROM property WHERE owner_id = ? AND deleted = 0 ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $userProperties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['status'] === 'student') {
    // Get recent bookings by this user
    $stmt = $db->prepare("SELECT b.*, p.property_name 
                         FROM bookings b
                         JOIN property p ON b.property_id = p.id
                         WHERE b.user_id = ? 
                         ORDER BY b.booking_date DESC 
                         LIMIT 5");
    $stmt->execute([$userId]);
    $userBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get reviews by this user
    $stmt = $db->prepare("SELECT r.*, p.property_name 
                         FROM reviews r
                         JOIN property p ON r.property_id = p.id
                         WHERE r.user_id = ? 
                         ORDER BY r.created_at DESC 
                         LIMIT 5");
    $stmt->execute([$userId]);
    $userReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
}



// Generate avatar initials
$userInitial = strtoupper(substr($user['username'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile | Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #3f37c9;
            --accent: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #ef233c;
            --white: #ffffff;
            
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 1rem;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1), 0 5px 10px rgba(0,0,0,0.05);
            
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: #f5f7ff;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        h1 {
            font-size: 1.75rem;
            color: var(--secondary);
        }

        .card {
            background-color: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .profile-picture-container {
            position: relative;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--light-gray);
            box-shadow: var(--shadow-sm);
        }

        .avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid var(--light-gray);
            box-shadow: var(--shadow-sm);
        }

        .profile-info {
            flex: 1;
            min-width: 250px;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--secondary);
        }

        .profile-email {
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .profile-meta {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
        }

        .meta-icon {
            color: var(--primary);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-student {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .status-property_owner {
            background-color: rgba(63, 55, 201, 0.1);
            color: var(--secondary);
        }

        .status-admin {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--accent);
        }

        .section-title {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            color: var(--secondary);
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background-color: var(--light);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .info-title {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .credit-score {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--dark);
        }

        tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background-color: var(--secondary);
            color: var(--white);
        }

        .btn-secondary:hover {
            background-color: #2a2d7a;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-danger {
            background-color: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background-color: #d11a2a;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        .rating {
            color: var(--warning);
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .grid {
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user"></i> User Profile</h1>
            <div class="action-buttons">
                <a href="edit.php?id=<?php echo $userId; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="delete.php?id=<?php echo $userId; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                    <i class="fas fa-trash"></i> Delete User
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
            </div>
        </div>

        <div class="card">
            <div class="profile-header">
                <div class="profile-picture-container">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                             alt="Profile Picture" 
                             class="profile-avatar"
                             onerror="this.onerror=null; this.classList.add('hidden'); document.getElementById('avatar-initials').classList.remove('hidden');">
                        <div id="avatar-initials" class="avatar-initials hidden"><?php echo $userInitial; ?></div>
                    <?php else: ?>
                        <div class="avatar-initials"><?php echo $userInitial; ?></div>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['username']); ?></h2>
                    <div class="profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
                    <div class="profile-meta">
                        <span class="meta-item">
                            <i class="fas fa-user-tag meta-icon"></i>
                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $user['status'])); ?>
                            </span>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-map-marker-alt meta-icon"></i>
                            <?php echo htmlspecialchars($user['location']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-phone meta-icon"></i>
                            <?php echo htmlspecialchars($user['phone_number']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-calendar-alt meta-icon"></i>
                            Joined <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid">
                <div class="info-card">
                    <div class="info-title">Payment Method</div>
                    <div class="info-value">
                        <?php echo ucfirst(str_replace('_', ' ', $user['payment_method'])); ?>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-title">Notification Preferences</div>
                    <div class="info-value">
                        <?php if ($user['email_notifications']): ?>
                            <span style="display: inline-block; margin-right: 1rem;">
                                <i class="fas fa-envelope" style="color: var(--primary);"></i> Email
                            </span>
                        <?php endif; ?>
                        <?php if ($user['sms_notifications']): ?>
                            <span style="display: inline-block;">
                                <i class="fas fa-sms" style="color: var(--primary);"></i> SMS
                            </span>
                        <?php endif; ?>
                        <?php if (!$user['email_notifications'] && !$user['sms_notifications']): ?>
                            None
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($creditHistory)): ?>
                <h3 class="section-title"><i class="fas fa-chart-line"></i> Credit Score History</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Change</th>
                                <th>New Score</th>
                                <th>Reason</th>
                                <th>Changed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditHistory as $history): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($history['created_at'])); ?></td>
                                    <td><?php echo $history['score_change'] > 0 ? '+' : ''; ?><?php echo $history['score_change']; ?></td>
                                    <td><?php echo $history['new_score']; ?></td>
                                    <td><?php echo htmlspecialchars($history['reason']); ?></td>
                                    <td><?php echo ucfirst($history['changed_by']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($user['status'] === 'property_owner'): ?>
                <h3 class="section-title"><i class="fas fa-home"></i> Properties</h3>
                <?php if (!empty($userProperties)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Price</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userProperties as $property): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($property['property_name']); ?></td>
                                        <td>$<?php echo number_format($property['price'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($property['location']); ?></td>
                                        <td>
                                            <span class="status-badge">
                                                <?php echo ucfirst($property['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($property['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="action-buttons">
                        <a href="/admin/properties/index.php?owner=<?php echo $userId; ?>" class="btn btn-secondary">
                            <i class="fas fa-list"></i> View All Properties
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-home empty-icon"></i>
                        <p>This user hasn't listed any properties yet.</p>
                    </div>
                <?php endif; ?>
            <?php elseif ($user['status'] === 'student'): ?>
                <?php if (!empty($userBookings)): ?>
                    <h3 class="section-title"><i class="fas fa-calendar-check"></i> Recent Bookings</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Dates</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Booked On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userBookings as $booking): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking['property_name']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($booking['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($booking['end_date'])); ?>
                                        </td>
                                        <td><?php echo $booking['duration_months']; ?> months</td>
                                        <td>
                                            <span class="status-badge">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="action-buttons">
                        <a href="/admin/bookings/index.php?user=<?php echo $userId; ?>" class="btn btn-secondary">
                            <i class="fas fa-list"></i> View All Bookings
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($userReviews)): ?>
                    <h3 class="section-title"><i class="fas fa-star"></i> Recent Reviews</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Rating</th>
                                    <th>Comment</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userReviews as $review): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($review['property_name']); ?></td>
                                        <td>
                                            <span class="rating">
                                                <?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo !empty($review['comment']) ? htmlspecialchars(substr($review['comment'], 0, 50)) . (strlen($review['comment']) > 50 ? '...' : '') : 'No comment'; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Handle avatar image error by showing initials
        document.addEventListener('DOMContentLoaded', function() {
            const avatar = document.querySelector('.profile-avatar');
            if (avatar) {
                avatar.onerror = function() {
                    this.classList.add('hidden');
                    document.getElementById('avatar-initials').classList.remove('hidden');
                };
            }
            
            // Make tables more responsive on small screens
            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            });
        });
    </script>
</body>
</html>