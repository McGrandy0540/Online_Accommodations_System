<?php
require_once 'config/database.php';

try {
    $pdo = Database::getInstance();
    
    // Create maintenance_messages table
    $sql = "CREATE TABLE IF NOT EXISTS maintenance_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        maintenance_request_id INT NOT NULL,
        sender_id INT NOT NULL,
        sender_type ENUM('student', 'owner') NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_maintenance_request (maintenance_request_id),
        INDEX idx_sender (sender_id),
        INDEX idx_created_at (created_at)
    )";
    
    $pdo->exec($sql);
    echo "maintenance_messages table created successfully!<br>";
    
    // Add a column to track unread messages count in maintenance_requests table
    try {
        $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN unread_messages_count INT DEFAULT 0");
        echo "Added unread_messages_count column to maintenance_requests table!<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "unread_messages_count column already exists in maintenance_requests table.<br>";
        } else {
            throw $e;
        }
    }
    
    echo "<br>Database setup completed successfully!";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
