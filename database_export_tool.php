<?php
/**
 * Online Accommodation System - Database Export Tool
 * This script exports all database tables and data
 */

require_once 'config/database.php';

class DatabaseExporter {
    private $db;
    private $dbName = 'online_accommodations_system';
    
    public function __construct() {
        try {
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get all tables in the database
     */
    public function getAllTables() {
        try {
            $stmt = $this->db->query("SHOW TABLES");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            return $tables;
        } catch (Exception $e) {
            echo "Error getting tables: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    /**
     * Export all data from all tables
     */
    public function exportAllData() {
        $tables = $this->getAllTables();
        $exportData = [];
        
        echo "Starting database export...\n";
        echo "Database: {$this->dbName}\n";
        echo "Found " . count($tables) . " tables\n\n";
        
        foreach ($tables as $table) {
            echo "Exporting table: $table\n";
            $data = $this->exportTableData($table);
            $exportData[$table] = $data;
            echo "Exported " . count($data) . " rows from $table\n\n";
        }
        
        return $exportData;
    }
    
    /**
     * Export data from a specific table
     */
    public function exportTableData($tableName) {
        try {
            $stmt = $this->db->query("SELECT * FROM `$tableName`");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo "Error exporting table $tableName: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    /**
     * Get table structure
     */
    public function getTableStructure($tableName) {
        try {
            $stmt = $this->db->query("DESCRIBE `$tableName`");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo "Error getting structure for table $tableName: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    /**
     * Export to JSON file
     */
    public function exportToJSON($filename = null) {
        if (!$filename) {
            $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.json';
        }
        
        $data = $this->exportAllData();
        $json = json_encode($data, JSON_PRETTY_PRINT);
        
        if (file_put_contents($filename, $json)) {
            echo "Database exported to: $filename\n";
            echo "File size: " . formatBytes(filesize($filename)) . "\n";
            return true;
        } else {
            echo "Failed to write export file: $filename\n";
            return false;
        }
    }
    
    /**
     * Export to CSV files (one file per table)
     */
    public function exportToCSV($directory = 'database_export_csv') {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $tables = $this->getAllTables();
        $exportedFiles = [];
        
        foreach ($tables as $table) {
            $filename = $directory . '/' . $table . '.csv';
            $data = $this->exportTableData($table);
            
            if (!empty($data)) {
                $file = fopen($filename, 'w');
                
                // Write header
                fputcsv($file, array_keys($data[0]));
                
                // Write data
                foreach ($data as $row) {
                    fputcsv($file, $row);
                }
                
                fclose($file);
                $exportedFiles[] = $filename;
                echo "Exported $table to: $filename\n";
            }
        }
        
        echo "\nExported " . count($exportedFiles) . " CSV files to: $directory/\n";
        return $exportedFiles;
    }
    
    /**
     * Generate SQL dump
     */
    public function exportToSQL($filename = null) {
        if (!$filename) {
            $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        $tables = $this->getAllTables();
        $sql = "-- Online Accommodation System Database Export\n";
        $sql .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: {$this->dbName}\n\n";
        
        $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql .= "SET AUTOCOMMIT = 0;\n";
        $sql .= "START TRANSACTION;\n";
        $sql .= "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
            $sql .= $this->generateTableSQL($table);
        }
        
        $sql .= "COMMIT;\n";
        
        if (file_put_contents($filename, $sql)) {
            echo "SQL dump exported to: $filename\n";
            echo "File size: " . formatBytes(filesize($filename)) . "\n";
            return true;
        } else {
            echo "Failed to write SQL dump: $filename\n";
            return false;
        }
    }
    
    /**
     * Generate SQL for a specific table
     */
    private function generateTableSQL($tableName) {
        $sql = "-- Table: $tableName\n";
        
        // Get CREATE TABLE statement
        try {
            $stmt = $this->db->query("SHOW CREATE TABLE `$tableName`");
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            $sql .= $createTable['Create Table'] . ";\n\n";
        } catch (Exception $e) {
            $sql .= "-- Error getting CREATE TABLE for $tableName: " . $e->getMessage() . "\n\n";
        }
        
        // Get data
        $data = $this->exportTableData($tableName);
        
        if (!empty($data)) {
            $sql .= "-- Data for table: $tableName\n";
            
            foreach ($data as $row) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . addslashes($value) . "'";
                    }
                }
                
                $columns = '`' . implode('`, `', array_keys($row)) . '`';
                $sql .= "INSERT INTO `$tableName` ($columns) VALUES (" . implode(', ', $values) . ");\n";
            }
            
            $sql .= "\n";
        }
        
        return $sql;
    }
    
    /**
     * Get database statistics
     */
    public function getDatabaseStats() {
        $stats = [];
        
        // Get table count
        $tables = $this->getAllTables();
        $stats['table_count'] = count($tables);
        
        // Get row counts for each table
        $stats['tables'] = [];
        $totalRows = 0;
        
        foreach ($tables as $table) {
            try {
                $stmt = $this->db->query("SELECT COUNT(*) as count FROM `$table`");
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                $stats['tables'][$table] = $count;
                $totalRows += $count;
            } catch (Exception $e) {
                $stats['tables'][$table] = 'Error: ' . $e->getMessage();
            }
        }
        
        $stats['total_rows'] = $totalRows;
        
        // Get database size
        try {
            $stmt = $this->db->query("
                SELECT 
                    ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = '{$this->dbName}'
            ");
            $size = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['database_size_mb'] = $size['size_mb'];
        } catch (Exception $e) {
            $stats['database_size_mb'] = 'Error: ' . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Display database statistics
     */
    public function displayStats() {
        $stats = $this->getDatabaseStats();
        
        echo "=== DATABASE STATISTICS ===\n";
        echo "Database: {$this->dbName}\n";
        echo "Total Tables: {$stats['table_count']}\n";
        echo "Total Rows: {$stats['total_rows']}\n";
        echo "Database Size: {$stats['database_size_mb']} MB\n\n";
        
        echo "=== TABLE ROW COUNTS ===\n";
        foreach ($stats['tables'] as $table => $count) {
            echo sprintf("%-30s: %s\n", $table, $count);
        }
        echo "\n";
    }
}

/**
 * Helper function to format bytes
 */
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "Online Accommodation System - Database Export Tool\n";
    echo "==================================================\n\n";
    
    $exporter = new DatabaseExporter();
    
    // Display statistics
    $exporter->displayStats();
    
    // Ask user what they want to export
    echo "Choose export format:\n";
    echo "1. JSON\n";
    echo "2. CSV (multiple files)\n";
    echo "3. SQL dump\n";
    echo "4. All formats\n";
    echo "Enter choice (1-4): ";
    
    $choice = trim(fgets(STDIN));
    
    switch ($choice) {
        case '1':
            $exporter->exportToJSON();
            break;
        case '2':
            $exporter->exportToCSV();
            break;
        case '3':
            $exporter->exportToSQL();
            break;
        case '4':
            echo "Exporting to all formats...\n\n";
            $exporter->exportToJSON();
            echo "\n";
            $exporter->exportToCSV();
            echo "\n";
            $exporter->exportToSQL();
            break;
        default:
            echo "Invalid choice. Exporting to JSON by default.\n";
            $exporter->exportToJSON();
    }
    
    echo "\nExport completed!\n";
} else {
    // Web interface
    $exporter = new DatabaseExporter();
    
    if (isset($_GET['action'])) {
        header('Content-Type: text/plain');
        
        switch ($_GET['action']) {
            case 'stats':
                $exporter->displayStats();
                break;
            case 'json':
                $exporter->exportToJSON();
                break;
            case 'csv':
                $exporter->exportToCSV();
                break;
            case 'sql':
                $exporter->exportToSQL();
                break;
            default:
                echo "Invalid action. Available actions: stats, json, csv, sql\n";
        }
    } else {
        // Show web interface
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Database Export Tool</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .button { display: inline-block; padding: 10px 20px; margin: 10px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; }
                .button:hover { background: #005a87; }
                .stats { background: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>Online Accommodation System - Database Export Tool</h1>
            
            <div class="stats">
                <h3>Database Statistics</h3>
                <pre><?php $exporter->displayStats(); ?></pre>
            </div>
            
            <h3>Export Options</h3>
            <a href="?action=json" class="button">Export to JSON</a>
            <a href="?action=csv" class="button">Export to CSV</a>
            <a href="?action=sql" class="button">Export to SQL</a>
            <a href="?action=stats" class="button">View Stats</a>
            
            <h3>Usage Instructions</h3>
            <p>Click on any export button above to download your database in the selected format.</p>
            <p>For command line usage, run: <code>php database_export_tool.php</code></p>
        </body>
        </html>
        <?php
    }
}
?>
