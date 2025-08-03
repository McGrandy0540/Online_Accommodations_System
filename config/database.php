<?php

// Paystack configuration
define('PAYSTACK_SECRET_KEY', 'sk_test_9c3c7da0284defbf21404dd3faa9cc15ed571d8e');
define('PAYSTACK_PUBLIC_KEY', 'pk_test_db73c7228ff880b4a3d49593023b91a6a5b923c6');
define('PAYSTACK_CURRENCY', 'GHS');

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
            
            // Display user-friendly message
            die("Database Connection Error: " . $e->getMessage());
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

// Usage example:
// $db = Database::getInstance();
?>