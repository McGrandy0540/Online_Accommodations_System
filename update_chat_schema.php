<?php
require_once 'config/database.php';

try {
    $pdo = Database::getInstance();
    
    // Add new columns to chat_conversations table
    $sql = "ALTER TABLE chat_conversations 
            ADD COLUMN conversation_type ENUM('student_owner', 'owner_admin') DEFAULT 'student_owner',
            ADD COLUMN admin_id INT NULL,
            ADD FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE";
    
    $pdo->exec($sql);
    
    echo "Database schema updated successfully!\n";
    echo "Added conversation_type and admin_id columns to chat_conversations table.\n";
    
} catch (PDOException $e) {
    echo "Error updating database schema: " . $e->getMessage() . "\n";
}
?>
