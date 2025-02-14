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

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Services\Queue;

// Function to log to both the logger and stdout
function log_message($message, $level = 'INFO', $context = []) {
    global $logger;
    global $config;
    
    // Add script identifier to context
    $context['script'] = 'check_schedule';
    
    // Log to the logger
    if ($level === 'INFO') {
        $logger->info($message, $context);
    } elseif ($level === 'ERROR') {
        $logger->error($message, $context);
    } elseif ($level === 'WARNING') {
        $logger->warning($message, $context);
    } elseif ($level === 'DEBUG') {
        $logger->debug($message, $context);
    }
    
    // Only output DEBUG messages to stdout if debug logging is enabled
    if ($level !== 'DEBUG' || $config->get('debug.debug_logging', false)) {
        // Also output to stdout for the cron job to capture
        echo date('[Y-m-d H:i:s]') . " $level: $message\n";
    }
}

// Get components
$logger = Logger::getInstance();
$db = Database::getInstance();
$config = Config::getInstance();

// Check debug logging setting
$debugLoggingEnabled = $config->get('debug.debug_logging', false);
log_message("Debug logging enabled: " . ($debugLoggingEnabled ? 'true' : 'false'), 'DEBUG', ['setting' => $debugLoggingEnabled]);

$queueService = new Queue();

log_message("Starting schedule checker", 'DEBUG');

// Create a lock file to prevent multiple instances
$lockFile = '/boot/config/plugins/par2protect/schedule/checker.lock';
$myPid = getmypid(); 
log_message("Current process PID: $myPid", 'DEBUG');

// Ensure schedule directory exists
$scheduleDir = dirname($lockFile);
if (!is_dir($scheduleDir)) {
    log_message("Creating schedule directory: $scheduleDir", 'DEBUG');
    mkdir($scheduleDir, 0755, true);
}

// Use file locking to ensure only one process can acquire the lock
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp) {
    log_message("Could not open lock file: $lockFile", 'ERROR');
    exit(1);
}

// Try to get an exclusive lock (non-blocking)
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another process has the lock
    log_message("Schedule checker already running, exiting", 'DEBUG');
    fclose($lockFp);
    exit(0);
}

// We have the lock, write our PID to the file
ftruncate($lockFp, 0);
fwrite($lockFp, $myPid);
fflush($lockFp);

// Register a shutdown function to release the lock
register_shutdown_function(function() use ($lockFp, $lockFile, $myPid) {
    log_message("Cleaning up lock file for PID: $myPid", 'DEBUG');
    
    // Check if the lock file handle is still valid
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    
    // Make sure the lock file is removed
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    
    log_message("Schedule checker finished", 'DEBUG');
});

log_message("Lock acquired for PID: $myPid", 'DEBUG');

try {
    // Get the verification schedule from config
    $verifyCron = $config->get('protection.verify_cron', '-1'); // Default to disabled
    log_message("Verification schedule cron expression: $verifyCron", 'DEBUG');
    
    // Check if verification is disabled
    if ($verifyCron === '-1') {
        log_message("Verification schedule is disabled, exiting", 'DEBUG');
        exit(0);
    }
    
    // Parse the cron expression
    $cronParts = explode(' ', $verifyCron);
    if (count($cronParts) !== 5) {
        log_message("Invalid cron expression: $verifyCron", 'ERROR');
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
        log_message("It's time to run scheduled verification", 'INFO');
        
        // Get all protected items
        $result = $db->query("SELECT * FROM protected_items");
        $items = $db->fetchAll($result);
        
        if (empty($items)) {
            log_message("No protected items found", 'WARNING');
        } else {
            log_message("Found " . count($items) . " protected items", 'INFO');
            
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
                        log_message("Added verification task for: " . $item['path'], 'DEBUG');
                    } else { 
                        $failedCount++;
                        log_message("Failed to add verification task for: " . $item['path'], 'ERROR');
                    }
                } catch (Exception $e) {
                    $failedCount++;
                    log_message("Error adding verification task for: " . $item['path'] . " - " . $e->getMessage(), 'ERROR');
                }
            }
            
            log_message("Added $addedCount verification tasks to queue, $failedCount failed", 'INFO');
        }
    } else {
        log_message("Not time to run scheduled verification yet", 'DEBUG');
    }
} catch (Exception $e) {
    log_message("Error checking schedule: " . $e->getMessage(), 'ERROR');
    exit(1);
}

exit(0);