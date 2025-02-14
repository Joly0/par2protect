<?php
namespace Par2Protect;

class Database {
    private static $db = null;
    private static $dbPath = '/boot/config/plugins/par2protect/par2protect.db';
    private static $currentVersion = 1;
    private static $timeout = 5000; // 5 seconds
    private static $busyTimeout = 10000; // 10 seconds
    private static $lastUsed = 0;
    private static $maxIdleTime = 30; // Close connection after 30 seconds of inactivity

    public static function getInstance() {
        $now = time();
        
        // Close idle connection
        if (self::$db !== null && ($now - self::$lastUsed) > self::$maxIdleTime) {
            self::closeConnection();
        }
        
        if (self::$db === null) {
            self::initializeDatabase();
        }
        
        self::$lastUsed = $now;
        return self::$db;
    }
    
    public static function closeConnection() {
        if (self::$db !== null) {
            // Close the database connection
            self::$db->close();
            self::$db = null;
            
            // Clean up WAL files if they exist
            $walFile = self::$dbPath . '-wal';
            $shmFile = self::$dbPath . '-shm';
            if (file_exists($walFile)) {
                @unlink($walFile);
            }
            if (file_exists($shmFile)) {
                @unlink($shmFile);
            }
        }
    }

    private static function runMigrations() {
        $currentVersion = self::$db->querySingle("PRAGMA user_version");
        
        // Migration for v1: Add basepath and par2_path columns
        if ($currentVersion < 1) {
            try {
                self::$db->exec('BEGIN TRANSACTION');
                
                // Check if columns exist first
                $columns = self::$db->querySingle("PRAGMA table_info(protected_items)", true);
                $hasBasepath = false;
                $hasPar2Path = false;
                
                foreach ($columns as $col) {
                    if ($col['name'] === 'basepath') $hasBasepath = true;
                    if ($col['name'] === 'par2_path') $hasPar2Path = true;
                }
                
                if (!$hasBasepath) {
                    self::$db->exec("ALTER TABLE protected_items ADD COLUMN basepath TEXT DEFAULT ''");
                }
                if (!$hasPar2Path) {
                    self::$db->exec("ALTER TABLE protected_items ADD COLUMN par2_path TEXT DEFAULT ''");
                }
                
                // Backfill existing data
                $result = self::$db->query("SELECT id, path FROM protected_items");
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $par2Path = dirname($row['path']) . '/.parity/' . basename($row['path']) . '.par2';
                    $basepath = dirname($par2Path);
                    
                    $stmt = self::$db->prepare("UPDATE protected_items SET basepath = :basepath, par2_path = :par2_path WHERE id = :id");
                    $stmt->bindValue(':basepath', $basepath);
                    $stmt->bindValue(':par2_path', $par2Path);
                    $stmt->bindValue(':id', $row['id']);
                    $stmt->execute();
                }
                
                self::$db->exec("PRAGMA user_version = 1");
                self::$db->exec('COMMIT');
                
            } catch (\Exception $e) {
                self::$db->exec('ROLLBACK');
                error_log("Migration failed: " . $e->getMessage());
                throw $e;
            }
        }
    }

    private static function initializeDatabase() {
        try {
            error_log("Initializing database");
            $dbDir = dirname(self::$dbPath);
            $createNew = !file_exists(self::$dbPath);
            
            error_log("Database path: " . self::$dbPath);
            error_log("Database directory: " . $dbDir);
            error_log("Create new database: " . ($createNew ? 'yes' : 'no'));
            
            // Ensure database directory exists with proper permissions
            if (!is_dir($dbDir)) {
                error_log("Creating database directory: " . $dbDir);
                if (!@mkdir($dbDir, 0755, true)) {
                    $error = error_get_last();
                    error_log("Failed to create database directory: " . $error['message']);
                    throw new \Exception("Failed to create database directory: " . $error['message']);
                }
                error_log("Database directory created successfully");
            }

            // Ensure directory is writable
            if (!is_writable($dbDir)) {
                if (!@chmod($dbDir, 0755)) {
                    error_log("Failed to make database directory writable: $dbDir");
                    throw new \Exception("Failed to make database directory writable: $dbDir");
                }
            }

            // Create or open database
            try {
                $logger = Logger::getInstance();
                $logger->debug("Opening database connection");
                
                self::$db = new \SQLite3(self::$dbPath, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
                if (!self::$db) {
                    throw new \Exception("Failed to create database connection: " . \SQLite3::lastErrorMsg());
                }
                
                self::$db->enableExceptions(true);
                
                // Set timeouts and optimize for concurrency
                self::$db->busyTimeout(self::$busyTimeout);
                
                // Configure database for reliability and concurrency
                self::$db->exec('PRAGMA foreign_keys = ON');
                self::$db->exec('PRAGMA journal_mode = DELETE'); // Use simpler journaling
                self::$db->exec('PRAGMA synchronous = FULL'); // Ensure data integrity
                self::$db->exec('PRAGMA locking_mode = EXCLUSIVE'); // Prevent concurrent access issues
                self::$db->exec('PRAGMA busy_timeout = ' . self::$busyTimeout);
                
                // Test database connection
                $test = self::$db->query('SELECT 1');
                if (!$test) {
                    error_log("Database connection test failed: " . self::$db->lastErrorMsg());
                    throw new \Exception("Database connection test failed");
                }
                
                error_log("Database connection established successfully: " . self::$dbPath);
            } catch (\Exception $e) {
                error_log("Failed to open database: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                throw new \Exception("Failed to open database: " . $e->getMessage());
            }
            
            if ($createNew) {
                error_log("Creating new database schema");
                self::createInitialSchema();
            } else {
                error_log("Checking existing database schema");
                self::checkAndUpgradeSchema();
            }
        } catch (\Exception $e) {
            error_log("Database initialization failed: " . $e->getMessage());
            throw $e;
        }
    }

    private static function createInitialSchema() {
        self::$db->exec('BEGIN TRANSACTION');
        
        try {
            // Create version table first
            self::$db->exec("
                CREATE TABLE schema_version (
                    version INTEGER PRIMARY KEY,
                    applied_at DATETIME DEFAULT (datetime('now', 'localtime')),
                    description TEXT
                )
            ");

            // Create main tables
            self::$db->exec("
                CREATE TABLE protected_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT UNIQUE NOT NULL,
                    mode TEXT NOT NULL,                    -- 'file' or 'directory'
                    redundancy INTEGER NOT NULL,           -- redundancy percentage
                    protected_date DATETIME NOT NULL,
                    last_verified DATETIME,
                    last_status TEXT,                      -- PROTECTED, UNPROTECTED, DAMAGED, etc.
                    size INTEGER,                          -- in bytes
                    par2_path TEXT,                        -- path to .par2 files
                    file_types TEXT,                       -- JSON array of file types (for directory mode)
                    created_at DATETIME DEFAULT (datetime('now', 'localtime'))
                )
            ");

            self::$db->exec("
                CREATE TABLE verification_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    protected_item_id INTEGER,
                    verification_date DATETIME NOT NULL,
                    status TEXT NOT NULL,
                    details TEXT,
                    FOREIGN KEY (protected_item_id) REFERENCES protected_items(id)
                )
            ");

            // Create indexes
            self::$db->exec("CREATE INDEX idx_path ON protected_items(path)");
            self::$db->exec("CREATE INDEX idx_last_verified ON protected_items(last_verified)");
            self::$db->exec("CREATE INDEX idx_status ON protected_items(last_status)");

            // Record initial version
            $stmt = self::$db->prepare("
                INSERT INTO schema_version (version, description) 
                VALUES (:version, :description)
            ");
            $stmt->bindValue(':version', self::$currentVersion, \SQLITE3_INTEGER);
            $stmt->bindValue(':description', 'Initial schema creation', \SQLITE3_TEXT);
            $stmt->execute();

            self::$db->exec('COMMIT');
            error_log("Database schema created successfully");
        } catch (\Exception $e) {
            self::$db->exec('ROLLBACK');
            error_log("Failed to create database schema: " . $e->getMessage());
            throw $e;
        }
    }

    private static function checkAndUpgradeSchema() {
        try {
            $version = self::getCurrentSchemaVersion();
            error_log("Current database version: $version");
            
            if ($version < self::$currentVersion) {
                error_log("Upgrading database from version $version to " . self::$currentVersion);
                self::$db->exec('BEGIN TRANSACTION');
                
                try {
                    for ($v = $version + 1; $v <= self::$currentVersion; $v++) {
                        $migrationMethod = "applyMigration_v{$v}";
                        if (method_exists(__CLASS__, $migrationMethod)) {
                            error_log("Applying migration to version $v");
                            self::{$migrationMethod}();
                            
                            $stmt = self::$db->prepare("
                                INSERT INTO schema_version (version, description) 
                                VALUES (:version, :description)
                            ");
                            $stmt->bindValue(':version', $v, \SQLITE3_INTEGER);
                            $stmt->bindValue(':description', "Migration to version {$v}", \SQLITE3_TEXT);
                            $stmt->execute();
                        }
                    }
                    
                    self::$db->exec('COMMIT');
                    error_log("Database upgrade completed successfully");
                } catch (\Exception $e) {
                    self::$db->exec('ROLLBACK');
                    error_log("Database migration failed: " . $e->getMessage());
                    throw new \Exception("Database migration failed: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("Schema check failed: " . $e->getMessage());
            throw $e;
        }
    }

    private static function getCurrentSchemaVersion() {
        try {
            $result = self::$db->query("SELECT MAX(version) as version FROM schema_version");
            if ($result === false) {
                error_log("Failed to query schema version");
                return 0;
            }
            $row = $result->fetchArray(\SQLITE3_ASSOC);
            return $row['version'] ?? 0;
        } catch (\Exception $e) {
            error_log("Error getting schema version: " . $e->getMessage());
            return 0;
        }
    }

    public static function addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes = null) {
        $db = self::getInstance();
        
        try {
            $stmt = $db->prepare("
                INSERT INTO protected_items
                (path, mode, redundancy, protected_date, par2_path, file_types, size)
                VALUES (:path, :mode, :redundancy, datetime('now', 'localtime'), :par2_path, :file_types, :size)
            ");

            $size = is_dir($path) ? self::getDirectorySize($path) : filesize($path);
            
            $stmt->bindValue(':path', $path, \SQLITE3_TEXT);
            $stmt->bindValue(':mode', $mode, \SQLITE3_TEXT);
            $stmt->bindValue(':redundancy', $redundancy, \SQLITE3_INTEGER);
            $stmt->bindValue(':par2_path', $par2Path, \SQLITE3_TEXT);
            $stmt->bindValue(':file_types', $fileTypes ? json_encode($fileTypes) : null, \SQLITE3_TEXT);
            $stmt->bindValue(':size', $size, \SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            error_log("Added protected item: $path");
            return $result;
        } catch (\Exception $e) {
            error_log("Failed to add protected item: " . $e->getMessage());
            throw $e;
        }
    }

    public static function removeProtectedItem($path) {
        $db = self::getInstance();
        
        try {
            $db->exec('BEGIN TRANSACTION');
            
            // Delete verification history entries first using a subquery
            $db->exec("DELETE FROM verification_history WHERE protected_item_id IN (SELECT id FROM protected_items WHERE path = '" . $db->escapeString($path) . "')");
            
            // Then delete the protected item
            $db->exec("DELETE FROM protected_items WHERE path = '" . $db->escapeString($path) . "'");
            
            $db->exec('COMMIT');
            error_log("Removed protected item and its history: $path");
            return true;
            
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Failed to remove protected item: " . $e->getMessage());
            throw $e;
        }
    }

    public static function updateVerificationStatus($path, $status, $details = null) {
        $db = self::getInstance();
        
        try {
            $db->exec('BEGIN TRANSACTION');

            $stmt = $db->prepare("
                UPDATE protected_items
                SET last_verified = datetime('now', 'localtime'),
                    last_status = :status
                WHERE path = :path
            ");
            
            $stmt->bindValue(':path', $path, \SQLITE3_TEXT);
            $stmt->bindValue(':status', $status, \SQLITE3_TEXT);
            $stmt->execute();

            $stmt = $db->prepare("
                INSERT INTO verification_history
                (protected_item_id, verification_date, status, details)
                SELECT id, datetime('now', 'localtime'), :status, :details
                FROM protected_items
                WHERE path = :path
            ");
            
            $stmt->bindValue(':path', $path, \SQLITE3_TEXT);
            $stmt->bindValue(':status', $status, \SQLITE3_TEXT);
            $stmt->bindValue(':details', $details, \SQLITE3_TEXT);
            $stmt->execute();

            $db->exec('COMMIT');
            error_log("Updated verification status for $path: $status");
            return true;
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Failed to update verification status: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getProtectedItems($needsVerification = false) {
        $logger = Logger::getInstance();
        $logger->debug("Getting protected items", [
            'needs_verification' => $needsVerification
        ]);
        $db = self::getInstance();
        
        try {
            $logger->debug("Getting protected items", [
                'needs_verification' => $needsVerification
            ]);
            
            // First, get all items from database in a single query
            $sql = "SELECT * FROM protected_items";
            if ($needsVerification) {
                $sql .= " WHERE last_verified IS NULL OR
                         datetime(last_verified) < datetime('now', '-24 hours')";
            }
            $sql .= " ORDER BY protected_date DESC";
            
            $results = $db->query($sql);
            if ($results === false) {
                throw new \Exception("Failed to query protected items: " . $db->lastErrorMsg());
            }
            
            $items = [];
            $updateQueue = [];
            
            // First pass: collect all items and identify those needing updates
            while ($row = $results->fetchArray(\SQLITE3_ASSOC)) {
                $path = $row['path'];
                $exists = file_exists($path);
                
                if (!$exists) {
                    $updateQueue[] = [
                        'path' => $path,
                        'status' => 'MISSING',
                        'reason' => 'File or directory no longer exists'
                    ];
                    continue;
                }
                
                $currentSize = is_dir($path) ?
                    self::getDirectorySize($path) :
                    filesize($path);
                
                if ($currentSize !== $row['size']) {
                    $row['size'] = $currentSize;
                    $updateQueue[] = [
                        'path' => $path,
                        'size' => $currentSize
                    ];
                }
                
                $items[] = $row;
            }
            
            // Second pass: batch process updates in a single transaction
            if (!empty($updateQueue)) {
                $db->exec('BEGIN IMMEDIATE TRANSACTION');
                
                try {
                    // Prepare statements once
                    $statusStmt = $db->prepare("
                        UPDATE protected_items
                        SET last_verified = CURRENT_TIMESTAMP,
                            last_status = :status
                        WHERE path = :path
                    ");
                    
                    $sizeStmt = $db->prepare("
                        UPDATE protected_items
                        SET size = :size
                        WHERE path = :path
                    ");
                    
                    foreach ($updateQueue as $update) {
                        if (isset($update['status'])) {
                            $statusStmt->bindValue(':path', $update['path'], SQLITE3_TEXT);
                            $statusStmt->bindValue(':status', $update['status'], SQLITE3_TEXT);
                            $statusStmt->execute();
                            $statusStmt->reset();
                        }
                        
                        if (isset($update['size'])) {
                            $sizeStmt->bindValue(':path', $update['path'], SQLITE3_TEXT);
                            $sizeStmt->bindValue(':size', $update['size'], SQLITE3_INTEGER);
                            $sizeStmt->execute();
                            $sizeStmt->reset();
                        }
                    }
                    
                    $db->exec('COMMIT');
                } catch (\Exception $e) {
                    $db->exec('ROLLBACK');
                    $logger->error("Failed to process updates", ['exception' => $e]);
                }
            }
            
            $logger->debug("Retrieved protected items", ['count' => count($items)]);
            return $items;
        } catch (\Exception $e) {
            $logger->error("Failed to get protected items", ['exception' => $e]);
            throw $e;
        }
    }

    private static function getDirectorySize($path) {
        try {
            $size = 0;
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
            return $size;
        } catch (\Exception $e) {
            error_log("Failed to get directory size for $path: " . $e->getMessage());
            return 0;
        }
    }
}