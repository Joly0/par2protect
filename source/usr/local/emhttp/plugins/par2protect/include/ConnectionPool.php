<?php
namespace Par2Protect;

class ConnectionPool {
    private static $instance = null;
    private static $initialized = false;
    
    private $logger;
    private $config;
    private $connections = [];
    private $maxConnections = 5;
    private $minConnections = 1;
    private $maxIdleTime = 30;
    private $healthCheckInterval = 60;
    private $lastHealthCheck = 0;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        
        // Load configuration
        $this->loadConfig();
        
        // Initialize minimum connections
        $this->initializeConnections();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            
            if (!self::$initialized && basename($_SERVER['SCRIPT_NAME']) === 'template.php') {
                self::$instance->logger->info("ConnectionPool initialized", [
                    'max_connections' => self::$instance->maxConnections,
                    'min_connections' => self::$instance->minConnections,
                    'idle_timeout' => self::$instance->maxIdleTime
                ]);
                self::$initialized = true;
            }
        }
        return self::$instance;
    }
    
    private function loadConfig() {
        $this->maxConnections = $this->config->get('database.max_connections', 5);
        $this->minConnections = $this->config->get('database.min_connections', 1);
        $this->maxIdleTime = $this->config->get('database.max_idle_time', 30);
        $this->healthCheckInterval = $this->config->get('database.health_check_interval', 60);
    }
    
    private function initializeConnections() {
        while (count($this->connections) < $this->minConnections) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->connections[] = [
                    'connection' => $connection,
                    'last_used' => time(),
                    'in_use' => false
                ];
            }
        }
    }
    
    private function createConnection() {
        try {
            $connection = new \SQLite3(DatabaseManager::DB_PATH, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
            
            if (!$connection) {
                throw new Exceptions\DatabaseConnectionException("Failed to create database connection");
            }
            
            // Configure connection
            $connection->enableExceptions(true);
            $connection->busyTimeout(5000); // 5 seconds
            
            // Set pragmas for better concurrency and performance
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA journal_mode = WAL');
            $connection->exec('PRAGMA synchronous = NORMAL');
            $connection->exec('PRAGMA wal_autocheckpoint = 1000');
            $connection->exec('PRAGMA cache_size = -2000');
            
            return $connection;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to create database connection", [
                'error' => $e->getMessage(),
                'exception' => $e
            ]);
            return null;
        }
    }
    
    public function getConnection() {
        $this->performHealthCheck();
        
        // Look for an available connection
        foreach ($this->connections as &$conn) {
            if (!$conn['in_use']) {
                // Check if connection is still valid
                if ($this->isConnectionHealthy($conn['connection'])) {
                    $conn['in_use'] = true;
                    $conn['last_used'] = time();
                    return $conn['connection'];
                } else {
                    // Connection is dead, remove it
                    $this->closeConnection($conn['connection']);
                    continue;
                }
            }
        }
        
        // Create new connection if under limit
        if (count($this->connections) < $this->maxConnections) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->connections[] = [
                    'connection' => $connection,
                    'last_used' => time(),
                    'in_use' => true
                ];
                return $connection;
            }
        }
        
        // No connections available
        throw new Exceptions\DatabaseConnectionException("No available connections in pool");
    }
    
    public function releaseConnection($connection) {
        foreach ($this->connections as &$conn) {
            if ($conn['connection'] === $connection) {
                $conn['in_use'] = false;
                $conn['last_used'] = time();
                break;
            }
        }
    }
    
    private function isConnectionHealthy($connection) {
        try {
            // Simple query to test connection
            $result = $connection->query('SELECT 1');
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function performHealthCheck() {
        $now = time();
        
        // Only check periodically
        if (($now - $this->lastHealthCheck) < $this->healthCheckInterval) {
            return;
        }
        
        $this->lastHealthCheck = $now;
        $this->logger->debug("Performing connection pool health check");
        
        // Remove dead connections and those idle for too long
        foreach ($this->connections as $key => $conn) {
            $idle = $now - $conn['last_used'];
            
            // Keep minimum connections even if idle
            if (count($this->connections) > $this->minConnections) {
                if ($idle > $this->maxIdleTime || !$this->isConnectionHealthy($conn['connection'])) {
                    $this->closeConnection($conn['connection']);
                    unset($this->connections[$key]);
                }
            } else if (!$this->isConnectionHealthy($conn['connection'])) {
                // Always replace unhealthy connections
                $this->closeConnection($conn['connection']);
                unset($this->connections[$key]);
            }
        }
        
        // Reindex array after removing elements
        $this->connections = array_values($this->connections);
        
        // Ensure minimum connections
        $this->initializeConnections();
        
        $this->logger->debug("Connection pool health check completed", [
            'total_connections' => count($this->connections),
            'active_connections' => count(array_filter($this->connections, fn($c) => $c['in_use']))
        ]);
    }
    
    private function closeConnection($connection) {
        try {
            $connection->close();
        } catch (\Exception $e) {
            // Ignore errors when closing
        }
    }
    
    public function getPoolStats() {
        $active = 0;
        foreach ($this->connections as $conn) {
            if ($conn['in_use']) {
                $active++;
            }
        }
        
        return [
            'total_connections' => count($this->connections),
            'active_connections' => $active,
            'available_connections' => count($this->connections) - $active,
            'max_connections' => $this->maxConnections,
            'min_connections' => $this->minConnections
        ];
    }
    
    public function __destruct() {
        foreach ($this->connections as $conn) {
            $this->closeConnection($conn['connection']);
        }
    }
}