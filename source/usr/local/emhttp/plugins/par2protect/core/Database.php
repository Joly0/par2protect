<?php
namespace Par2Protect\Core;

/**
 * Database class for handling database operations
 */
class Database {
    // private static $instance = null; // Removed for DI
    // private static $sharedConnection = null; // Removed, connection managed per instance
    private $db = null;
    private $logger = null;
    private $config = null;
    private $dbPath = '/boot/config/plugins/par2protect/par2protect.db';
    public $inTransaction = false;
    private $maxRetries = 5;
    private $initialRetryDelay = 50; // milliseconds
    private $maxRetryDelay = 1000; // milliseconds
    
    /**
     * Private constructor to prevent direct instantiation
     */
    // private static $connectionCount = 0; // Removed
    // private static $instanceCount = 0; // Removed

    // Make constructor public and inject dependencies
    public function __construct(Logger $logger, Config $config) {
        $this->logger = $logger;
        $this->config = $config;
        
        // Get database path from config
        $this->dbPath = $this->config->get('database.path', $this->dbPath);
        
        // Get retry configuration
        $this->maxRetries = $this->config->get('database.max_retries', $this->maxRetries);
        $this->initialRetryDelay = $this->config->get('database.initial_retry_delay', $this->initialRetryDelay);
        $this->maxRetryDelay = $this->config->get('database.max_retry_delay', $this->maxRetryDelay);
        
        // Create database directory if it doesn't exist
        $dbDir = dirname($this->dbPath);
        if (!is_dir($dbDir)) {
            if (!@mkdir($dbDir, 0755, true)) {
                $this->logger->error("Failed to create database directory: $dbDir");
                throw new Exceptions\DatabaseException("Failed to create database directory: $dbDir");
            }
        }
        
        // Connect to database
        $this->connect();
    }
    
    /**
     * Get singleton instance
     *
     * @return Database
     */
    // Removed getInstance() method
    
