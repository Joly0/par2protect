#!/usr/bin/php
<?php
/**
 * Schedule Checker Script
 * 
 * This script checks if it's time to run scheduled verifications based on the cron expression
 * in the settings, and adds verification tasks to the queue if needed.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statements if getting from container
// use Par2Protect\Core\Database;
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\Config;
use Par2Protect\Services\Queue; // Keep this one for type hinting if needed, or remove


// Get components
// Get components from container
$container = get_container();
if (!$container) {
    // Use basic error logging as the main logger might not be available
    error_log("Par2Protect Schedule Check: Failed to get DI container from bootstrap.");
    exit(1);
}
$logger = $container->get('logger');
if (!$logger) {
    // Use basic error logging as the main logger failed to initialize
    error_log("Par2Protect Schedule Check: Failed to get logger service from container.");
    exit(1);
}
$db = $container->get('database');
$config = $container->get('config');

// Enable console output for this script
$logger->enableStdoutLogging(true);

// Check debug logging setting
$debugLoggingEnabled = $config->get('debug.debug_logging', false);
$logger->debug("Debug logging enabled: " . ($debugLoggingEnabled ? 'true' : 'false'), ['setting' => $debugLoggingEnabled]);

$queueService = $container->get(Queue::class);

$logger->debug("Starting schedule checker");

// Create a lock file to prevent multiple instances
$lockFile = '/boot/config/plugins/par2protect/schedule/checker.lock';
$myPid = getmypid(); 
$logger->debug("Current process PID: $myPid");

// Ensure schedule directory exists
$scheduleDir = dirname($lockFile);
if (!is_dir($scheduleDir)) {
    $logger->debug("Creating schedule directory: $scheduleDir");
    mkdir($scheduleDir, 0755, true);
}

// Use file locking to ensure only one process can acquire the lock
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp) {
    $logger->error("Could not open lock file: $lockFile");
    exit(1);
}

// Try to get an exclusive lock (non-blocking)
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another process has the lock
    $logger->debug("Schedule checker already running, exiting");
    fclose($lockFp);
    exit(0);
}

// We have the lock, write our PID to the file
ftruncate($lockFp, 0);
fwrite($lockFp, $myPid);
fflush($lockFp);

// Register a shutdown function to release the lock
register_shutdown_function(function() use ($lockFp, $lockFile, $myPid, $logger) {
    $logger->debug("Cleaning up lock file for PID: $myPid");
    
    // Check if the lock file handle is still valid
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    
    // Make sure the lock file is removed
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    
    $logger->debug("Schedule checker finished");
});

$logger->debug("Lock acquired for PID: $myPid");

try {
    // Get the verification schedule from config
    $verifyCron = $config->get('protection.verify_cron', '-1'); // Default to disabled
    $logger->debug("Verification schedule cron expression: $verifyCron");
    
    // Check if verification is disabled
    if ($verifyCron === '-1') {
        $logger->debug("Verification schedule is disabled, exiting");
        exit(0);
    }
    
    // Parse the cron expression
    $cronParts = explode(' ', $verifyCron);
    if (count($cronParts) !== 5) {
        $logger->error("Invalid cron expression: $verifyCron");
        exit(1);
    }
    
    $minute = $cronParts[0];
    $hour = $cronParts[1];
    $dayOfMonth = $cronParts[2];
    $month = $cronParts[3];
    $dayOfWeek = $cronParts[4];
    
    // Get current time
    $now = time();
    $currentMinute = (int)date('i', $now);
    $currentHour = (int)date('G', $now);
    $currentDayOfMonth = (int)date('j', $now);
    $currentMonth = (int)date('n', $now);
    $currentDayOfWeek = (int)date('w', $now); // 0 (Sunday) to 6 (Saturday)
    
    // Check if it's time to run verification
    $shouldRun = false;
    
    // Check minute
    $minuteMatch = ($minute === '*' || $minute === (string)$currentMinute);
    if (strpos($minute, '*/') === 0) {
        $minuteInterval = (int)substr($minute, 2);
        $minuteMatch = ($currentMinute % $minuteInterval === 0);
    } elseif (strpos($minute, ',') !== false) {
        $minuteValues = explode(',', $minute);
        $minuteMatch = in_array((string)$currentMinute, $minuteValues);
    }
    
    // Check hour
    $hourMatch = ($hour === '*' || $hour === (string)$currentHour);
    if (strpos($hour, '*/') === 0) {
        $hourInterval = (int)substr($hour, 2);
        $hourMatch = ($currentHour % $hourInterval === 0);
    } elseif (strpos($hour, ',') !== false) {
        $hourValues = explode(',', $hour);
        $hourMatch = in_array((string)$currentHour, $hourValues);
    }
    
    // Check day of month
    $dayOfMonthMatch = ($dayOfMonth === '*' || $dayOfMonth === (string)$currentDayOfMonth);
    if (strpos($dayOfMonth, ',') !== false) {
        $dayOfMonthValues = explode(',', $dayOfMonth);
        $dayOfMonthMatch = in_array((string)$currentDayOfMonth, $dayOfMonthValues);
    }
    
    // Check month
    $monthMatch = ($month === '*' || $month === (string)$currentMonth);
    if (strpos($month, ',') !== false) {
        $monthValues = explode(',', $month);
        $monthMatch = in_array((string)$currentMonth, $monthValues);
    }
    
    // Check day of week
    $dayOfWeekMatch = ($dayOfWeek === '*' || $dayOfWeek === (string)$currentDayOfWeek);
    if (strpos($dayOfWeek, ',') !== false) {
        $dayOfWeekValues = explode(',', $dayOfWeek);
        $dayOfWeekMatch = in_array((string)$currentDayOfWeek, $dayOfWeekValues);
    }
    
    // If day of month is specified, day of week is ignored, and vice versa
    if ($dayOfMonth !== '*' && $dayOfWeek !== '*') {
        $shouldRun = $minuteMatch && $hourMatch && $monthMatch && ($dayOfMonthMatch || $dayOfWeekMatch);
    } else {
        $shouldRun = $minuteMatch && $hourMatch && $monthMatch && $dayOfMonthMatch && $dayOfWeekMatch;
    }
    
    // Check if we should run verification
    if ($shouldRun) {
        $logger->info("It's time to run scheduled verification");
        
        // Get all protected items
        $result = $db->query("SELECT * FROM protected_items");
        $items = $db->fetchAll($result);
        
        if (empty($items)) {
            $logger->warning("No protected items found");
        } else {
            $logger->info("Found " . count($items) . " protected items");
            
            $addedCount = 0;
            $failedCount = 0;
            
            // Add verification tasks to queue for all protected items
            foreach ($items as $item) {
                try {
                    // Add to queue with force=true
                    $result = $queueService->addOperation('verify', [
                        'path' => $item['path'],
                        'force' => true // Always force verification on schedule
                    ]);
                    
                    if ($result['success']) {
                        $addedCount++;
                        $logger->debug("Added verification task for: " . $item['path']);
                    } else { 
                        $failedCount++;
                        $logger->error("Failed to add verification task for: " . $item['path']);
                    }
                } catch (Exception $e) {
                    $failedCount++;
                    $logger->error("Error adding verification task for: " . $item['path'] . " - " . $e->getMessage());
                }
            }
            
            $logger->info("Added $addedCount verification tasks to queue, $failedCount failed");
        }
    } else {
        $logger->debug("Not time to run scheduled verification yet");
    }
} catch (Exception $e) {
    $logger->error("Error checking schedule: " . $e->getMessage());
    exit(1);
}

exit(0);