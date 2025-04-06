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
    // private static $instance = null; // Removed for DI
    private $db = null;
    private $inTransaction = false;
    private $logger;
    private $config;
    private $dbPath;
    private $maxRetries = 5; // Default max retries
    private $initialRetryDelay = 50; // Default initial delay (ms)
    private $maxRetryDelay = 1000; // Default max delay (ms)
    
    /**
     * Private constructor to enforce singleton pattern
     */
    // Make constructor public and inject dependencies
    public function __construct(Logger $logger, Config $config) {
        $this->logger = $logger;
        $this->config = $config;

        // Get retry configuration (use same keys as main DB for consistency)
        $this->maxRetries = $this->config->get('database.max_retries', $this->maxRetries);
        $this->initialRetryDelay = $this->config->get('database.initial_retry_delay', $this->initialRetryDelay);
        $this->maxRetryDelay = $this->config->get('database.max_retry_delay', $this->maxRetryDelay);
        
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
            
            // Queue database connections are not logged to reduce noise
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
    // Removed getInstance() method
    
    /**
     * Initialize queue table
     *
     * @return void
     */
    public function initializeQueueTable() {
        try {
            // Create operation_queue table if it doesn't exist
            if (!$this->tableExists('operation_queue')) {
                // Table creation is not logged to reduce noise
                
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
    // Updated query method to use retry logic
    public function query($sql, $params = []) {
        return $this->queryWithRetry($sql, $params);
    }

    /**
     * Execute SQL query with retry mechanism for database locked errors
     * (Copied from Database class for consistency)
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param int $retryCount Current retry count (internal use)
     * @param int $delay Current delay in milliseconds (internal use)
     * @return \SQLite3Result|bool
     */
    private function queryWithRetry($sql, $params = [], $retryCount = 0, $delay = null) {
        // Set initial delay if not provided
        if ($delay === null) {
            $delay = $this->initialRetryDelay;
        }

        try {
            return $this->executeQuery($sql, $params);
        } catch (DatabaseException $e) {
            // Check if this is a database locked error
            if (strpos($e->getMessage(), 'database is locked') !== false && $retryCount < $this->maxRetries) {
                // Calculate next delay with exponential backoff (but cap at max delay)
                $nextDelay = min($delay * 2, $this->maxRetryDelay);

                $this->logger->warning("QueueDatabase locked, retrying operation", [
                    'retry_count' => $retryCount + 1,
                    'max_retries' => $this->maxRetries,
                    'delay_ms' => $delay
                ]);

                usleep($delay * 1000); // Convert milliseconds to microseconds
                return $this->queryWithRetry($sql, $params, $retryCount + 1, $nextDelay);
            }
            // Re-throw original exception if not a lock error or retries exceeded
            throw $e;
        } catch (\Exception $e) {
             // Catch other potential exceptions during executeQuery
             $this->logger->error("QueueDatabase query error", [
                 'sql' => $sql,
                 'params' => json_encode($params),
                 'error' => $e->getMessage()
             ]);
             throw new DatabaseException("QueueDatabase query error: " . $e->getMessage());
        }
    }

    /**
     * Execute SQL query (internal implementation)
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \SQLite3Result|bool
     */
    private function executeQuery($sql, $params = []) {
        // This method contains the actual query execution logic
        // Extracted from the original query() method
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
            // Check if the error is 'database is locked' to allow retry
            $lastError = $this->db->lastErrorMsg();
            if (strpos($lastError, 'database is locked') !== false) {
                 throw new DatabaseException($lastError); // Throw specific exception for retry logic
            } else {
                 throw new DatabaseException("Failed to execute query: " . $lastError);
            }
        }

        return $result;
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
     * Check if currently in a transaction
     *
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->inTransaction;
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