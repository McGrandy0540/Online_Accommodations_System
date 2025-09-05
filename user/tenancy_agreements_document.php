<?php
session_start();
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is a student
if ($_SESSION['status'] !== 'student') {
    die("<h1>Access Denied</h1><p>You don't have permission to access this page.</p>");
}

// Get student data
$student_id = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../auth/login.php');
    exit();
}

// Get tenancy agreements accessible to this student
$agreements_stmt = $pdo->prepare("
    SELECT 
        ta.*,
        p.property_name,
        u.username as owner_name,
        saa.accessed_at,
        saa.downloaded_at
    FROM tenancy_agreements ta
    JOIN student_agreement_access saa ON ta.id = saa.agreement_id
    JOIN property p ON ta.property_id = p.id
    JOIN users u ON ta.owner_id = u.id
    JOIN bookings b ON saa.booking_id = b.id
    WHERE saa.student_id = ? 
    AND b.status IN ('paid', 'confirmed')
    ORDER BY ta.uploaded_at DESC
");
$agreements_stmt->execute([$student_id]);
$agreements = $agreements_stmt->fetchAll();

// Update accessed_at timestamp when page is loaded
if (!empty($agreements)) {
    $update_access = $pdo->prepare("
        UPDATE student_agreement_access 
        SET accessed_at = NOW() 
        WHERE student_id = ? AND accessed_at IS NULL
    ");
    $update_access->execute([$student_id]);
}

// Handle download request
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $agreement_id = $_GET['download'];
    
    // Verify the student has access to this agreement
    $verify_access = $pdo->prepare("
        SELECT ta.file_path 
        FROM tenancy_agreements ta
        JOIN student_agreement_access saa ON ta.id = saa.agreement_id
        WHERE saa.student_id = ? AND ta.id = ?
    ");
    $verify_access->execute([$student_id, $agreement_id]);
    $agreement = $verify_access->fetch();
    
    if ($agreement && file_exists($agreement['file_path'])) {
        // Update downloaded_at timestamp
        $update_download = $pdo->prepare("
            UPDATE student_agreement_access 
            SET downloaded_at = NOW() 
            WHERE student_id = ? AND agreement_id = ?
        ");
        $update_download->execute([$student_id, $agreement_id]);
        
        // Force download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($agreement['file_path']) . '"');
        header('Content-Length: ' . filesize($agreement['file_path']));
        readfile($agreement['file_path']);
        exit();
    } else {
        // File doesn't exist or student doesn't have access
        header("Location: tenancy_agreements.php?error=file_not_found");
        exit();
    }
}

// Get profile picture path
function getProfilePicturePath($path) {
    if (empty($path)) {
        return null;
    }
    
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    
    return '../uploads/profile_pictures/' . ltrim($path, '/');
}

$profile_pic_path = getProfilePicturePath($_SESSION['profile_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenancy Agreements - Student Dashboard</title>
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
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
            transition: background-color var(--transition-speed);
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .page-title {
            font-size: 28px;
            margin: 10px 0;
            text-align: center;
            flex-grow: 1;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 25px;
            margin-bottom: 25px;
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

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--accent-color);
        }

        .agreements-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .agreement-card {
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 20px;
            background-color: white;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
        }

        .agreement-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }

        .agreement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .agreement-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .agreement-details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        @media (min-width: 768px) {
            .agreement-details {
                grid-template-columns: 1fr 1fr;
            }
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        .detail-value {
            font-size: 14px;
            color: var(--secondary-color);
        }

        .agreement-description {
            margin-bottom: 15px;
            color: #495057;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .agreement-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all var(--transition-speed);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .access-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            font-size: 14px;
            color: #6c757d;
        }

        .no-agreements {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-agreements-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
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

            .agreement-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .agreement-actions {
                flex-direction: column;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="page-title">Tenancy Agreements</h1>
                <div></div> <!-- Empty div for spacing -->
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-file-contract"></i>
                Your Tenancy Agreements
            </h2>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                This section displays tenancy agreements from property owners whose properties you have booked and paid for.
            </div>

            <?php if (isset($_GET['error']) && $_GET['error'] == 'file_not_found'): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    The requested file could not be found. Please contact the property owner.
                </div>
            <?php endif; ?>

            <?php if (empty($agreements)): ?>
                <div class="no-agreements">
                    <div class="no-agreements-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3>No Tenancy Agreements Available</h3>
                    <p>You will see tenancy agreements here once you have booked and paid for a property.</p>
                </div>
            <?php else: ?>
                <div class="agreements-list">
                    <?php foreach ($agreements as $agreement): 
                        $file_exists = file_exists($agreement['file_path']);
                    ?>
                        <div class="agreement-card">
                            <div class="agreement-header">
                                <h3 class="agreement-title"><?php echo htmlspecialchars($agreement['title']); ?></h3>
                                <?php if (!$file_exists): ?>
                                    <span style="color: var(--accent-color); font-size: 14px;">
                                        <i class="fas fa-exclamation-triangle"></i> File missing
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="agreement-details">
                                <div class="detail-item">
                                    <span class="detail-label">Property</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($agreement['property_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Property Owner</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($agreement['owner_name']); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Uploaded On</span>
                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($agreement['uploaded_at'])); ?></span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">You Accessed</span>
                                    <span class="detail-value">
                                        <?php echo $agreement['accessed_at'] ? date('M j, Y g:i A', strtotime($agreement['accessed_at'])) : 'Just now'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!empty($agreement['description'])): ?>
                                <div class="agreement-description">
                                    <?php echo htmlspecialchars($agreement['description']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="agreement-actions">
                                <?php if ($file_exists): ?>
                                    <a href="<?php echo htmlspecialchars($agreement['file_path']); ?>" target="_blank" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> View Agreement
                                    </a>
                                    <a href="?download=<?php echo $agreement['id']; ?>" class="btn btn-success">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-primary" disabled>
                                        <i class="fas fa-eye"></i> View Agreement
                                    </button>
                                    <button class="btn btn-success" disabled>
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="access-info">
                                <?php if ($agreement['downloaded_at']): ?>
                                    <span><i class="fas fa-check-circle"></i> Downloaded on <?php echo date('M j, Y', strtotime($agreement['downloaded_at'])); ?></span>
                                <?php else: ?>
                                    <span><i class="fas fa-info-circle"></i> Not downloaded yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>