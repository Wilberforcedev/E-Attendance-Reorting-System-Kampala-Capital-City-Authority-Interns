<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = getenv('EARS_DB_HOST') ?: '127.0.0.1';
        $this->db_name = getenv('EARS_DB_NAME') ?: 'ears_db';
        $this->username = getenv('EARS_DB_USER') ?: '';
        $this->password = getenv('EARS_DB_PASSWORD') ?: '';
    }

    public function getConnection() {
        $this->conn = null;

        if ($this->username === '' || $this->password === '') {
            error_log('EARS database configuration is incomplete.');
            throw new RuntimeException('Database service is not configured.');
        }

        try {
            $this->conn = new PDO(
                'mysql:host=' . $this->host . ';dbname=' . $this->db_name . ';charset=utf8mb4',
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $exception) {
            error_log('EARS database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('Database service is unavailable.');
        }

        return $this->conn;
    }
}
