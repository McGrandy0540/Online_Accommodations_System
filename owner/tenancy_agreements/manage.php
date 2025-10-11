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

// Get saved agreements
$agreements_stmt = $pdo->prepare("
    SELECT ga.*, p.property_name, 
           (SELECT COUNT(*) FROM tenant_agreement_access WHERE agreement_id = ga.id) as sent_count
    FROM generated_tenancy_agreements ga
    JOIN property p ON ga.property_id = p.id
    WHERE ga.owner_id = ?
    ORDER BY ga.created_at DESC
");
$agreements_stmt->execute([$owner_id]);
$agreements = $agreements_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agreements | Property Owner</title>
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
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .back-button {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .page-title {
            font-size: 28px;
            flex-grow: 1;
            text-align: center;
        }
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        .card-title {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .agreements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .agreement-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .agreement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .agreement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .agreement-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-published {
            background: #d4edda;
            color: #155724;
        }
        .status-draft {
            background: #fff3cd;
            color: #856404;
        }
        .agreement-details {
            margin-bottom: 15px;
        }
        .agreement-details p {
            margin-bottom: 5px;
            color: #6c757d;
            font-size: 14px;
        }
        .agreement-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #dee2e6;
        }
        .empty-state h3 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        .stats-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-item {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
        }
        .stat-info p {
            font-size: 14px;
            color: #6c757d;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="../dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Manage Tenancy Agreements</h1>
                <a href="generate.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Create New Agreement
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div class="stat-info">
                    <h3><?= count($agreements) ?></h3>
                    <p>Total Agreements</p>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background: #28a745;">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stat-info">
                    <h3><?= array_sum(array_column($agreements, 'sent_count')) ?></h3>
                    <p>Total Sent</p>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon" style="background: #ffc107;">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-info">
                    <h3><?= count(array_unique(array_column($agreements, 'property_id'))) ?></h3>
                    <p>Properties Covered</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-contract"></i>
                Your Generated Agreements
            </h2>

            <?php if (empty($agreements)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Agreements Generated Yet</h3>
                    <p>Create your first tenancy agreement to get started.</p>
                    <a href="generate.php" class="btn btn-primary" style="padding: 12px 24px; font-size: 16px;">
                        <i class="fas fa-plus"></i> Create First Agreement
                    </a>
                </div>
            <?php else: ?>
                <div class="agreements-grid">
                    <?php foreach ($agreements as $agreement): ?>
                        <div class="agreement-card">
                            <div class="agreement-header">
                                <div>
                                    <div class="agreement-title"><?= htmlspecialchars($agreement['agreement_title']) ?></div>
                                </div>
                                <span class="status-badge status-<?= $agreement['status'] ?>">
                                    <?= ucfirst($agreement['status']) ?>
                                </span>
                            </div>
                            <div class="agreement-details">
                                <p><strong>Property:</strong> <?= htmlspecialchars($agreement['property_name']) ?></p>
                                <p><strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($agreement['created_at'])) ?></p>
                                <p><strong>Sent to:</strong> <?= $agreement['sent_count'] ?> tenant(s)</p>
                            </div>
                            <div class="agreement-actions">
                                <a href="preview_agreement.php?agreement_id=<?= $agreement['id'] ?>" 
                                   class="btn btn-secondary" target="_blank">
                                    <i class="fas fa-eye"></i> Preview
                                </a>
                                <a href="send_agreement.php?agreement_id=<?= $agreement['id'] ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Send
                                </a>
                                <a href="generate_document.php?agreement_id=<?= $agreement['id'] ?>" 
                                   class="btn btn-success">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>