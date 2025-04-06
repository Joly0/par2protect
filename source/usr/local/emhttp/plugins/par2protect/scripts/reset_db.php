<?php
/**
 * Database Reset Script
 * 
 * This script resets the database by deleting it and reinitializing it.
 * Use with caution as this will delete all data!
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
$config = $container->get('config');

// Enable console output for this script
$logger->enableStdoutLogging(true);


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
$logger->info("Database path: $dbPath");

// Check if database file exists
if (!file_exists($dbPath)) {
    $logger->warning("Database file does not exist, nothing to reset");
    $logger->info("Run init_db.php to create a new database");
    exit(0);
}

// Confirm reset if not forced
if (!$force) {
    $logger->warning("WARNING: This will delete all data in the database!");
    $logger->warning("To proceed, run this script with the --force flag");
    exit(0);
}

// Close any existing database connections
$db = $container->get('database');
$db->close();

// Backup the database
$backupPath = $dbPath . '.backup.' . date('YmdHis');
$logger->info("Creating backup at: $backupPath");

if (!copy($dbPath, $backupPath)) {
    $logger->error("Failed to create backup");
    exit(1);
}

// Delete the database
$logger->info("Deleting database file");
if (!unlink($dbPath)) {
    $logger->error("Failed to delete database file");
    exit(1);
}

// Reinitialize the database
$logger->info("Reinitializing database");
$logger->info("Running init_db.php");

// Run init_db.php
$initScript = __DIR__ . '/init_db.php';
if (!file_exists($initScript)) {
    $logger->error("init_db.php not found at: $initScript");
    exit(1);
}

// Execute init_db.php
$output = [];
$returnCode = 0;
exec("php $initScript 2>&1", $output, $returnCode);

// Check if init_db.php succeeded
if ($returnCode !== 0) {
    $logger->error("Failed to initialize database");
    $logger->error("Output from init_db.php:");
    foreach ($output as $line) {
        $logger->error("  $line");
    }
    exit(1);
}

// Log output from init_db.php
foreach ($output as $line) {
    echo $line . "\n";
}

$logger->info("Database reset completed successfully");
$logger->info("Backup created at: $backupPath");