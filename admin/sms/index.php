<?php
session_start();
require_once __DIR__ . '../../../config/database.php';
require_once __DIR__ . '../../../includes/auth-check.php';
require_once __DIR__ . '../../../includes/SMSService.php';
require_once __DIR__ . '../../../includes/NotificationService.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit();
}        

$smsService = new SMSService();
$notificationService = new NotificationService();
$pdo = Database::getInstance();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'send_test_sms':
                $phoneNumber = $_POST['phone_number'] ?? '';
                $message = $_POST['message'] ?? '';
                
                if ($phoneNumber && $message) {
                    $result = $smsService->sendSMS($phoneNumber, $message);
                    $_SESSION['success_message'] = $result ? "Test SMS sent successfully!" : "Failed to send test SMS.";
                    $_SESSION['error_message'] = $result ? "" : "Please check the logs for details.";
                }
                break;
                
            case 'process_pending':
                $results = $notificationService->processPendingSMSForUser($_SESSION['user_id']);
                $_SESSION['success_message'] = "Processed {$results['processed']} notifications. Success: {$results['success']}, Failed: {$results['failed']}";
                break;
                
            case 'cleanup_old':
                $days = intval($_POST['days'] ?? 90);
                $result = $notificationService->deleteOldNotifications($days);
                $_SESSION['success_message'] = $result ? "Old notifications cleaned up successfully." : "Failed to cleanup old notifications.";
                break;
        }
        header("Location: index.php");
        exit();
    }
}

// Get SMS statistics
$smsStats = $smsService->getSMSStats();
$notificationStats = $notificationService->getNotificationStats();

