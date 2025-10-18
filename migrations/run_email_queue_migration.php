<?php
/**
 * Migration Script: Create email_queue table
 * Run this script once to create the email_queue table for EmailJS notifications
 */

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getInstance();
    
    echo "Starting email_queue table migration...\n";
    
    // Read the SQL file
    $sql = file_get_contents(__DIR__ . '/create_email_queue_table.sql');
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0;
        }
    );
    
    // Execute each statement
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    
    echo "\n✓ Migration completed successfully!\n";
    echo "✓ email_queue table has been created.\n";
    echo "\nYou can now use the EmailJS notification system.\n";
    
} catch (PDOException $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
