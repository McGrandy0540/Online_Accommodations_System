<?php
// Start session and check admin authentication
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Database connection
require_once __DIR__ . '../../../config/database.php';

// Initialize variables
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$property_name = '';
$confirmation = false;
$error = '';
$success = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Start transaction
        $db->beginTransaction();

        // Get property details for confirmation
        $stmt = $db->prepare("SELECT id, property_name, owner_id FROM property WHERE id = ? AND deleted = 0");
        $stmt->execute([$property_id]);
        $property = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$property) {
            throw new Exception("Property not found or already deleted");
        }

        // Soft delete the property
        $stmt = $db->prepare("UPDATE property SET deleted = 1 WHERE id = ?");
        $stmt->execute([$property_id]);

        // Update related rooms
        $stmt = $db->prepare("UPDATE property_rooms SET status = 'maintenance' WHERE property_id = ?");
        $stmt->execute([$property_id]);

        // Cancel pending bookings
        $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' 
                            WHERE property_id = ? AND status IN ('pending', 'confirmed')");
        $stmt->execute([$property_id]);

        // Log admin action
        $admin_id = $_SESSION['admin_id'];
        $action = "Deleted property: " . $property['property_name'] . " (ID: $property_id)";
        
        $stmt = $db->prepare("INSERT INTO admin_actions (admin_id, action_type, target_id, target_type, details) 
                            VALUES (?, 'delete', ?, 'property', ?)");
        $stmt->execute([$admin_id, $property_id, $action]);

        // Commit transaction
        $db->commit();

        $success = "Property '{$property['property_name']}' has been successfully deleted.";
        $confirmation = false; // Reset confirmation

    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error deleting property: " . $e->getMessage();
    }
} elseif ($property_id > 0) {
    // Get property details for confirmation
    try {
        $stmt = $db->prepare("SELECT property_name FROM property WHERE id = ? AND deleted = 0");
        $stmt->execute([$property_id]);
        $property = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($property) {
            $property_name = $property['property_name'];
            $confirmation = true;
        } else {
            $error = "Property not found or already deleted";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "Invalid property ID";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Property | Admin Panel</title>
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
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--secondary-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .delete-container {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-top: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        h1 {
            color: var(--secondary-color);
            margin-bottom: 20px;
            font-size: 24px;
            text-align: center;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.3);
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--accent-color);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .property-info {
            background-color: var(--light-color);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border-left: 4px solid var(--accent-color);
        }

        .property-name {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 10px;
        }

        .warning-message {
            background-color: rgba(255, 193, 7, 0.1);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border-left: 4px solid var(--warning-color);
            color: var(--secondary-color);
        }

        .warning-icon {
            color: var(--warning-color);
            margin-right: 10px;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            padding: 12px 24px;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-danger {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background-color: #1a252f;
            transform: translateY(-2px);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline:hover {
            background-color: rgba(44, 62, 80, 0.1);
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .back-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .delete-container {
                padding: 20px;
            }
            
            h1 {
                font-size: 20px;
            }
            
            .btn {
                padding: 10px 18px;
                font-size: 14px;
                width: 100%;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="delete-container">
            <h1>Delete Property</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <a href="index.php" class="btn btn-secondary">Back to Properties</a>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <a href="index.php" class="btn btn-secondary">Back to Properties</a>
            <?php elseif ($confirmation): ?>
                <div class="property-info">
                    <div class="property-name">Property: <?php echo htmlspecialchars($property_name); ?></div>
                    <div>ID: <?php echo $property_id; ?></div>
                </div>
                
                <div class="warning-message">
                    <div>
                        <span class="warning-icon">⚠️</span>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>
                    <p style="margin-top: 10px;">
                        Deleting this property will also cancel all pending bookings and mark related rooms as unavailable.
                    </p>
                </div>
                
                <form method="POST" action="delete.php?id=<?php echo $property_id; ?>">
                    <div class="btn-group">
                        <button type="submit" name="confirm_delete" class="btn btn-danger">
                            Confirm Delete
                        </button>
                        <a href="index.php" class="btn btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-danger">
                    No property specified for deletion.
                </div>
                <a href="index.php" class="btn btn-secondary">Back to Properties</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>