<?php
require_once __DIR__ . '/../../../config/database.php';

try {
    $pdo = Database::getInstance();

    
    
    // Check if chat_typing_indicators table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'chat_typing_indicators'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Create the chat_typing_indicators table
        $sql = "CREATE TABLE chat_typing_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY (conversation_id, user_id)
        )";
        
        $pdo->exec($sql);
        echo "chat_typing_indicators table created successfully!\n";
    } else {
        echo "chat_typing_indicators table already exists.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>