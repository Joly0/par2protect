<?php
/**
 * Backup Logs Script
 * 
 * This script backs up logs from /tmp to the user-specified backup location.
 * It should be run periodically via cron to ensure logs are not lost when the system restarts.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statements
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\Config;

// Get components from container
$container = get_container();
$logger = $container->get('logger');
$config = $container->get('config');

// Log start
$logger->info("Starting log backup operation");

// Get backup location from config
$backupLocation = $config->get('logging.backup_path', '/boot/config/plugins/par2protect');

// Define source and destination paths
$tmpLogDir = '/tmp/par2protect/logs';
$backupLogDir = $backupLocation;

// Create backup directory if it doesn't exist
if (!is_dir($backupLogDir)) {
    if (!@mkdir($backupLogDir, 0755, true)) {
        $logger->error("Failed to create backup directory: $backupLogDir");
        exit(1);
    }
    $logger->debug("Created backup directory: $backupLogDir");
}

// Check if tmp log directory exists
if (!is_dir($tmpLogDir)) {
    $logger->warning("Temporary log directory does not exist: $tmpLogDir");
    exit(0);
}

// Get all log files in tmp directory
$logFiles = glob($tmpLogDir . '/*.log');
$jsonFiles = glob($tmpLogDir . '/*.json');
$allFiles = array_merge($logFiles, $jsonFiles);

if (empty($allFiles)) {
    $logger->info("No log files found in temporary directory");
    exit(0);
}

// Copy each log file to backup location
$backupCount = 0;
foreach ($allFiles as $file) {
    $filename = basename($file);
    $backupFile = $backupLogDir . '/' . $filename;
    
    // Check if file has been modified since last backup
    if (file_exists($backupFile) && filemtime($file) <= filemtime($backupFile)) {
        $logger->debug("Skipping backup of $filename (not modified)");
        continue;
    }
    
    if (copy($file, $backupFile)) {
        $logger->debug("Backed up $filename to $backupLogDir");
        $backupCount++;
    } else {
        $logger->error("Failed to backup $filename to $backupLogDir");
    }
}

// Log result
if ($backupCount > 0) {
    $logger->info("Backed up $backupCount log files to $backupLogDir");
} else {
    $logger->info("No log files needed backup");
}

// Exit with success
exit(0);