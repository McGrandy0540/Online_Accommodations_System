<?php

class Database {
    private $host = 'localhost';
    private $db_name = 'online_accommodations_system';
    private $username = 'root';
    private $password = 'mcgrandy0408';
    private $conn;

    // Database connection
    public function connect() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name}", 
                $this->username, 
                $this->password
            );
            
            // Set PDO to throw exceptions on error
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Optional: Set character encoding
            $this->conn->exec("SET NAMES utf8");

        } catch(PDOException $e) {
            // Log error to file in production
            error_log("Database Connection Error: " . $e->getMessage());
            
            // Throw exception instead of die() to allow proper error handling
            throw new Exception("Database Connection Error: " . $e->getMessage());
        }

        return $this->conn;
    }

    // Singleton instance
    public static function getInstance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new Database();
        }
        return $instance->connect();
    }
}
?>