    /**
     * Connect to database
     *
     * @return void
     */
    private function connect() {
        try {
            // Removed shared connection logic - each instance connects independently
            // if (self::$sharedConnection !== null) { ... }
            
            // Removed connection counter
            // self::$connectionCount++;
            // Create new SQLite database connection
            $this->db = new \SQLite3($this->dbPath);
            
            // Removed shared connection logic
            // self::$sharedConnection = $this->db;
            
            // Set journal mode
            $journalMode = $this->config->get('database.journal_mode', 'WAL');
            $this->db->exec("PRAGMA journal_mode = $journalMode");
            
            // Set synchronous mode
            $synchronous = $this->config->get('database.synchronous', 'NORMAL');
            $this->db->exec("PRAGMA synchronous = $synchronous");
            
            // Set busy timeout
            $busyTimeout = $this->config->get('database.busy_timeout', 5000);
            $this->db->busyTimeout($busyTimeout);
            
            // Enable foreign keys
            $this->db->exec('PRAGMA foreign_keys = ON');
            
            // Database connections are not logged to reduce noise
        } catch (\Exception $e) {
            $this->logger->error("Database connection failed", [
                'path' => $this->dbPath,
                'error' => $e->getMessage(),
                // 'connection_count' => self::$connectionCount // Removed
            ]);
            
            throw new Exceptions\DatabaseException("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get SQLite instance
     *
     * @return \SQLite3
     */
    public function getSQLite() {
        return $this->db;
    }
    
    /**
     * Execute SQL query
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \SQLite3Result
     */
    public function query($sql, $params = []) {
        return $this->queryWithRetry($sql, $params);
    }
    
    /**
     * Execute SQL query with retry mechanism for database locked errors
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param int $retryCount Current retry count (internal use)
     * @param int $delay Current delay in milliseconds (internal use)
     * @return \SQLite3Result
     */
    private function queryWithRetry($sql, $params = [], $retryCount = 0, $delay = null) {
        // Set initial delay if not provided
        if ($delay === null) {
            $delay = $this->initialRetryDelay;
        }
        
        try {
            return $this->executeQuery($sql, $params);
        } catch (Exceptions\DatabaseException $e) {
            // Check if this is a database locked error
            if (strpos($e->getMessage(), 'database is locked') !== false && $retryCount < $this->maxRetries) {
                // Calculate next delay with exponential backoff (but cap at max delay)
                $nextDelay = min($delay * 2, $this->maxRetryDelay);
                
                $this->logger->warning("Database locked, retrying operation", [
                    'retry_count' => $retryCount + 1,
                    'max_retries' => $this->maxRetries,
                    'delay_ms' => $delay
                ]);
                
                usleep($delay * 1000); // Convert milliseconds to microseconds
                return $this->queryWithRetry($sql, $params, $retryCount + 1, $nextDelay);
            }
            throw $e;
        }
    }
    
    /**
     * Execute SQL query (internal implementation)
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \SQLite3Result
     */
    private function executeQuery($sql, $params = []) {
        try {
            // Prepare statement
            $stmt = $this->db->prepare($sql);
            
            if (!$stmt) {
                $this->logger->error("Failed to prepare SQL statement", [
                    'sql' => $sql,
                    'error' => $this->db->lastErrorMsg()
                ]);
                
                throw new Exceptions\DatabaseException("Failed to prepare SQL statement: " . $this->db->lastErrorMsg());
            }
            
            // Bind parameters
            foreach ($params as $param => $value) {
                $type = \SQLITE3_TEXT;
                
                if (is_int($value)) {
                    $type = \SQLITE3_INTEGER;
                } else if (is_float($value)) {
                    $type = \SQLITE3_FLOAT;
                } else if (is_null($value)) {
                    $type = \SQLITE3_NULL;
                }
                
                $stmt->bindValue($param, $value, $type);
            }
            
            // Execute statement
            $result = $stmt->execute();
            
            if (!$result) {
                $this->logger->error("Failed to execute SQL statement", [
                    'sql' => $sql,
                    'error' => $this->db->lastErrorMsg()
                ]);
                
                throw new Exceptions\DatabaseException("Failed to execute SQL statement: " . $this->db->lastErrorMsg());
            }
            
            return $result;
        } catch (Exceptions\DatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Database query failed", [
                'sql' => $sql,
                'error' => $e->getMessage()
            ]);
            
            throw new Exceptions\DatabaseException("Database query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Fetch all rows from result
     *
     * @param \SQLite3Result $result Query result
     * @return array
     */
    public function fetchAll($result) {
        $rows = [];
        
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        
        return $rows;
    }
    
    /**
     * Fetch one row from result
     *
     * @param \SQLite3Result $result Query result
     * @return array|null
     */
    public function fetchOne($result) {
        return $result->fetchArray(\SQLITE3_ASSOC);
    }
    
    /**
     * Begin transaction
     *
     * @return void
     */
    public function beginTransaction() {
        if ($this->inTransaction) {
            $this->logger->warning("Transaction already in progress");
            return;
        }
        
        $this->db->exec('BEGIN TRANSACTION');
        $this->inTransaction = true;
    }
    
    /**
     * Commit transaction
     *
     * @return void
     */
    public function commit() {
        if (!$this->inTransaction) {
            $this->logger->warning("No transaction in progress");
            return;
        }
        
        $this->db->exec('COMMIT');
        $this->inTransaction = false;
    }
    
    /**
     * Rollback transaction
     *
     * @return void
     */
    public function rollback() {
        if (!$this->inTransaction) {
            $this->logger->warning("No transaction in progress");
            return;
        }
        
        $this->db->exec('ROLLBACK');
        $this->inTransaction = false;
    }
    
    /**
     * Check if table exists
     *
     * @param string $tableName Table name
     * @return bool
     */
    public function tableExists($tableName) {
        $result = $this->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=:name",
            [':name' => $tableName]
        );
        
        return $this->fetchOne($result) !== false;
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
     * Get number of rows affected by the last SQL statement
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
     */
    public function close() {
        if ($this->db) {
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