<?php
// Database connection
require_once __DIR__ . '../../../config/database.php';
$db = Database::getInstance();

// Start session and check admin privileges
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user ID is provided and valid
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid user ID";
    header("Location: index.php");
    exit();
}

$userId = intval($_GET['id']);
$errors = [];
$success = '';

// Fetch user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND deleted = 0");
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

// Pre-calculate user initial for avatar fallback
$userInitial = strtoupper(substr($user['username'], 0, 1));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $userData = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'status' => trim($_POST['status'] ?? 'student'),
        'sex' => trim($_POST['sex'] ?? 'male'),
        'location' => trim($_POST['location'] ?? ''),
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'email_notifications' => isset($_POST['email_notifications']) ? 1 : 0,
        'sms_notifications' => isset($_POST['sms_notifications']) ? 1 : 0,
        'pwd' => trim($_POST['pwd'] ?? '')
    ];

    // Handle file upload
    if (!empty($_FILES['profile_picture']['name'])) {
        $uploadDir = __DIR__ . '/../../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($fileInfo, $_FILES['profile_picture']['tmp_name']);
        finfo_close($fileInfo);

        if (!in_array($detectedType, $allowedTypes)) {
            $errors[] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
        } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File size must be less than 2MB.';
        } else {
            $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $userId . '_' . time() . '.' . $extension;
            $destination = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                // Delete old profile picture if it exists
                if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/../../' . $user['profile_picture'])) {
                    unlink(__DIR__ . '/../../' . $user['profile_picture']);
                }
                $userData['profile_picture'] = 'uploads/profiles/' . $filename;
            } else {
                $errors[] = 'Failed to upload profile picture.';
            }
        }
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Build the update query
            $updateFields = [];
            $updateValues = [];
            
            foreach ($userData as $field => $value) {
                if ($field === 'pwd' && !empty($value)) {
                    $updateFields[] = "pwd = ?";
                    $updateValues[] = password_hash($value, PASSWORD_DEFAULT);
                } elseif ($field !== 'pwd') {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $value;
                }
            }
            
            // Only update password if it was provided
            if (empty($userData['pwd'])) {
                unset($userData['pwd']);
            }
            
            $updateValues[] = $userId;
            
            $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute($updateValues);
            
            $db->commit();
            
            $success = 'User updated successfully!';
            
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $userInitial = strtoupper(substr($user['username'], 0, 1));
        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User | Admin Panel</title>
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

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .admin-header h1 {
            font-size: 1.75rem;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .admin-header .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: var(--light);
            color: var(--dark);
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: var(--transition);
        }

        .admin-header .back-btn:hover {
            background-color: var(--light-gray);
        }

        .card {
            background-color: var(--white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .card-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background-color: rgba(239, 35, 60, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: var(--light);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            background-color: var(--light);
            transition: var(--transition);
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            accent-color: var(--primary);
        }

        .invalid-feedback {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
        }

        .text-muted {
            color: var(--gray);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
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
            background-color: var(--light);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: var(--light-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
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

        .profile-picture-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--light-gray);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }
        
        .profile-picture-upload {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .profile-picture-upload label {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: var(--primary);
            color: var(--white);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 0.5rem;
        }
        
        .profile-picture-upload label:hover {
            background-color: var(--primary-dark);
        }
        
        .profile-picture-upload input[type="file"] {
            display: none;
        }
        
        .profile-picture-filename {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }
        
        .avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid var(--light-gray);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .card {
                padding: 1.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="admin-header">
            <h1>
                <i class="fas fa-user-cog"></i>
                Edit User: <?php echo htmlspecialchars($user['username']); ?>
            </h1>
            <a href="index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </header>

        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin-top: 0.5rem; padding-left: 1.25rem;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit.php?id=<?php echo $userId; ?>" enctype="multipart/form-data">
                <div class="profile-picture-container">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                             alt="Profile Picture" 
                             class="profile-picture"
                             data-initial="<?php echo htmlspecialchars($userInitial); ?>"
                             onerror="this.onerror=null; this.parentNode.innerHTML='<div class=\'avatar-initials\'>'+this.getAttribute(\'data-initial\')+'<\/div>'">
                    <?php else: ?>
                        <div class="avatar-initials">
                            <?php echo $userInitial; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="profile-picture-upload">
                        <label for="profile_picture">
                            <i class="fas fa-camera"></i> Change Photo
                        </label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                        <div class="profile-picture-filename" id="file-name">No file chosen</div>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div>
                        <div class="form-group">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            <?php if (isset($errors['username'])): ?>
                                <span class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i> <?php echo $errors['username']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            <?php if (isset($errors['email'])): ?>
                                <span class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i> <?php echo $errors['email']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="pwd" class="form-label">Password</label>
                            <input type="password" id="pwd" name="pwd" class="form-control" 
                                   placeholder="Leave blank to keep current">
                            <span class="text-muted">
                                <i class="fas fa-info-circle"></i> Minimum 8 characters
                            </span>
                            <?php if (isset($errors['pwd'])): ?>
                                <span class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i> <?php echo $errors['pwd']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div>
                        <div class="form-group">
                            <label for="status" class="form-label">User Type *</label>
                            <select id="status" name="status" class="form-select" required>
                                <option value="student" <?php echo $user['status'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="property_owner" <?php echo $user['status'] === 'property_owner' ? 'selected' : ''; ?>>Property Owner</option>
                                <option value="admin" <?php echo $user['status'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="sex" class="form-label">Gender</label>
                            <select id="sex" name="sex" class="form-select">
                                <option value="male" <?php echo $user['sex'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $user['sex'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo $user['sex'] === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="location" class="form-label">Location</label>
                            <input type="text" id="location" name="location" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['location']); ?>">
                        </div>
                    </div>
                    
                    <div>
                        <div class="form-group">
                            <label for="phone_number" class="form-label">Phone Number</label>
                            <input type="tel" id="phone_number" name="phone_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['phone_number']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_method" class="form-label">Preferred Payment Method</label>
                            <select id="payment_method" name="payment_method" class="form-select">
                                <option value="cash" <?php echo $user['payment_method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="mobile_money" <?php echo $user['payment_method'] === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="credit_card" <?php echo $user['payment_method'] === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="bank_transfer" <?php echo $user['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <div class="form-group">
                            <label class="form-label">Notification Preferences</label>
                            <div class="form-check">
                                <input type="checkbox" id="email_notifications" name="email_notifications" 
                                       class="form-check-input" value="1" <?php echo $user['email_notifications'] ? 'checked' : ''; ?>>
                                <label for="email_notifications">Email Notifications</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="sms_notifications" name="sms_notifications" 
                                       class="form-check-input" value="1" <?php echo $user['sms_notifications'] ? 'checked' : ''; ?>>
                                <label for="sms_notifications">SMS Notifications</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Current Status</label>
                            <div>
                                <span class="status-badge status-<?php echo $user['status']; ?>">
                                    <i class="fas fa-user-tag"></i> 
                                    <?php echo ucfirst(str_replace('_', ' ', $user['status'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Last Updated</label>
                            <div>
                                <i class="fas fa-calendar-alt"></i> 
                                <?php echo date('M j, Y g:i A', strtotime($user['updated_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Display the selected file name
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
            document.getElementById('file-name').textContent = fileName;
        });
    </script>
</body>
</html>