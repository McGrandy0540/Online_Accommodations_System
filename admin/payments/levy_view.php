<?php
// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user is admin
if ($_SESSION['status'] !== 'admin') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once __DIR__. '../../../config/database.php';
$database = new Database();
$pdo = $database->connect();

$paymentId = $_GET['id'] ?? null;
if (!$paymentId) {
    header("Location: index.php");
    exit();
}

// Get payment details
$stmt = $pdo->prepare("
    SELECT 
        rlp.*, 
        u.username as owner_name,
        u.email as owner_email,
        u.phone_number as owner_phone,
        a.username as approver_name
    FROM room_levy_payments rlp
    JOIN users u ON rlp.owner_id = u.id
    LEFT JOIN users a ON rlp.admin_approver_id = a.id
    WHERE rlp.id = ?
");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    header("Location: index.php");
    exit();
}

// Get rooms associated with this payment
$roomsStmt = $pdo->prepare("
    SELECT 
        pr.*,
        p.property_name,
        p.location as property_location
    FROM property_rooms pr
    JOIN property p ON pr.property_id = p.id
    WHERE pr.levy_payment_id = ?
    ORDER BY p.property_name, pr.room_number
");
$roomsStmt->execute([$paymentId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle payment approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_payment'])) {
    $notes = $_POST['approval_notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Call the stored procedure to approve the payment
        $stmt = $pdo->prepare("CALL approve_levy_payment(?, ?, ?)");
        $stmt->execute([$paymentId, $_SESSION['user_id'], $notes]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Payment #$paymentId has been successfully approved.";
        header("Location: levy_view.php?id=$paymentId");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error approving payment: " . $e->getMessage();
        header("Location: levy_view.php?id=$paymentId");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Levy Payment Details - Hostel Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar styles */
        #sidebar {
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            color: white;
            transition: var(--transition-speed);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        #sidebar.active {
            margin-left: calc(-1 * var(--sidebar-width));
        }

        .sidebar-header {
            padding: 20px;
            background-color: var(--dark-color);
            text-align: center;
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 1.2rem;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li a {
            display: block;
            padding: 12px 20px;
            color: #b8c7ce;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
        }

        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-speed);
            padding: 20px;
        }

        /* Top navigation */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary-color);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Page header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .breadcrumb {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 10px;
            font-size: 0.9rem;
        }

        .breadcrumb li:not(:last-child)::after {
            content: '/';
            margin-left: 10px;
            color: #999;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .card-body {
            padding: 20px;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .table td {
            padding: 12px 15px;
            border-top: 1px solid #eee;
            vertical-align: middle;
        }

        .table tr:hover {
            background-color: #f9f9f9;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            background-color: var(--success-color);
            color: white;
        }

        .badge-warning {
            background-color: var(--warning-color);
            color: #212529;
        }

        .badge-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .badge-primary {
            background-color: var(--primary-color);
            color: white;
        }

        /* Action buttons */
        .action-btn {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.85rem;
            margin-right: 5px;
            transition: all 0.3s;
        }

        .action-btn i {
            margin-right: 5px;
        }

        .action-btn.view {
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .action-btn.view:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .action-btn.approve {
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .action-btn.approve:hover {
            background-color: var(--success-color);
            color: white;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            #sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            
            #sidebar.active {
                margin-left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        /* Levy payment specific styles */
        .levy-payment-card {
            border-left: 4px solid var(--info-color);
        }
        
        .levy-payment-header {
            background-color: var(--info-color);
            color: white;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div id="sidebar">
            <div class="sidebar-header">
                <h3>Hostel Admin</h3>
            </div>
            <div class="sidebar-menu">
                <ul>
                    <li><a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../properties/index.php"><i class="fas fa-home"></i> Properties</a></li>
                    <li><a href="../bookings/index.php"><i class="fas fa-calendar-check"></i> Bookings</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="../users/index.php"><i class="fas fa-users"></i> Users</a></li>
                    <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i> Reports</a></li>

                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="user-profile">
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="User Profile" style="width: 30px; height: 30px; border-radius: 50%;">
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Room Levy Payment Details</h1>
                <ul class="breadcrumb">
                    <li><a href="../dashboard.php">Home</a></li>
                    <li><a href="index.php">Payments</a></li>
                    <li>Levy Payment #<?= $paymentId ?></li>
                </ul>
            </div>

            <!-- Display error/success messages -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Payment Details Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h2 class="mb-0">Payment Information</h2>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="fw-bold">Payment ID:</label>
                                <p>#<?= $payment['id'] ?></p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Reference:</label>
                                <p><?= htmlspecialchars($payment['payment_reference']) ?></p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Amount:</label>
                                <p>GHS <?= number_format($payment['amount'], 2) ?></p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Payment Method:</label>
                                <p><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="fw-bold">Status:</label>
                                <p>
                                    <?php 
                                    $statusClass = '';
                                    if ($payment['status'] === 'completed') {
                                        $statusClass = 'bg-success';
                                    } elseif ($payment['status'] === 'pending') {
                                        $statusClass = 'bg-warning';
                                    } else {
                                        $statusClass = 'bg-danger';
                                    }
                                    ?>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Payment Date:</label>
                                <p><?= date('M j, Y g:i A', strtotime($payment['payment_date'])) ?></p>
                            </div>
                            <?php if ($payment['status'] === 'completed'): ?>
                                <div class="mb-3">
                                    <label class="fw-bold">Approved By:</label>
                                    <p><?= htmlspecialchars($payment['approver_name']) ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="fw-bold">Approval Date:</label>
                                    <p><?= date('M j, Y g:i A', strtotime($payment['approval_date'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($payment['notes'])): ?>
                        <div class="mb-3">
                            <label class="fw-bold">Notes:</label>
                            <p><?= htmlspecialchars($payment['notes']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Property Owner Details Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h2 class="mb-0">Property Owner Information</h2>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="fw-bold">Name:</label>
                                <p><?= htmlspecialchars($payment['owner_name']) ?></p>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold">Email:</label>
                                <p><?= htmlspecialchars($payment['owner_email']) ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="fw-bold">Phone:</label>
                                <p><?= htmlspecialchars($payment['owner_phone']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rooms Included in Payment -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="mb-0">Rooms Included in Payment</h2>
                        <span class="badge bg-light text-dark">
                            <?= count($rooms) ?> Rooms
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rooms)): ?>
                        <div class="alert alert-info">No rooms found for this payment.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Property</th>
                                        <th>Room #</th>
                                        <th>Capacity</th>
                                        <th>Gender</th>
                                        <th>Status</th>
                                        <th>Levy Status</th>
                                        <th>Expiry Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $room): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($room['property_name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($room['property_location']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($room['room_number']) ?></td>
                                            <td><?= $room['capacity'] ?> students</td>
                                            <td><?= ucfirst($room['gender']) ?></td>
                                            <td>
                                                <?php 
                                                $roomStatusClass = '';
                                                if ($room['status'] === 'available') {
                                                    $roomStatusClass = 'bg-success';
                                                } elseif ($room['status'] === 'occupied') {
                                                    $roomStatusClass = 'bg-warning';
                                                } else {
                                                    $roomStatusClass = 'bg-danger';
                                                }
                                                ?>
                                                <span class="badge <?= $roomStatusClass ?>">
                                                    <?= ucfirst($room['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $levyStatusClass = '';
                                                if ($room['levy_payment_status'] === 'approved') {
                                                    $levyStatusClass = 'bg-success';
                                                } elseif ($room['levy_payment_status'] === 'paid') {
                                                    $levyStatusClass = 'bg-primary';
                                                } else {
                                                    $levyStatusClass = 'bg-warning';
                                                }
                                                ?>
                                                <span class="badge <?= $levyStatusClass ?>">
                                                    <?= ucfirst($room['levy_payment_status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= $room['levy_expiry_date'] ? date('M j, Y', strtotime($room['levy_expiry_date'])) : 'N/A' ?>
                                                <?php if ($room['levy_expiry_date']): ?>
                                                    <br>
                                                    <small class="<?= $room['levy_expiry_date'] < date('Y-m-d') ? 'text-danger' : 'text-success' ?>">
                                                        <?= $room['levy_expiry_date'] < date('Y-m-d') ? 'Expired' : 'Active' ?>
                                                    </small>
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

            <!-- Payment Approval Form (only show if pending) -->
            <?php if ($payment['status'] === 'pending'): ?>
                <div class="card">
                    <div class="card-header bg-warning text-white">
                        <h2 class="mb-0">Payment Approval</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="approvalNotes" class="form-label">Approval Notes</label>
                                <textarea class="form-control" id="approvalNotes" name="approval_notes" rows="3" placeholder="Enter any notes about this approval"></textarea>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Approving this payment will activate all <?= count($rooms) ?> rooms for student listings for 1 year.
                            </div>
                            
                            <div class="d-flex justify-content-end">
                                <a href="index.php" class="btn btn-secondary me-2">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Payments
                                </a>
                                <button type="submit" name="approve_payment" class="btn btn-success">
                                    <i class="fas fa-check me-2"></i> Approve Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Payments
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container">
            <span class="text-muted">Hostel Management System &copy; <?= date('Y') ?></span>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Font Awesome -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>