<?php
/**
 * Database Initialization Script
 * 
 * This script initializes the database with the required tables.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statements if getting from container
// use Par2Protect\Core\Database;
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\Config;

// Get components from container
$container = get_container();
$logger = $container->get('logger');
$db = $container->get('database');
$config = $container->get('config');

// Enable console output for this script
$logger->enableStdoutLogging(true);


$logger->info("Starting database initialization");

// Get database path
$dbPath = $config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
$logger->info("Using database at: $dbPath");

// Check if database file exists
$dbExists = file_exists($dbPath);
if ($dbExists) {
    $logger->info("Database file already exists");
} else {
    $logger->info("Database file does not exist, will be created");
}

// Create tables
try {
    // Begin transaction
    $db->beginTransaction();
    
    // Create protected_items table
    $logger->info("Creating protected_items table");
    $db->query("
        CREATE TABLE IF NOT EXISTS protected_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT NOT NULL,
            mode TEXT NOT NULL,                    -- Internal mode ('file', 'directory')
            display_mode TEXT,                     -- User-facing mode string
            redundancy INTEGER NOT NULL,           -- redundancy percentage
            protected_date DATETIME NOT NULL,
            last_verified DATETIME,
            last_status TEXT,                      -- PROTECTED, UNPROTECTED, DAMAGED, etc.
            last_details TEXT,                     -- Detailed output from verification/repair
            size INTEGER,                          -- in bytes
            par2_size INTEGER,                     -- size of protection files in bytes
            data_size INTEGER,                     -- size of protected data in bytes
            par2_path TEXT,                        -- path to .par2 files
            file_types TEXT,                       -- JSON array of file types (for directory mode)
            parent_dir TEXT,                       -- parent directory for individual files
            protected_files TEXT,                  -- JSON array of protected files (for individual files mode)
            created_at DATETIME DEFAULT (datetime('now', 'localtime')),
            UNIQUE(path, file_types)               -- Composite unique key for path + file_types
        )
    ");
    
    // Create indexes for protected_items
    $logger->info("Creating indexes for protected_items table");
    $db->query("CREATE INDEX IF NOT EXISTS idx_path ON protected_items(path)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_last_verified ON protected_items(last_verified)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_status ON protected_items(last_status)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_parent_dir ON protected_items(parent_dir)");
    
    // Create verification_history table
    $logger->info("Creating verification_history table");
    $db->query("
        CREATE TABLE IF NOT EXISTS verification_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            protected_item_id INTEGER,
            verification_date DATETIME NOT NULL,
            status TEXT NOT NULL,
            details TEXT,
            FOREIGN KEY (protected_item_id) REFERENCES protected_items(id) ON DELETE CASCADE
        )
    ");
    
    // Create operation_queue table
    $logger->info("Creating operation_queue table");
    $db->query("
        CREATE TABLE IF NOT EXISTS operation_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operation_type TEXT NOT NULL,
            parameters TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            started_at INTEGER,
            completed_at INTEGER,
            updated_at INTEGER NOT NULL,
            result TEXT,
            pid INTEGER
        )
    ");
    
    // Create indexes for operation_queue
    $logger->info("Creating indexes for operation_queue table");
    $db->query("CREATE INDEX IF NOT EXISTS idx_queue_status ON operation_queue(status)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_queue_type ON operation_queue(operation_type)");
    
    // Create file_metadata table
    $logger->info("Creating file_metadata table");
    $db->query("
        CREATE TABLE IF NOT EXISTS file_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            protected_item_id INTEGER NOT NULL,
            file_path TEXT NOT NULL,
            owner TEXT NOT NULL,
            group_name TEXT NOT NULL,
            permissions TEXT NOT NULL,
            mtime INTEGER,                         -- Added modification time
            extended_attributes TEXT,
            created_at DATETIME DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (protected_item_id) REFERENCES protected_items(id) ON DELETE CASCADE,
            UNIQUE(protected_item_id, file_path)
        )
    ");
    
    // Create indexes for file_metadata
    $logger->info("Creating indexes for file_metadata table");
    $db->query("CREATE INDEX IF NOT EXISTS idx_file_metadata_protected_item_id ON file_metadata(protected_item_id)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_file_metadata_file_path ON file_metadata(file_path)");

    // --- Add Schema Migration for mtime column ---
    $logger->info("Checking file_metadata schema for mtime column...");
    $columnsResult = $db->query("PRAGMA table_info(file_metadata)");
    $hasMtimeColumn = false;
    // Use fetchArray(SQLITE3_ASSOC) on the result object
    while ($column = $columnsResult->fetchArray(SQLITE3_ASSOC)) {
        if (isset($column['name']) && $column['name'] === 'mtime') {
            $hasMtimeColumn = true;
            break;
        }
    }

    if (!$hasMtimeColumn) {
        $logger->info("Adding mtime column to file_metadata table...");
        $db->query("ALTER TABLE file_metadata ADD COLUMN mtime INTEGER");
        $logger->info("mtime column added.");
    } else {
        $logger->info("mtime column already exists.");
    }
    // --- End Schema Migration ---
    
    // Commit transaction
    $db->commit();
    
    $logger->info("Database tables created successfully");
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction) {
        $db->rollback();
    }
    
    $logger->error("Error creating database tables: " . $e->getMessage());
    exit(1);
}

// Migrate data from old database if it exists
$oldDbPath = '/boot/config/plugins/par2protect/par2protect_old.db';
if (file_exists($oldDbPath)) {
    $logger->info("Found old database at: $oldDbPath");
    $logger->info("Attempting to migrate data from old database");
    
    try {
        // Connect to old database
        $oldDb = new SQLite3($oldDbPath);
        
        // Check if protected_files table exists in old database
        $result = $oldDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='protected_files'");
        $hasProtectedFiles = $result->fetchArray() !== false;
        
        if ($hasProtectedFiles) {
            $logger->info("Found protected_files table in old database");
            
            // Begin transaction
            $db->beginTransaction();
            
            // Get protected files from old database
            $result = $oldDb->query("SELECT * FROM protected_files");
            $count = 0;
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // Map old fields to new fields
                $path = $row['path'] ?? '';
                $mode = 'file';
                $redundancy = $row['redundancy'] ?? 10;
                $protectedDate = $row['protected_date'] ?? date('Y-m-d H:i:s');
                $lastVerified = $row['last_verified'] ?? null;
                $lastStatus = $row['status'] ?? 'PROTECTED';
                $size = $row['size'] ?? 0;
                $par2Path = $row['par2_path'] ?? '';
                
                if (empty($path) || empty($par2Path)) {
                    $logger->warning("Skipping invalid record: " . json_encode($row));
                    continue;
                }
                
                // Insert into new database
                $db->query(
                    "INSERT OR IGNORE INTO protected_items 
                    (path, mode, redundancy, protected_date, last_verified, last_status, size, par2_size, data_size, par2_path)
                    VALUES (:path, :mode, :redundancy, :protected_date, :last_verified, :last_status, :size, :par2_size, :data_size, :par2_path)",
                    [
                        ':path' => $path,
                        ':mode' => $mode,
                        ':redundancy' => $redundancy,
                        ':protected_date' => $protectedDate,
                        ':last_verified' => $lastVerified,
                        ':last_status' => $lastStatus,
                        ':size' => $size,
                        ':par2_size' => 0, // Default value for migrated data
                        ':data_size' => $size, // Use size as data_size for migrated data
                        ':par2_path' => $par2Path
                    ]
                );
                
                $count++;
            }
            
            // Commit transaction
            $db->commit();
            
            $logger->info("Migrated $count protected files from old database");
        } else {
            $logger->warning("No protected_files table found in old database");
        }
        
        // Close old database
        $oldDb->close();
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction) {
            $db->rollback();
        }
        
        $logger->error("Error migrating data from old database: " . $e->getMessage());
    }
}

$logger->info("Database initialization completed successfully");