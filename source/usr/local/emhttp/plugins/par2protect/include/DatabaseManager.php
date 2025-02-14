<?php
namespace Par2Protect;

class DatabaseManager {
    private static $instance = null;
    private $currentConnection = null;
    private $transactionLevel = 0;
    private $maxRetries = 5;
    private $retryDelay = 500; // milliseconds
    private $logger;
    private $pool;
    
    const DB_PATH = '/boot/config/plugins/par2protect/par2protect.db';
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->pool = ConnectionPool::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get database connection with retry logic
     */
    private function getConnection() {
        // If in a transaction, keep using the same connection
        if ($this->transactionLevel > 0 && $this->currentConnection !== null) {
            return $this->currentConnection;
        }
        
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $this->logger->debug("Getting database connection from pool", [
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries
                ]);
                
                // Get connection from pool
                $connection = $this->pool->getConnection();
                
                // Store connection if starting a transaction
                if ($this->transactionLevel > 0) {
                    $this->currentConnection = $connection;
                }
                
                return $connection;
                
            } catch (\Exception $e) {
                $lastError = $e;
                $this->logger->warning("Failed to get connection from pool", [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                
                // Wait before retry with exponential backoff
                if ($attempt < $this->maxRetries - 1) {
                    $delay = $this->retryDelay * pow(2, $attempt);
                    usleep($delay * 1000); // Convert to microseconds
                }
                
                $attempt++;
            }
        }
        
        // All attempts failed
        throw new Exceptions\DatabaseConnectionException(
            "Failed to get database connection after {$this->maxRetries} attempts: " .
            ($lastError ? $lastError->getMessage() : 'Unknown error')
        );
    }
    
    /**
     * Execute query with retry logic
     */
    public function query($sql, $params = []) {
        $attempt = 0;
        $lastError = null;
        $connection = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $connection = $this->getConnection();
                
                if (!empty($params)) {
                    $stmt = $connection->prepare($sql);
                    if (!$stmt) {
                        throw new Exceptions\DatabaseQueryException(
                            "Failed to prepare statement: " . $connection->lastErrorMsg(),
                            $sql
                        );
                    }
                    
                    foreach ($params as $key => $value) {
                        $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                        $stmt->bindValue($key, $value, $type);
                    }
                    
                    $result = $stmt->execute();
                } else {
                    $result = $connection->query($sql);
                }
                
                if ($result === false) {
                    throw new Exceptions\DatabaseQueryException(
                        "Query failed: " . $connection->lastErrorMsg(),
                        $sql
                    );
                }
                
                // Release connection if not in transaction
                if ($this->transactionLevel === 0) {
                    $this->pool->releaseConnection($connection);
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $lastError = $e;
                $this->logger->warning("Query attempt failed", [
                    'attempt' => $attempt + 1,
                    'sql' => $sql,
                    'error' => $e->getMessage()
                ]);
                
                // Release connection on error if not in transaction
                if ($this->transactionLevel === 0 && $connection !== null) {
                    $this->pool->releaseConnection($connection);
                }
                
                // If database is locked, retry
                if (strpos($e->getMessage(), 'database is locked') !== false) {
                    if ($attempt < $this->maxRetries - 1) {
                        $delay = $this->retryDelay * pow(2, $attempt);
                        usleep($delay * 1000);
                        $attempt++;
                        continue;
                    }
                }
                
                if ($e instanceof Exceptions\DatabaseException) {
                    throw $e;
                }
                
                throw new Exceptions\DatabaseQueryException(
                    "Query failed: " . $e->getMessage(),
                    $sql,
                    null,
                    null,
                    0,
                    $e
                );
            }
        }
        
        throw new Exceptions\DatabaseQueryException(
            "Query failed after {$this->maxRetries} attempts: " .
            ($lastError ? $lastError->getMessage() : 'Unknown error'),
            $sql
        );
    }
    
    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback) {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $this->beginTransaction();
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (\Exception $e) {
                $lastError = $e;
                $this->rollback();
                
                // If database is locked, retry with exponential backoff
                if (strpos($e->getMessage(), 'database is locked') !== false) {
                    $this->logger->warning("Transaction retry due to lock", [
                        'attempt' => $attempt + 1,
                        'max_retries' => $this->maxRetries,
                        'next_delay' => $this->retryDelay * pow(2, $attempt)
                    ]);
                    
                    $delay = $this->retryDelay * pow(2, $attempt);
                    usleep($delay * 1000);
                    $attempt++;
                    continue;
                }
                
                if ($e instanceof Exceptions\DatabaseException) {
                    throw $e;
                }
                
                throw new Exceptions\DatabaseTransactionException(
                    "Transaction failed: " . $e->getMessage(),
                    'rollback',
                    null,
                    null,
                    0,
                    $e
                );
            }
        }
        
        // All retries failed
        throw new Exceptions\DatabaseTransactionException(
            "Transaction failed after {$this->maxRetries} attempts: " .
            ($lastError ? $lastError->getMessage() : 'Unknown error'),
            'retry_exhausted',
            null,
            ['max_retries' => $this->maxRetries],
            0,
            $lastError
        );
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        if ($this->transactionLevel === 0) {
            // Get a dedicated connection for the transaction
            $this->currentConnection = $this->getConnection();
            $this->query('BEGIN IMMEDIATE TRANSACTION');
        }
        $this->transactionLevel++;
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        if ($this->transactionLevel === 1) {
            try {
                $this->query('COMMIT');
            } finally {
                // Release connection back to pool
                if ($this->currentConnection !== null) {
                    $this->pool->releaseConnection($this->currentConnection);
                    $this->currentConnection = null;
                }
            }
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        if ($this->transactionLevel === 1) {
            try {
                $this->query('ROLLBACK');
            } finally {
                // Release connection back to pool
                if ($this->currentConnection !== null) {
                    $this->pool->releaseConnection($this->currentConnection);
                    $this->currentConnection = null;
                }
            }
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->currentConnection !== null) {
            // Release connection back to pool
            $this->pool->releaseConnection($this->currentConnection);
            $this->currentConnection = null;
            $this->transactionLevel = 0;
        }
    }
    
    /**
     * Check if connected
     */
    public function isConnected() {
        return $this->currentConnection !== null;
    }
    
    /**
     * Get connection pool statistics
     */
    public function getPoolStats() {
        return $this->pool->getPoolStats();
    }
    
    /**
     * Get all rows from result
     */
    public function fetchAll($result) {
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }
    
    /**
     * Get single row from result
     */
    public function fetchOne($result) {
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    /**
     * Get single value from result
     */
    public function fetchValue($result) {
        $row = $result->fetchArray(SQLITE3_NUM);
        return $row ? $row[0] : null;
    }
    
    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct() {
        $this->close();
    }
}