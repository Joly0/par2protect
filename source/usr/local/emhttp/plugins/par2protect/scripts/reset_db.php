<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/logging.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/database.php");

$logger = Logger::getInstance();
$dbPath = '/boot/config/plugins/par2protect/par2protect.db';

try {
    $logger->info("Starting database reset");

    // Remove existing database if it exists
    if (file_exists($dbPath)) {
        $logger->info("Removing existing database", ['path' => $dbPath]);
        if (!unlink($dbPath)) {
            throw new \Exception("Failed to remove existing database");
        }
    }

    // Remove .db-journal file if it exists
    $journalPath = $dbPath . '-journal';
    if (file_exists($journalPath)) {
        $logger->info("Removing journal file", ['path' => $journalPath]);
        unlink($journalPath);
    }

    // Remove .db-wal file if it exists
    $walPath = $dbPath . '-wal';
    if (file_exists($walPath)) {
        $logger->info("Removing WAL file", ['path' => $walPath]);
        unlink($walPath);
    }

    // Remove .db-shm file if it exists
    $shmPath = $dbPath . '-shm';
    if (file_exists($shmPath)) {
        $logger->info("Removing SHM file", ['path' => $shmPath]);
        unlink($shmPath);
    }

    // Create database directory if it doesn't exist
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        $logger->info("Creating database directory", ['path' => $dbDir]);
        if (!mkdir($dbDir, 0755, true)) {
            throw new \Exception("Failed to create database directory");
        }
    }

    // Ensure directory is writable
    if (!is_writable($dbDir)) {
        $logger->info("Setting directory permissions", ['path' => $dbDir]);
        if (!chmod($dbDir, 0755)) {
            throw new \Exception("Failed to set directory permissions");
        }
    }

    // Initialize database
    $logger->info("Initializing new database");
    $db = Database::getInstance();

    // Verify database was created
    if (!file_exists($dbPath)) {
        throw new \Exception("Database file was not created");
    }

    // Verify database is writable
    if (!is_writable($dbPath)) {
        $logger->info("Setting database file permissions", ['path' => $dbPath]);
        if (!chmod($dbPath, 0644)) {
            throw new \Exception("Failed to set database file permissions");
        }
    }

    // Test database connection
    $logger->info("Testing database connection");
    $items = Database::getProtectedItems();
    
    $logger->info("Database reset completed successfully", [
        'path' => $dbPath,
        'size' => filesize($dbPath),
        'permissions' => substr(sprintf('%o', fileperms($dbPath)), -4)
    ]);

    echo "Database reset completed successfully.\n";
    
} catch (\Exception $e) {
    $logger->error("Database reset failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}