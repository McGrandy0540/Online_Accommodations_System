<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/database.php';

try {
    $db = Database::getInstance();
    
    // Validate input
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Base query with security filtering
    $sql = "SELECT p.*, u.username as owner_name 
            FROM property p
            JOIN users u ON p.owner_id = u.id
            WHERE p.deleted = 0 AND p.approved = 1";
    
    $params = [];
    
    // Add search conditions
    if (!empty($query)) {
        $sql .= " AND MATCH(p.property_name, p.description, p.location) AGAINST(:query IN BOOLEAN MODE)";
        $params[':query'] = $query;
    }
    
    // Add filters
    $filters = ['category_id', 'location', 'bedrooms', 'bathrooms', 'min_price', 'max_price'];
    foreach ($filters as $filter) {
        if (isset($_GET[$filter]) && is_numeric($_GET[$filter])) {
            $column = str_replace('min_', '', str_replace('max_', '', $filter));
            $operator = strpos($filter, 'min_') === 0 ? '>=' : (strpos($filter, 'max_') === 0 ? '<=' : '=');
            $sql .= " AND p.$column $operator :$filter";
            $params[":$filter"] = $_GET[$filter];
        }
    }
    
    // Count total results
    $countStmt = $db->prepare(str_replace('p.*, u.username as owner_name', 'COUNT(*) as total', $sql));
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    // Add pagination and sorting
    $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    // Execute query
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $paramType);
    }
    $stmt->execute();
    
    // Format results
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include image URLs
    foreach ($results as &$property) {
        $imgStmt = $db->prepare("SELECT image_url FROM property_images WHERE property_id = ? LIMIT 1");
        $imgStmt->execute([$property['id']]);
        $property['image_url'] = $imgStmt->fetchColumn() ?: 'default-property.jpg';
    }
    
    echo json_encode([
        'success' => true,
        'data' => $results,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'limit' => $limit
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>