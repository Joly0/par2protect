<?php
namespace Par2Protect\Core;

use Par2Protect\Core\Exceptions\DatabaseException;

/**
 * QueueDatabase Class
 * 
 * This class provides database functionality for queue operations.
 * It uses a separate SQLite database file stored in /tmp to prevent locking issues.
 */
class QueueDatabase {
    private static $instance = null;
    private $db = null;
    private $inTransaction = false;
    private $logger;
    private $config;
    private $dbPath;
    
    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        
        // Set database path in /tmp
        $this->dbPath = '/tmp/par2protect/queue/queue.db';
        
        // Create directory if it doesn't exist
        $dbDir = dirname($this->dbPath);
        if (!is_dir($dbDir)) {
            if (!@mkdir($dbDir, 0755, true)) {
                $this->logger->error("Failed to create queue database directory: {$dbDir}");
                throw new DatabaseException("Failed to create queue database directory: {$dbDir}");
            }
        }
        
        // Connect to database
        try {
            $this->db = new \SQLite3($this->dbPath);
            $this->db->enableExceptions(true);
            
            // Set pragmas for better performance
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA synchronous = NORMAL');
            $this->db->exec('PRAGMA temp_store = MEMORY');
            $this->db->exec('PRAGMA cache_size = 5000');
            
            $this->logger->debug("Connected to queue database: {$this->dbPath}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to connect to queue database", [
                'path' => $this->dbPath,
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException("Failed to connect to queue database: " . $e->getMessage());
        }
    }
    
    /**
     * Get singleton instance
     *
     * @return QueueDatabase
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize queue table
     *
     * @return void
     */
    public function initializeQueueTable() {
        try {
            // Create operation_queue table if it doesn't exist
            if (!$this->tableExists('operation_queue')) {
                $this->logger->debug("Creating operation_queue table in queue database");
                
                $this->query("
                    CREATE TABLE operation_queue (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        operation_type TEXT NOT NULL,
                        parameters TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'pending',
                        created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                        started_at DATETIME,
                        completed_at DATETIME,
                        updated_at DATETIME DEFAULT (datetime('now', 'localtime')),
                        result TEXT,
                        pid INTEGER
                    )
                ");
                
                // Create indexes
                $this->query("CREATE INDEX idx_status ON operation_queue(status)");
                $this->query("CREATE INDEX idx_operation_type ON operation_queue(operation_type)");
                $this->query("CREATE INDEX idx_created_at ON operation_queue(created_at)");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize queue table", [
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException("Failed to initialize queue table: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a table exists
     *
     * @param string $tableName Table name
     * @return bool
     */
    public function tableExists($tableName) {
        try {
            $result = $this->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=:name",
                [':name' => $tableName]
            );
            $row = $this->fetchOne($result);
            return $row !== false;
        } catch (\Exception $e) {
            $this->logger->error("Failed to check if table exists", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Execute a query
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \SQLite3Result|bool
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                throw new DatabaseException("Failed to prepare statement: " . $this->db->lastErrorMsg());
            }
            
            // Bind parameters
            foreach ($params as $param => $value) {
                $type = \SQLITE3_TEXT;
                
                if (is_int($value)) {
                    $type = \SQLITE3_INTEGER;
                } elseif (is_float($value)) {
                    $type = \SQLITE3_FLOAT;
                } elseif (is_null($value)) {
                    $type = \SQLITE3_NULL;
                }
                
                $stmt->bindValue($param, $value, $type);
            }
            
            // Execute query
            $result = $stmt->execute();
            
            if ($result === false) {
                throw new DatabaseException("Failed to execute query: " . $this->db->lastErrorMsg());
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Database query error", [
                'sql' => $sql,
                'params' => json_encode($params),
                'error' => $e->getMessage()
            ]);
            throw new DatabaseException("Database query error: " . $e->getMessage());
        }
    }
    
    /**
     * Fetch a single row
     *
     * @param \SQLite3Result $result Query result
     * @return array|false
     */
    public function fetchOne($result) {
        if ($result === false || $result === true) {
            return false;
        }
        
        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $result->finalize();
        
        return $row !== false ? $row : false;
    }
    
    /**
     * Fetch all rows
     *
     * @param \SQLite3Result $result Query result
     * @return array
     */
    public function fetchAll($result) {
        if ($result === false || $result === true) {
            return [];
        }
        
        $rows = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        
        $result->finalize();
        
        return $rows;
    }
    
    /**
     * Begin a transaction
     *
     * @return bool
     */
    public function beginTransaction() {
        if ($this->inTransaction) {
            $this->logger->warning("Transaction already in progress");
            return false;
        }
        
        $this->db->exec('BEGIN TRANSACTION');
        $this->inTransaction = true;
        
        return true;
    }
    
    /**
     * Commit a transaction
     *
     * @return bool
     */
    public function commit() {
        if (!$this->inTransaction) {
            $this->logger->warning("No transaction in progress");
            return false;
        }
        
        $this->db->exec('COMMIT');
        $this->inTransaction = false;
        
        return true;
    }
    
    /**
     * Rollback a transaction
     *
     * @return bool
     */
    public function rollback() {
        if (!$this->inTransaction) {
            $this->logger->warning("No transaction in progress");
            return false;
        }
        
        $this->db->exec('ROLLBACK');
        $this->inTransaction = false;
        
        return true;
    }
    
    /**
     * Get last insert ID
     *
     * @return int
     */
    public function lastInsertId() {
        return $this->db->lastInsertRowID();
    }
    
    /**
     * Get number of changes from the last operation
     *
     * @return int
     */
    public function changes() {
        return $this->db->changes();
    }
    
    /**
     * Close database connection
     *
     * @return void
    /**
     * Close database connection
     *
     * @return void
     */
    public function close() {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}