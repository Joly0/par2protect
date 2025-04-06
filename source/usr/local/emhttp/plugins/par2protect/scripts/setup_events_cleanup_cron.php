<?php
/**
 * Setup Events Cleanup Cron Job
 * 
 * This script sets up a cron job to clean up old events.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statement
// use Par2Protect\Core\Logger;

// Get logger from container
$container = get_container();
$logger = $container->get('logger');

$logger->info("Setting up events cleanup cron job");

// Path to the cleanup script
$scriptPath = '/usr/local/emhttp/plugins/par2protect/scripts/cleanup_events.php';
$logPath = '/boot/config/plugins/par2protect/logs/events_cleanup.log';

// Create the cron entry
$cronEntry = "0 0 * * * php $scriptPath >> $logPath 2>&1\n";

// Check if the cron entry already exists
$currentCrontab = shell_exec('crontab -l') ?: '';
if (strpos($currentCrontab, $scriptPath) !== false) {
    $logger->info("Cron job already exists, skipping");
    exit(0);
}

// Add the new cron entry
$newCrontab = $currentCrontab . $cronEntry;

// Write to a temporary file
$tempFile = tempnam(sys_get_temp_dir(), 'cron');
file_put_contents($tempFile, $newCrontab);

// Install the new crontab
$output = shell_exec("crontab $tempFile 2>&1");
unlink($tempFile);

if ($output) {
    $logger->error("Failed to install crontab", [
        'output' => $output
    ]);
    echo "Error: Failed to install crontab: $output\n";
    exit(1);
}

$logger->info("Events cleanup cron job installed successfully");
echo "Events cleanup cron job installed successfully\n";