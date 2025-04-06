<?php
/**
 * Initialize Temporary Directories Script
 * 
 * This script initializes the temporary directory structure in /tmp for PAR2Protect.
 * It should be run on system startup to ensure the necessary directories exist.
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
$logger->info("Initializing temporary directories for PAR2Protect");

// Define directories to create
$tmpDirs = [
    '/tmp/par2protect',                  // Base directory
    '/tmp/par2protect/queue',            // Queue directory
    '/tmp/par2protect/logs',             // Logs directory
    '/tmp/par2protect/locks',            // Locks directory
    '/tmp/par2protect/cache',            // Cache directory
    '/tmp/par2protect/events'            // Events directory
];

// Create directories
$createdCount = 0;
foreach ($tmpDirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            $logger->debug("Created directory: $dir");
            $createdCount++;
        } else {
            $logger->error("Failed to create directory: $dir");
        }
    } else {
        $logger->debug("Directory already exists: $dir");
    }
}

// Log result
if ($createdCount > 0) {
    $logger->info("Created $createdCount temporary directories");
} else {
    $logger->info("All temporary directories already exist");
}

// Copy any existing logs from /boot/config to /tmp if they don't exist in /tmp yet
$bootLogDir = '/boot/config/plugins/par2protect';
$tmpLogDir = '/tmp/par2protect/logs';

// Main log file
$bootLogFile = $bootLogDir . '/par2protect.log';
$tmpLogFile = $tmpLogDir . '/par2protect.log';
if (file_exists($bootLogFile) && !file_exists($tmpLogFile)) {
    if (copy($bootLogFile, $tmpLogFile)) {
        $logger->debug("Copied log file from $bootLogFile to $tmpLogFile");
    } else {
        $logger->error("Failed to copy log file from $bootLogFile to $tmpLogFile");
    }
}

// Activity log
$bootActivityFile = $bootLogDir . '/activity.json';
$tmpActivityFile = $tmpLogDir . '/activity.json';
if (file_exists($bootActivityFile) && !file_exists($tmpActivityFile)) {
    if (copy($bootActivityFile, $tmpActivityFile)) {
        $logger->debug("Copied activity log from $bootActivityFile to $tmpActivityFile");
    } else {
        $logger->error("Failed to copy activity log from $bootActivityFile to $tmpActivityFile");
    }
}

// Queue processor log
$bootQueueLogFile = $bootLogDir . '/queue_processor.log';
$tmpQueueLogFile = $tmpLogDir . '/queue_processor.log';
if (file_exists($bootQueueLogFile) && !file_exists($tmpQueueLogFile)) {
    if (copy($bootQueueLogFile, $tmpQueueLogFile)) {
        $logger->debug("Copied queue processor log from $bootQueueLogFile to $tmpQueueLogFile");
    } else {
        $logger->error("Failed to copy queue processor log from $bootQueueLogFile to $tmpQueueLogFile");
    }
}

// Exit with success
$logger->info("Temporary directory initialization completed");
exit(0);