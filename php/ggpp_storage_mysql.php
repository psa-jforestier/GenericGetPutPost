<?php

/**
 * Storage implementation using MySQL/MariaDB
 * TO BE MORE TESTED!!!
 */
class StorageMySQL extends Storage {
    private $pdo;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $dsn = $config['mysql']['dsn'];
        
        // Extract username and password from config if provided
        $username = $config['mysql']['username'] ?? null;
        $password = $config['mysql']['password'] ?? null;
        
        try {
            if ($username !== null && $password !== null) {
                $this->pdo = new PDO($dsn, $username, $password);
            } else {
                $this->pdo = new PDO($dsn);
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database connection failed: ' . $e->getMessage());
        }
        
        $this->initialize_storage();
    }

    private function initialize_storage() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS documents (
                    udi VARCHAR(15) PRIMARY KEY,
                    data LONGBLOB NOT NULL,
                    date_update DATETIME NOT NULL,
                    KEY idx_date_update (date_update)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limit (
                    client_rate_key VARCHAR(255) PRIMARY KEY,
                    rounded_time DATETIME NOT NULL,
                    count INT NOT NULL,
                    KEY idx_rounded_time (rounded_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database initialization failed: ' . $e->getMessage());
        }
    }

    public function document_exists($udi) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM documents WHERE udi = :udi");
            $stmt->execute([':udi' => $udi]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function delete_document($udi) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM documents WHERE udi = :udi");
            $stmt->execute([':udi' => $udi]);
            return true;
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function get_document($udi) {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM documents WHERE udi = :udi LIMIT 1");
            $stmt->execute([':udi' => $udi]);
            $data = $stmt->fetchColumn();
            if ($data === false) {
                return false;
            }
            return $data;
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function store_document($udi, $data) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO documents (udi, data, date_update) 
                VALUES (:udi, :data, :date_update)
                ON DUPLICATE KEY UPDATE 
                    data = VALUES(data),
                    date_update = VALUES(date_update)
            ");
            $stmt->execute([
                ':udi' => $udi,
                ':data' => $data,
                ':date_update' => date('Y-m-d H:i:s', time())
            ]);
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database insert/update failed: ' . $e->getMessage());
        }
    }

    public function get_request_count($client_rate_key, int $rounded_time) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT count, rounded_time FROM rate_limit 
                WHERE client_rate_key = :client_rate_key LIMIT 1
            ");
            $stmt->execute([':client_rate_key' => $client_rate_key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row === false) {
                return 0;
            }
            
            // Check if the stored rounded_time is still within the current period
            if ((int)$row['rounded_time'] < $rounded_time) {
                return 0;
            }
            
            return (int)$row['count'];
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function set_request_count($client_rate_key, int $rounded_time, int $count) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limit (client_rate_key, rounded_time, count) 
                VALUES (:client_rate_key, :rounded_time, :count)
                ON DUPLICATE KEY UPDATE 
                    rounded_time = VALUES(rounded_time),
                    count = VALUES(count)
            ");
            $stmt->execute([
                ':client_rate_key' => $client_rate_key,
                ':rounded_time' => $rounded_time,
                ':count' => $count
            ]);
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database insert/update failed: ' . $e->getMessage());
        }
    }
}
