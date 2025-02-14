<?php
/**
 * Setup Log Backup Cron Script
 * 
 * This script sets up a cron job to periodically backup logs from /tmp to the user-specified backup location.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

// Get components
$logger = Logger::getInstance();
$config = Config::getInstance();

// Log start
$logger->info("Setting up log backup cron job");

// Get backup interval from config
$backupInterval = $config->get('logging.backup_interval', 'daily');

// Define cron expression based on backup interval
$cronExpression = '';
switch ($backupInterval) {
    case 'hourly':
        $cronExpression = '0 * * * *'; // Run at minute 0 of every hour
        break;
    case 'daily':
        $cronExpression = '0 0 * * *'; // Run at midnight every day
        break;
    case 'weekly':
        $cronExpression = '0 0 * * 0'; // Run at midnight every Sunday
        break;
    case 'never':
        $logger->info("Log backup is disabled, not setting up cron job");
        exit(0);
        break;
    default:
        $cronExpression = '0 0 * * *'; // Default to daily
        break;
}

// Get path to backup script
$backupScriptPath = __DIR__ . '/backup_logs.php';

// Create cron job entry
$cronEntry = "$cronExpression php $backupScriptPath > /dev/null 2>&1\n";

// Get current crontab
exec('crontab -l 2>/dev/null', $currentCrontab, $returnCode);

// Check if cron job already exists
$cronJobExists = false;
foreach ($currentCrontab as $line) {
    if (strpos($line, $backupScriptPath) !== false) {
        $cronJobExists = true;
        break;
    }
}

// If cron job doesn't exist, add it
if (!$cronJobExists) {
    // Add our cron job to the current crontab
    $currentCrontab[] = $cronEntry;
    
    // Write new crontab to a temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'crontab');
    file_put_contents($tempFile, implode("\n", $currentCrontab));
    
    // Install new crontab
    exec("crontab $tempFile", $output, $returnCode);
    
    // Remove temporary file
    unlink($tempFile);
    
    if ($returnCode === 0) {
        $logger->info("Log backup cron job set up successfully", [
            'interval' => $backupInterval,
            'cron_expression' => $cronExpression
        ]);
        echo "Log backup cron job set up successfully\n";
        echo "Interval: $backupInterval\n";
        echo "Cron expression: $cronExpression\n";
    } else {
        $logger->error("Failed to set up log backup cron job", [
            'return_code' => $returnCode
        ]);
        echo "Failed to set up log backup cron job\n";
        echo "Return code: $returnCode\n";
    }
} else {
    $logger->info("Log backup cron job already exists", [
        'interval' => $backupInterval
    ]);
    echo "Log backup cron job already exists\n";
    echo "Interval: $backupInterval\n";
}

// Exit with success
exit(0);