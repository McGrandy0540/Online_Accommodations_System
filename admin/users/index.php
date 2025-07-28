<?php
// Check admin session (replace with your actual session check)
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['status'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit();
}

// Database connection
require_once(__DIR__ . '../../../config/database.php');
$db = Database::getInstance();

// Pagination setup
$perPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$query = "SELECT * FROM users WHERE deleted = 0";
$params = [];

if (!empty($search)) {
    $query .= " AND (username LIKE :search OR email LIKE :search2)";
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
}

if (!empty($statusFilter)) {
    $query .= " AND status = :status";
    $params[':status'] = $statusFilter;
}

// Get total count for pagination
$countQuery = str_replace('SELECT *', 'SELECT COUNT(*) as total', $query);
$stmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$totalUsers = $stmt->fetchColumn();

// Add sorting and pagination
$query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

// Fetch users
$stmt = $db->prepare($query);

// Bind all parameters
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

// Bind limit and offset as integers
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        h1 {
            color: var(--secondary-color);
            font-size: 24px;
        }

        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-bottom: 30px;
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .status-filter {
            min-width: 200px;
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 16px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
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

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: var(--light-color);
            font-weight: 600;
        }

        tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-student {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
        }

        .status-owner {
            background-color: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .status-admin {
            background-color: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .action-btn:hover {
            transform: scale(1.1);
        }

        .view-btn {
            background-color: var(--secondary-color);
            color: white;
        }

        .edit-btn {
            background-color: var(--primary-color);
            color: white;
        }

        .delete-btn {
            background-color: var(--accent-color);
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 5px;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: block;
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            color: var(--secondary-color);
            text-decoration: none;
            transition: var(--transition);
        }

        .page-link:hover {
            background-color: var(--light-color);
        }

        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #777;
        }

        .empty-icon {
            font-size: 50px;
            margin-bottom: 20px;
            color: #ddd;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filters {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-box, .status-filter {
                min-width: 100%;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 14px;
            }
            
            .card {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .action-btn {
                width: 100%;
                border-radius: var(--border-radius);
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-users"></i> User Management</h1>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New User
            </a>
        </div>

        <div class="card">
            <div class="filters">
                <form method="GET" action="" class="search-form">
                    <div class="search-box">
                        <input type="text" name="search" class="form-control" placeholder="Search users..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="status-filter">
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="student" <?php echo $statusFilter === 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="property_owner" <?php echo $statusFilter === 'property_owner' ? 'selected' : ''; ?>>Property Owner</option>
                            <option value="admin" <?php echo $statusFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </form>
            </div>

            <div class="table-responsive">
                <?php if (count($users) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['location']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $user['id']; ?>" class="action-btn view-btn" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $user['id']; ?>" class="action-btn edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $user['id']; ?>" class="action-btn delete-btn" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-users-slash"></i>
                        </div>
                        <h3>No users found</h3>
                        <p>Try adjusting your search or filter criteria</p>
                        <a href="?" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-sync-alt"></i> Reset Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalUsers > $perPage): ?>
                <div class="pagination">
                    <?php
                    $totalPages = ceil($totalUsers / $perPage);
                    $visiblePages = 5;
                    $startPage = max(1, min($page - floor($visiblePages / 2), $totalPages - $visiblePages + 1));
                    $endPage = min($startPage + $visiblePages - 1, $totalPages);
                    
                    if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif;
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor;
                    
                    if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>