// Get recent SMS logs
$recentSMS = [];
try {
    $stmt = $pdo->prepare("
        SELECT sl.*, u.username, u.email 
        FROM sms_logs sl
        LEFT JOIN notifications n ON sl.notification_id = n.id
        LEFT JOIN users u ON n.user_id = u.id
        ORDER BY sl.created_at DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $recentSMS = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching SMS logs: " . $e->getMessage());
}

// Calculate today's statistics
$today = date('Y-m-d');
$todayStats = [
    'sent' => 0,
    'failed' => 0,
    'delivered' => 0,
    'pending' => 0
];

foreach ($smsStats as $stat) {
    if ($stat['date'] === $today) {
        $todayStats[$stat['status']] = $stat['count'];
    }
}

// Get pending notifications count
$pendingCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM notifications n
        JOIN users u ON n.user_id = u.id
        WHERE n.delivered = 0 
        AND u.phone_number IS NOT NULL 
        AND u.phone_number != ''
    ");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching pending count: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Management | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background-color: var(--light-color);
            color: var(--dark-color);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: var(--secondary-color);
            color: white;
            position: fixed;
            height: 100vh;
            transition: all var(--transition-speed);
            box-shadow: var(--box-shadow);
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .sidebar-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .sidebar-menu {
            padding: 15px 0;
            overflow-y: auto;
            height: calc(100vh - var(--header-height));
        }
        
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all var(--transition-speed);
            gap: 12px;
            font-size: 1rem;
        }
        
        .sidebar-menu li a:hover, 
        .sidebar-menu li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar-menu li a i {
            width: 24px;
            text-align: center;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            transition: margin-left var(--transition-speed);
        }
        
        .top-nav {
            background: white;
            padding: 15px 25px;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: var(--header-height);
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary-color);
            display: none;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .admin-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .content-area {
            padding: 25px;
            flex: 1;
        }
        
        .page-header {
            margin-bottom: 25px;
        }
        
        .page-title h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-weight: 600;
            padding: 15px 20px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-color: rgba(40, 167, 69, 0.2);
        }
        
        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--accent-color);
            border-color: rgba(231, 76, 60, 0.2);
        }
        
        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Mobile responsiveness */
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
            
            .card-body {
                padding: 15px;
            }
            
            .content-area {
                padding: 15px;
            }
            
            .page-title h1 {
                font-size: 1.5rem;
            }
            
            .col-md-3 {
                margin-bottom: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-header, .sidebar-menu li span {
                display: none;
            }
            
            .sidebar-menu li a {
                justify-content: center;
                padding: 12px 0;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .sidebar.active {
                width: var(--sidebar-width);
            }
            
            .sidebar.active .sidebar-header, 
            .sidebar.active .sidebar-menu li span {
                display: block;
            }
            
            .sidebar.active .sidebar-menu li a {
                justify-content: flex-start;
                padding: 12px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../../assets/images/default-profile.png" alt="Profile Picture">
            <div>
                <h3><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></h3>
                <small>Administrator</small>
            </div>
        </div>
        <div class="sidebar-menu">
            <ul>
                <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="../users/"><i class="fas fa-users"></i> User Management</a></li>
                <li><a href="../properties/"><i class="fas fa-home"></i> Property Management</a></li>
                <li><a href="../bookings/"><i class="fas fa-calendar-alt"></i> Booking Management</a></li>
                <li><a href="../payments/"><i class="fas fa-money-bill-wave"></i> Payment Management</a></li>
                <li><a href="index.php" class="active"><i class="fas fa-sms"></i> SMS Management</a></li>
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
                <img src="../../../assets/images/default-profile.png" alt="User Profile">
                <span><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?> <span class="admin-badge">ADMIN</span></span>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
            <div class="page-header">
                <div class="page-title">
                    <h1><i class="fas fa-sms me-2"></i> SMS Management</h1>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="text-success"><?= $todayStats['sent'] ?></h4>
                                    <p class="mb-0 text-muted">SMS Sent Today</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-info">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="text-info"><?= $todayStats['delivered'] ?></h4>
                                    <p class="mb-0 text-muted">Delivered Today</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-mobile-alt fa-2x text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="text-warning"><?= $pendingCount ?></h4>
                                    <p class="mb-0 text-muted">Pending SMS</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-clock fa-2x text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="text-danger"><?= $todayStats['failed'] ?></h4>
                                    <p class="mb-0 text-muted">Failed Today</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMS Statistics Chart -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">SMS Statistics (Last 7 Days)</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="smsChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-3">
                                <button class="btn btn-outline-primary" onclick="processPending()">
                                    <i class="fas fa-sync me-2"></i> Process Pending SMS
                                </button>
                                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                                    <i class="fas fa-trash me-2"></i> Cleanup Old Data
                                </button>
                                <a href="../../test_sms.php" class="btn btn-outline-info" target="_blank">
                                    <i class="fas fa-vial me-2"></i> Run System Test
                                </a>
                                <a href="logs.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-list me-2"></i> View All Logs
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent SMS Logs -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent SMS Activity</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#testSMSModal">
                        <i class="fas fa-plus me-1"></i> Send Test SMS
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($recentSMS)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No SMS activity yet. Send your first SMS to see logs here.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Phone Number</th>
                                        <th>User</th>
                                        <th>Message</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSMS as $sms): ?>
                                        <tr>
                                            <td><?= date('M j, Y H:i', strtotime($sms['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($sms['phone_number']) ?></td>
                                            <td>
                                                <?php if ($sms['username']): ?>
                                                    <?= htmlspecialchars($sms['username']) ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($sms['email']) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($sms['message']) ?>">
                                                    <?= htmlspecialchars(substr($sms['message'], 0, 50)) ?>
                                                    <?php if (strlen($sms['message']) > 50) echo '...'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $statusClass = 'secondary';
                                                switch ($sms['status']) {
                                                    case 'sent':
                                                    case 'delivered':
                                                        $statusClass = 'success';
                                                        break;
                                                    case 'failed':
                                                    case 'error':
                                                        $statusClass = 'danger';
                                                        break;
                                                    case 'pending':
                                                        $statusClass = 'warning';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge bg-<?= $statusClass ?>">
                                                    <?= ucfirst($sms['status']) ?>
                                                </span>
                                                <?php if ($sms['error_message']): ?>
                                                    <i class="fas fa-info-circle text-danger ms-1" title="<?= htmlspecialchars($sms['error_message']) ?>"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Test SMS Modal -->
<div class="modal fade" id="testSMSModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Send Test SMS</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_test_sms">
                    <div class="mb-3">
                        <label for="phone_number" class="form-label">Phone Number</label>
                        <input type="text" class="form-control" id="phone_number" name="phone_number" 
                               placeholder="+233244123456" required>
                        <div class="form-text">Enter phone number in international format</div>
                    </div>
                    <div class="mb-3">
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control" id="message" name="message" rows="3" 
                                  maxlength="160" required>Test SMS from Landlords&Tenants system</textarea>
                        <div class="form-text">Maximum 160 characters</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send SMS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cleanup Modal -->
<div class="modal fade" id="cleanupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Cleanup Old Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="cleanup_old">
                    <div class="mb-3">
                        <label for="days" class="form-label">Delete notifications older than:</label>
                        <select class="form-select" id="days" name="days">
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">1 year</option>
                        </select>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        This action cannot be undone. Old notifications and their associated data will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Delete Old Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// SMS Statistics Chart
const ctx = document.getElementById('smsChart').getContext('2d');
const smsData = <?= json_encode($smsStats) ?>;

// Process data for chart
const last7Days = [];
const sentData = [];
const failedData = [];
const deliveredData = [];

for (let i = 6; i >= 0; i--) {
    const date = new Date();
    date.setDate(date.getDate() - i);
    const dateStr = date.toISOString().split('T')[0];
    last7Days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
    
    const dayStats = smsData.filter(stat => stat.date === dateStr);
    sentData.push(dayStats.find(s => s.status === 'sent')?.count || 0);
    failedData.push(dayStats.find(s => s.status === 'failed')?.count || 0);
    deliveredData.push(dayStats.find(s => s.status === 'delivered')?.count || 0);
}

new Chart(ctx, {
    type: 'line',
    data: {
        labels: last7Days,
        datasets: [{
            label: 'Sent',
            data: sentData,
            borderColor: '#3498db',
            backgroundColor: 'rgba(52, 152, 219, 0.1)',
            tension: 0.1,
            borderWidth: 2,
            fill: true
        }, {
            label: 'Delivered',
            data: deliveredData,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.1,
            borderWidth: 2,
            fill: true
        }, {
            label: 'Failed',
            data: failedData,
            borderColor: '#e74c3c',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
            tension: 0.1,
            borderWidth: 2,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            },
            tooltip: {
                mode: 'index',
                intersect: false,
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

function processPending() {
    if (confirm('Process all pending SMS notifications?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type="hidden" name="action" value="process_pending">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Character counter for SMS message
document.getElementById('message').addEventListener('input', function() {
    const remaining = 160 - this.value.length;
    const helpText = this.nextElementSibling;
    helpText.textContent = `${remaining} characters remaining`;
    
    if (remaining < 0) {
        helpText.classList.add('text-danger');
        this.classList.add('is-invalid');
    } else {
        helpText.classList.remove('text-danger');
        this.classList.remove('is-invalid');
    }
});

// Toggle sidebar on mobile
document.getElementById('menuToggle').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('active');
    document.querySelector('.main-content').classList.toggle('sidebar-collapsed');
});

// Auto-hide sidebar on small screens
function handleResize() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (window.innerWidth < 768) {
        sidebar.classList.add('active');
        mainContent.classList.add('sidebar-collapsed');
    } else {
        sidebar.classList.remove('active');
        mainContent.classList.remove('sidebar-collapsed');
    }
}

// Initialize on load
window.addEventListener('load', handleResize);
window.addEventListener('resize', handleResize);
</script>
</body>
</html>