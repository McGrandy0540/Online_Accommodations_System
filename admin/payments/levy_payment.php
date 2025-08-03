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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'approve_levy') {
        $paymentId = $_POST['payment_id'];
        $roomIds = json_decode($_POST['room_ids']);
        $adminId = $_SESSION['user_id'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // 1. Update payment status
            $stmt = $pdo->prepare("UPDATE room_levy_payments 
                                  SET status = 'completed', 
                                      admin_approver_id = ?,
                                      approval_date = NOW(),
                                      notes = ?
                                  WHERE id = ?");
            $stmt->execute([$adminId, $notes, $paymentId]);
            
            // 2. Update all rooms associated with this payment
            $stmt = $pdo->prepare("UPDATE property_rooms 
                                  SET levy_payment_status = 'approved',
                                      levy_expiry_date = DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
                                      last_renewal_date = NOW(),
                                      renewal_count = renewal_count + 1
                                  WHERE id = ?");
            
            foreach ($roomIds as $roomId) {
                $stmt->execute([$roomId]);
                
                // 3. Record in payment history
                $stmtHistory = $pdo->prepare("INSERT INTO room_levy_payment_history 
                                             (room_id, payment_id, payment_date, expiry_date, amount, status)
                                             SELECT id, ?, payment_date, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 
                                                    payment_amount, 'active'
                                             FROM property_rooms 
                                             WHERE id = ?");
                $stmtHistory->execute([$paymentId, $roomId]);
            }
            
            // 4. Get owner details for notification
            $stmtOwner = $pdo->prepare("SELECT rlp.owner_id, u.username, u.email, 
                                       COUNT(pr.id) as room_count, rlp.amount
                                       FROM room_levy_payments rlp
                                       JOIN users u ON rlp.owner_id = u.id
                                       JOIN property_rooms pr ON pr.payment_id = rlp.id
                                       WHERE rlp.id = ?");
            $stmtOwner->execute([$paymentId]);
            $ownerInfo = $stmtOwner->fetch(PDO::FETCH_ASSOC);
            
            // 5. Send notification to owner
            $stmtNotify = $pdo->prepare("INSERT INTO notifications 
                                        (user_id, message, type, notification_type)
                                        VALUES (?, ?, 'payment_received', 'in_app')");
            $message = "Your levy payment for {$ownerInfo['room_count']} room(s) totaling GHS {$ownerInfo['amount']} has been approved for 1 YEAR";
            $stmtNotify->execute([$ownerInfo['owner_id'], $message]);
            
            // 6. Log admin action
            $stmtLog = $pdo->prepare("INSERT INTO admin_actions 
                                     (admin_id, action_type, target_id, target_type, details)
                                     VALUES (?, 'levy_approval', ?, 'payment', ?)");
            $details = "Approved levy payment #$paymentId for " . count($roomIds) . " rooms";
            $stmtLog->execute([$adminId, $paymentId, $details]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Levy payment approved successfully']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }
    elseif ($_POST['action'] === 'reject_levy') {
        $paymentId = $_POST['payment_id'];
        $roomIds = json_decode($_POST['room_ids']);
        $adminId = $_SESSION['user_id'];
        $reason = $_POST['reason'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // 1. Update payment status
            $stmt = $pdo->prepare("UPDATE room_levy_payments 
                                  SET status = 'failed', 
                                      admin_approver_id = ?,
                                      notes = ?
                                  WHERE id = ?");
            $stmt->execute([$adminId, "$reason: $notes", $paymentId]);
            
            // 2. Update all rooms associated with this payment
            $stmt = $pdo->prepare("UPDATE property_rooms 
                                  SET levy_payment_status = 'pending',
                                      levy_expiry_date = NULL,
                                      payment_id = NULL
                                  WHERE id = ?");
            
            foreach ($roomIds as $roomId) {
                $stmt->execute([$roomId]);
            }
            
            // 3. Get owner details for notification
            $stmtOwner = $pdo->prepare("SELECT rlp.owner_id, u.username, u.email, 
                                       COUNT(pr.id) as room_count, rlp.amount
                                       FROM room_levy_payments rlp
                                       JOIN users u ON rlp.owner_id = u.id
                                       JOIN property_rooms pr ON pr.payment_id = rlp.id
                                       WHERE rlp.id = ?");
            $stmtOwner->execute([$paymentId]);
            $ownerInfo = $stmtOwner->fetch(PDO::FETCH_ASSOC);
            
            // 4. Send notification to owner
            $stmtNotify = $pdo->prepare("INSERT INTO notifications 
                                        (user_id, message, type, notification_type)
                                        VALUES (?, ?, 'payment_received', 'in_app')");
            $message = "Your levy payment for {$ownerInfo['room_count']} room(s) was rejected. Reason: $reason";
            $stmtNotify->execute([$ownerInfo['owner_id'], $message]);
            
            // 5. Log admin action
            $stmtLog = $pdo->prepare("INSERT INTO admin_actions 
                                     (admin_id, action_type, target_id, target_type, details)
                                     VALUES (?, 'levy_rejection', ?, 'payment', ?)");
            $details = "Rejected levy payment #$paymentId. Reason: $reason";
            $stmtLog->execute([$adminId, $paymentId, $details]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Levy payment rejected']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Get pending levy payments
$stmt = $pdo->prepare("
    SELECT rlp.id as payment_id, rlp.payment_reference, rlp.amount, rlp.payment_method, 
           rlp.transaction_id, rlp.payment_date, rlp.room_count,
           u.id as owner_id, u.username as owner_name, u.email as owner_email,
           GROUP_CONCAT(pr.id) as room_ids,
           GROUP_CONCAT(CONCAT(p.property_name, ' - Room ', pr.room_number) SEPARATOR '|') as room_details
    FROM room_levy_payments rlp
    JOIN users u ON rlp.owner_id = u.id
    JOIN property_rooms pr ON pr.payment_id = rlp.id
    JOIN property p ON pr.property_id = p.id
    WHERE rlp.status = 'pending'
    GROUP BY rlp.id
    ORDER BY rlp.payment_date DESC
");
$stmt->execute();
$pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Room Levy Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .payment-card {
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        .payment-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.8rem;
        }
        .owner-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .room-badge {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 48px 0 0;
            box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
            background-color: #2c3e50;
        }
        .sidebar .nav-link {
            color: white;
            padding: 0.5rem 1rem;
            font-size: 1.2rem;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: rgba(255, 255, 255, .1);
        }
        .sidebar .nav-link:hover {
            color: rgba(255, 255, 255, .75);
        }
        .sidebar-heading {
            font-size: 2rem;
            /* text-transform: uppercase; */
            color: white;
            padding: 0.5rem 1rem;
            font-weight: bold;
        }
        main {
            padding-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="sidebar-heading">Landlords&Tenant</div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="../dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../properties/index.php">
                                <i class="bi bi-house-door me-2"></i>Properties
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../users/index.php">
                                <i class="bi bi-people me-2"></i>Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="bi bi-credit-card me-2"></i>Payments
                            </a>
                        </li>

                    </ul>
                    
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Pending Room Levy Payments</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">Export</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">Print</button>
                        </div>
                    </div>
                </div>

                <?php if (empty($pendingPayments)): ?>
                    <div class="alert alert-info">No pending levy payments to approve.</div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($pendingPayments as $payment): 
                            $rooms = explode('|', $payment['room_details']);
                            $roomIds = explode(',', $payment['room_ids']);
                        ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card payment-card">
                                    <div class="card-header bg-light">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Payment #<?= htmlspecialchars($payment['payment_reference']) ?></h5>
                                            <span class="badge bg-warning status-badge">Pending</span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center mb-3">
                                                <img src="https://via.placeholder.com/50" alt="Owner Avatar" class="owner-avatar me-2">
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($payment['owner_name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($payment['owner_email']) ?></small>
                                                </div>
                                            </div>
                                            <ul class="list-unstyled">
                                                <li><strong>Amount:</strong> GHS <?= number_format($payment['amount'], 2) ?></li>
                                                <li><strong>Payment Method:</strong> <?= htmlspecialchars($payment['payment_method']) ?></li>
                                                <li><strong>Transaction ID:</strong> <?= htmlspecialchars($payment['transaction_id']) ?></li>
                                                <li><strong>Date:</strong> <?= date('M j, Y', strtotime($payment['payment_date'])) ?></li>
                                                <li><strong>Rooms:</strong> <?= $payment['room_count'] ?></li>
                                            </ul>
                                        </div>
                                        <div class="mb-3">
                                            <h6>Rooms Included:</h6>
                                            <div>
                                                <?php foreach ($rooms as $room): ?>
                                                    <span class="badge bg-primary room-badge"><?= htmlspecialchars($room) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white">
                                        <div class="d-flex justify-content-between">
                                            <button class="btn btn-sm btn-success approve-btn" 
                                                    data-payment-id="<?= $payment['payment_id'] ?>"
                                                    data-room-ids='<?= json_encode($roomIds) ?>'>
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                            <button class="btn btn-sm btn-danger reject-btn" 
                                                    data-payment-id="<?= $payment['payment_id'] ?>"
                                                    data-room-ids='<?= json_encode($roomIds) ?>'>
                                                <i class="bi bi-x-circle"></i> Reject
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Levy Approval</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this levy payment of <strong>GHS <span id="approvalAmount"></span></strong>?</p>
                    <div class="mb-3">
                        <label for="adminNotes" class="form-label">Admin Notes (Optional)</label>
                        <textarea class="form-control" id="adminNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmApprove">Confirm Approval</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div class="modal fade" id="rejectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirm Levy Rejection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this levy payment of <strong>GHS <span id="rejectionAmount"></span></strong>?</p>
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Reason for Rejection (Required)</label>
                        <select class="form-select" id="rejectionReason">
                            <option value="" selected disabled>Select a reason</option>
                            <option value="invalid_payment">Invalid Payment</option>
                            <option value="unverified_source">Unverified Payment Source</option>
                            <option value="incorrect_amount">Incorrect Amount</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rejectionNotes" class="form-label">Additional Notes</label>
                        <textarea class="form-control" id="rejectionNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmReject">Confirm Rejection</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const approvalModal = new bootstrap.Modal(document.getElementById('approvalModal'));
            const rejectionModal = new bootstrap.Modal(document.getElementById('rejectionModal'));
            
            let currentPaymentId = null;
            let currentRoomIds = null;
            let currentAmount = null;

            // Handle approval buttons
            document.querySelectorAll('.approve-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPaymentId = this.getAttribute('data-payment-id');
                    currentRoomIds = this.getAttribute('data-room-ids');
                    // Get the amount from the card
                    const amount = this.closest('.card').querySelector('ul li:first-child').textContent.replace('Amount: GHS ', '');
                    document.getElementById('approvalAmount').textContent = amount;
                    approvalModal.show();
                });
            });

            // Handle rejection buttons
            document.querySelectorAll('.reject-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPaymentId = this.getAttribute('data-payment-id');
                    currentRoomIds = this.getAttribute('data-room-ids');
                    // Get the amount from the card
                    const amount = this.closest('.card').querySelector('ul li:first-child').textContent.replace('Amount: GHS ', '');
                    document.getElementById('rejectionAmount').textContent = amount;
                    rejectionModal.show();
                });
            });

            // Confirm approval
            document.getElementById('confirmApprove').addEventListener('click', function() {
                const notes = document.getElementById('adminNotes').value;
                
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'approve_levy',
                        'payment_id': currentPaymentId,
                        'room_ids': currentRoomIds,
                        'notes': notes
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        approvalModal.hide();
                        alert('Levy payment approved successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while approving the payment');
                });
            });

            // Confirm rejection
            document.getElementById('confirmReject').addEventListener('click', function() {
                const reason = document.getElementById('rejectionReason').value;
                const notes = document.getElementById('rejectionNotes').value;
                
                if (!reason) {
                    alert('Please select a rejection reason');
                    return;
                }
                
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'reject_levy',
                        'payment_id': currentPaymentId,
                        'room_ids': currentRoomIds,
                        'reason': reason,
                        'notes': notes
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        rejectionModal.hide();
                        alert('Levy payment rejected.');
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while rejecting the payment');
                });
            });
        });
    </script>
</body>
</html>