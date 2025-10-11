<?php
session_start();
require_once '../../config/database.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'property_owner') {
    header("Location: ../../auth/login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

$agreement_id = $_GET['agreement_id'] ?? 0;

// Get agreement details
$agreement_stmt = $pdo->prepare("
    SELECT ga.*, p.property_name, u.username as owner_name 
    FROM generated_tenancy_agreements ga
    JOIN property p ON ga.property_id = p.id 
    JOIN users u ON ga.owner_id = u.id
    WHERE ga.id = ? AND ga.owner_id = ?
");
$agreement_stmt->execute([$agreement_id, $owner_id]);
$agreement = $agreement_stmt->fetch();

if (!$agreement) {
    $_SESSION['error'] = "Agreement not found or access denied.";
    header("Location: manage.php");
    exit();
}

// Get tenants for this property (current bookings)
$tenants_stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.username, u.email, u.phone_number, b.id as booking_id
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    WHERE b.property_id = ? AND b.status IN ('confirmed', 'paid', 'cash_approved')
    ORDER BY u.username
");
$tenants_stmt->execute([$agreement['property_id']]);
$tenants = $tenants_stmt->fetchAll();

// Handle sending agreement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_agreement'])) {
    $selected_tenants = $_POST['tenants'] ?? [];
    $message = $_POST['message'] ?? '';
    
    if (empty($selected_tenants)) {
        $error = "Please select at least one tenant.";
    } else {
        $success_count = 0;
        foreach ($selected_tenants as $tenant_data) {
            list($tenant_id, $booking_id) = explode('_', $tenant_data);
            
            // Record access
            $access_stmt = $pdo->prepare("
                INSERT INTO tenant_agreement_access 
                (agreement_id, tenant_id, booking_id, status, sent_at)
                VALUES (?, ?, ?, 'sent', NOW())
                ON DUPLICATE KEY UPDATE sent_at = NOW(), status = 'sent'
            ");
            if ($access_stmt->execute([$agreement_id, $tenant_id, $booking_id])) {
                $success_count++;
            }
        }
        
        $_SESSION['success'] = "Agreement sent to $success_count tenant(s) successfully!";
        header("Location: manage.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Agreement to Tenants</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background: #f5f7fa;
            color: #2c3e50;
            line-height: 1.6;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .agreement-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .tenants-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
        }
        .tenant-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s;
        }
        .tenant-item:hover {
            background: #f8f9fa;
        }
        .tenant-item:last-child {
            border-bottom: none;
        }
        .tenant-info {
            margin-left: 15px;
        }
        .tenant-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .tenant-details {
            font-size: 14px;
            color: #6c757d;
        }
        .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-paper-plane"></i> Send Agreement to Tenants</h1>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>
        
        <div class="agreement-info">
            <h3>Agreement Details</h3>
            <p><strong>Title:</strong> <?= htmlspecialchars($agreement['agreement_title']) ?></p>
            <p><strong>Property:</strong> <?= htmlspecialchars($agreement['property_name']) ?></p>
            <p><strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($agreement['created_at'])) ?></p>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Select Tenants to Send Agreement:</label>
                <div class="tenants-list">
                    <?php if (empty($tenants)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h3>No Active Tenants</h3>
                            <p>There are no active tenants for this property at the moment.</p>
                            <p>Tenants will appear here once they have confirmed bookings.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <div class="tenant-item">
                                <input type="checkbox" name="tenants[]" 
                                       value="<?= $tenant['id'] ?>_<?= $tenant['booking_id'] ?>" 
                                       id="tenant_<?= $tenant['id'] ?>">
                                <div class="tenant-info">
                                    <div class="tenant-name"><?= htmlspecialchars($tenant['username']) ?></div>
                                    <div class="tenant-details">
                                        Email: <?= htmlspecialchars($tenant['email']) ?> | 
                                        Phone: <?= htmlspecialchars($tenant['phone_number']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Additional Message (Optional):</label>
                <textarea name="message" class="form-textarea" 
                          placeholder="Add a personal message for the tenants...">Dear tenant, please find attached your tenancy agreement. Please review and sign at your earliest convenience.</textarea>
            </div>
            
            <div class="action-buttons">
                <a href="manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Agreements
                </a>
                <?php if (!empty($tenants)): ?>
                    <button type="submit" name="send_agreement" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Agreement
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</body>
</html>