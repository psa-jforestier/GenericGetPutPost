<?php

/**
 * Storage implementation using the sqlite
 */
class StorageSQLite extends Storage {
    private $pdo;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $dsn = $config['sqlite']['dsn'];
        try {
            $this->pdo = new PDO($dsn);
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
                    udi TEXT PRIMARY KEY,
                    data BLOB,
                    date_update TEXT,
                    date_creation TEXT,
                    date_access TEXT
                );
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limit (
                    client_rate_key TEXT PRIMARY KEY,
                    rounded_time TEXT,
                    count INTEGER
                );
            ");
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database initialization failed: ' . $e->getMessage());
        }
    }

    // Expose the PDO object for advanced usage if needed (command line interface)
    public function getStoragePHPObject() {
        return $this->pdo;
    }

    public function document_exists($udi) 
    {
        try {
            // prepare and execute the query
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM documents WHERE udi = :udi");
            $stmt->execute([':udi' => $udi]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function delete_document($udi) 
    {
        try {
            // prepare and execute the query
            $stmt = $this->pdo->prepare("DELETE FROM documents WHERE udi = :udi");
            $stmt->execute([':udi' => $udi]);
            return true;
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database delete failed: ' . $e->getMessage());
        }
    }

    public function get_document($udi) 
    {
        try {
            // prepare and execute the query
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
    public function store_document($udi, $data) 
    {
        try {
            // prepare and execute the query
            $stmt = $this->pdo->prepare("
                INSERT INTO documents (udi, data, date_update) 
                VALUES (:udi, :data, :date_update)
                ON CONFLICT(udi) DO UPDATE SET 
                    data = excluded.data,
                    date_update = excluded.date_update
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

    public function get_request_count($client_rate_key, int $rounded_time)
    {
        try {
            // prepare and execute the query
            $stmt = $this->pdo->prepare("SELECT count, rounded_time FROM rate_limit WHERE client_rate_key = :client_rate_key LIMIT 1");
            $stmt->execute([':client_rate_key' => $client_rate_key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return 0;
            }
            if (strtotime($row['rounded_time']) < $rounded_time) {
                return 0;
            }
            return (int)$row['count'];
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database query failed: ' . $e->getMessage());
        }
    }

    public function set_request_count($client_rate_key, int $rounded_time, int $count)
    {
        try {
            // prepare and execute the query
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limit (client_rate_key, rounded_time, count) 
                VALUES (:client_rate_key, :rounded_time, :count)
                ON CONFLICT(client_rate_key) DO UPDATE SET 
                    rounded_time = excluded.rounded_time,
                    count = excluded.count
            ");
            $stmt->execute([
                ':client_rate_key' => $client_rate_key,
                ':rounded_time' => date('Y-m-d H:i:s', $rounded_time),
                ':count' => $count
            ]);
        } catch (PDOException $e) {
            DIE_WITH_ERROR(500, 'Database insert/update failed: ' . $e->getMessage());
        }
    }

}
