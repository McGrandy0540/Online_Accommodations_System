<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin (assuming role is stored in session)
if ($_SESSION['status'] !== 'admin') {
    // If not admin, show access denied
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Database connection
require_once '../config/database.php';

// Get the PDO instance from the Database class
$database = new Database();
$pdo = $database->connect();

// Get all pending owner documents with owner information
$stmt = $pdo->prepare("
    SELECT 
        od.*,
        u.username as owner_name,
        u.email as owner_email,
        u.phone_number as owner_phone,
        u.created_at as owner_joined,
        COUNT(p.id) as property_count
    FROM owner_documents od
    JOIN users u ON od.owner_id = u.id
    LEFT JOIN property p ON u.id = p.owner_id
    WHERE od.status = 'pending'
    GROUP BY od.id
    ORDER BY od.uploaded_at DESC
");
$stmt->execute();
$pending_documents = $stmt->fetchAll();

// Get all processed documents for history
$stmt = $pdo->prepare("
    SELECT 
        od.*,
        u.username as owner_name,
        u.email as owner_email,
        u.phone_number as owner_phone,
        admin.username as reviewed_by_name,
        COUNT(p.id) as property_count
    FROM owner_documents od
    JOIN users u ON od.owner_id = u.id
    LEFT JOIN users admin ON od.reviewed_by = admin.id
    LEFT JOIN property p ON u.id = p.owner_id
    WHERE od.status != 'pending'
    GROUP BY od.id
    ORDER BY od.reviewed_at DESC
    LIMIT 20
");
$stmt->execute();
$processed_documents = $stmt->fetchAll();

// Handle document approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = $_POST['document_id'] ?? null;
    $action = $_POST['action'] ?? '';
    $review_notes = $_POST['review_notes'] ?? '';
    
    if ($document_id && in_array($action, ['approve', 'reject'])) {
        try {
            $pdo->beginTransaction();
            
            // Update document status
            $update_stmt = $pdo->prepare("
                UPDATE owner_documents 
                SET status = :status, 
                    reviewed_by = :reviewed_by, 
                    reviewed_at = NOW(), 
                    review_notes = :review_notes 
                WHERE id = :id
            ");
            
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $update_stmt->execute([
                ':status' => $status,
                ':reviewed_by' => $_SESSION['user_id'],
                ':review_notes' => $review_notes,
                ':id' => $document_id
            ]);
            
            // If approved, update all properties to approved status
            if ($action === 'approve') {
                // Get owner ID from document
                $owner_stmt = $pdo->prepare("SELECT owner_id FROM owner_documents WHERE id = :id");
                $owner_stmt->execute([':id' => $document_id]);
                $document = $owner_stmt->fetch();
                
                if ($document) {
                    $property_stmt = $pdo->prepare("
                        UPDATE property 
                        SET approved = 1 
                        WHERE owner_id = :owner_id AND deleted = 0
                    ");
                    $property_stmt->execute([':owner_id' => $document['owner_id']]);
                    
                    // Create notification for owner
                    $notify_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, created_at)
                        VALUES (:user_id, :message, 'document_approval', NOW())
                    ");
                    $notify_stmt->execute([
                        ':user_id' => $document['owner_id'],
                        ':message' => 'Your property documents have been approved. Your properties are now visible to students.'
                    ]);
                }
            }
            
            $pdo->commit();
            
            // Refresh the page to show updated status
            header("Location: ".$_SERVER['PHP_SELF']);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error processing request: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Property Owner Document Approval</title>
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
            --danger-color: #dc3545;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 80px;
            --header-height: 60px;
            --transition-speed: 0.3s;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7f9;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: var(--primary-color);
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .back-button {
            color: white;
            text-decoration: none;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            transition: all var(--transition-speed);
            backdrop-filter: blur(10px);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .page-title {
            font-size: 32px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all var(--transition-speed);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-color);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 15px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stat-icon.pending {
            background: var(--warning-color);
        }

        .stat-icon.approved {
            background: var(--success-color);
        }

        .stat-icon.rejected {
            background: var(--danger-color);
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 5px 0;
            color: var(--secondary-color);
        }

        .stat-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            transition: all var(--transition-speed);
            background: #f8f9fa;
        }

        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 22px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .document-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: all var(--transition-speed);
            border-left: 4px solid var(--warning-color);
        }

        .document-card.approved {
            border-left-color: var(--success-color);
        }

        .document-card.rejected {
            border-left-color: var(--danger-color);
        }

        .document-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .document-owner {
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 5px;
            color: var(--secondary-color);
        }

        .document-contact {
            color: #6c757d;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .document-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 14px;
            color: #6c757d;
        }

        .document-files {
            margin-bottom: 20px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            background: #f8f9fa;
            border-radius: var(--border-radius);
            margin-bottom: 10px;
        }

        .file-name {
            flex: 1;
            font-size: 14px;
        }

        .file-action {
            margin-left: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .review-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
        }

        .status-approved {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }

        .status-rejected {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            outline: 0;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-dialog {
            position: relative;
            width: auto;
            margin: 30px auto;
            max-width: 800px;
        }

        .modal-content {
            position: relative;
            display: flex;
            flex-direction: column;
            width: 100%;
            pointer-events: auto;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid rgba(0, 0, 0, 0.2);
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            outline: 0;
        }

        .modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
        }

        .modal-title {
            margin-bottom: 0;
            line-height: 1.5;
            font-size: 20px;
            color: var(--secondary-color);
        }

        .modal-body {
            position: relative;
            flex: 1 1 auto;
            padding: 20px;
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 20px;
            border-top: 1px solid #e9ecef;
            border-bottom-right-radius: var(--border-radius);
            border-bottom-left-radius: var(--border-radius);
        }

        .close {
            float: right;
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
            color: #000;
            text-shadow: 0 1px 0 #fff;
            opacity: 0.5;
            background: none;
            border: none;
            cursor: pointer;
        }

        .close:hover {
            opacity: 0.75;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .page-title {
                text-align: left;
                font-size: 24px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-title {
                font-size: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-radius: var(--border-radius);
                margin-bottom: 5px;
            }

            .document-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .modal-dialog {
                margin: 10px;
                max-width: none;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 15px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="../admin/dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Property Owner Document Approval</h1>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i> Document status updated successfully.
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo count($pending_documents); ?></div>
                <div class="stat-label">Pending Documents</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon approved">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $approved_count = 0;
                    foreach ($processed_documents as $doc) {
                        if ($doc['status'] === 'approved') $approved_count++;
                    }
                    echo $approved_count;
                    ?>
                </div>
                <div class="stat-label">Approved Documents</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon rejected">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $rejected_count = 0;
                    foreach ($processed_documents as $doc) {
                        if ($doc['status'] === 'rejected') $rejected_count++;
                    }
                    echo $rejected_count;
                    ?>
                </div>
                <div class="stat-label">Rejected Documents</div>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="pending">Pending Review</div>
            <div class="tab" data-tab="processed">Processed Documents</div>
        </div>

        <div class="tab-content active" id="pending">
            <?php if (empty($pending_documents)): ?>
                <div class="card">
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>No Pending Documents</h3>
                        <p>There are no property owner documents awaiting review.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="document-grid">
                    <?php foreach ($pending_documents as $document): ?>
                        <div class="document-card">
                            <div class="document-owner"><?php echo htmlspecialchars($document['owner_name']); ?></div>
                            <div class="document-contact">
                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($document['owner_email']); ?></div>
                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($document['owner_phone']); ?></div>
                                <div><i class="fas fa-home"></i> <?php echo $document['property_count']; ?> Properties</div>
                            </div>
                            <div class="document-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($document['uploaded_at'])); ?></span>
                                <span class="status-badge status-pending">Pending</span>
                            </div>
                            <div class="document-files">
                                <div class="file-item">
                                    <span class="file-name">Ghana Card</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['ghana_card_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Passport Photo</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['passport_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Land Document</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['land_document_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Property Document</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['property_document_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                            <div class="review-form">
                                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                                    <input type="hidden" name="document_id" value="<?php echo $document['id']; ?>">
                                    <div class="form-group">
                                        <label class="form-label" for="review_notes_<?php echo $document['id']; ?>">Review Notes:</label>
                                        <textarea class="form-control" id="review_notes_<?php echo $document['id']; ?>" name="review_notes" placeholder="Add notes about your review decision"></textarea>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" name="action" value="approve" class="btn btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="processed">
            <?php if (empty($processed_documents)): ?>
                <div class="card">
                    <div class="no-data">
                        <div class="no-data-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3>No Processed Documents</h3>
                        <p>There are no processed property owner documents to display.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="document-grid">
                    <?php foreach ($processed_documents as $document): ?>
                        <div class="document-card <?php echo $document['status']; ?>">
                            <div class="document-owner"><?php echo htmlspecialchars($document['owner_name']); ?></div>
                            <div class="document-contact">
                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($document['owner_email']); ?></div>
                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($document['owner_phone']); ?></div>
                                <div><i class="fas fa-home"></i> <?php echo $document['property_count']; ?> Properties</div>
                            </div>
                            <div class="document-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($document['uploaded_at'])); ?></span>
                                <span class="status-badge status-<?php echo $document['status']; ?>">
                                    <?php echo ucfirst($document['status']); ?>
                                </span>
                            </div>
                            <?php if ($document['reviewed_by_name']): ?>
                                <div class="document-meta">
                                    <span>Reviewed by: <?php echo htmlspecialchars($document['reviewed_by_name']); ?></span>
                                    <span><?php echo date('M j, Y', strtotime($document['reviewed_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($document['review_notes'])): ?>
                                <div class="document-meta">
                                    <strong>Review Notes:</strong>
                                    <p><?php echo htmlspecialchars($document['review_notes']); ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="document-files">
                                <div class="file-item">
                                    <span class="file-name">Ghana Card</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['ghana_card_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Passport Photo</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['passport_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Land Document</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['land_document_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div class="file-item">
                                    <span class="file-name">Property Document</span>
                                    <a href="../uploads/owner_documents/<?php echo htmlspecialchars($document['property_document_path']); ?>" target="_blank" class="btn btn-primary btn-sm file-action">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });

        // Confirmation for reject action
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const actionButton = e.submitter;
                if (actionButton && actionButton.value === 'reject') {
                    const notes = this.querySelector('textarea[name="review_notes"]').value;
                    if (!notes || notes.trim() === '') {
                        e.preventDefault();
                        alert('Please provide review notes when rejecting documents.');
                    } else if (!confirm('Are you sure you want to reject these documents? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                }
            });
        });

        // Show modal for document preview (if implemented)
        document.querySelectorAll('.file-action').forEach(button => {
            button.addEventListener('click', function(e) {
                // This would open a modal with the document preview
                // Implementation would depend on your document viewer setup
            });
        });
    </script>
</body>
</html>