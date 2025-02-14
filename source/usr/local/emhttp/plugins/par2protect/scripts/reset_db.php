<?php
/**
 * Database Reset Script
 * 
 * This script resets the database by deleting it and reinitializing it.
 * Use with caution as this will delete all data!
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

// Get components
$logger = Logger::getInstance();
$config = Config::getInstance();

// Function to log to both the logger and stdout
function log_message($message, $level = 'INFO') {
    global $logger;
    
    // Log to the logger
    if ($level === 'INFO') {
        $logger->info($message);
    } elseif ($level === 'ERROR') {
        $logger->error($message);
    } elseif ($level === 'WARNING') {
        $logger->warning($message);
    } elseif ($level === 'DEBUG') {
        $logger->debug($message);
    }
    
    // Also output to stdout for the script to capture
    echo date('[Y-m-d H:i:s]') . " $level: $message\n";
}

// Check if force flag is provided
$force = false;
foreach ($argv as $arg) {
    if ($arg === '--force') {
        $force = true;
        break;
    }
}

// Get database path
$dbPath = $config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
log_message("Database path: $dbPath");

// Check if database file exists
if (!file_exists($dbPath)) {
    log_message("Database file does not exist, nothing to reset", 'WARNING');
    log_message("Run init_db.php to create a new database");
    exit(0);
}

// Confirm reset if not forced
if (!$force) {
    log_message("WARNING: This will delete all data in the database!", 'WARNING');
    log_message("To proceed, run this script with the --force flag", 'WARNING');
    exit(0);
}

// Close any existing database connections
$db = Database::getInstance();
$db->close();

// Backup the database
$backupPath = $dbPath . '.backup.' . date('YmdHis');
log_message("Creating backup at: $backupPath");

if (!copy($dbPath, $backupPath)) {
    log_message("Failed to create backup", 'ERROR');
    exit(1);
}

// Delete the database
log_message("Deleting database file");
if (!unlink($dbPath)) {
    log_message("Failed to delete database file", 'ERROR');
    exit(1);
}

// Reinitialize the database
log_message("Reinitializing database");
log_message("Running init_db.php");

// Run init_db.php
$initScript = __DIR__ . '/init_db.php';
if (!file_exists($initScript)) {
    log_message("init_db.php not found at: $initScript", 'ERROR');
    exit(1);
}

// Execute init_db.php
$output = [];
$returnCode = 0;
exec("php $initScript 2>&1", $output, $returnCode);

// Check if init_db.php succeeded
if ($returnCode !== 0) {
    log_message("Failed to initialize database", 'ERROR');
    log_message("Output from init_db.php:", 'ERROR');
    foreach ($output as $line) {
        log_message("  $line", 'ERROR');
    }
    exit(1);
}

// Log output from init_db.php
foreach ($output as $line) {
    echo $line . "\n";
}

log_message("Database reset completed successfully");
log_message("Backup created at: $backupPath");