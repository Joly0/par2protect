<?php
/**
 * Queue Processor Script
 * 
 * This script processes operations from the queue.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Database;
use Par2Protect\Core\QueueDatabase;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Services\Protection;
use Par2Protect\Services\Verification;

// Function to log to both the logger and stdout
function log_message($message, $level = 'INFO', $context = []) {
    global $logger;
    global $config;
    
    // Add script identifier to context
    $context['script'] = 'process_queue';
    
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

/**
 * Check if a database operation is safe to perform
 * This function attempts to detect potential deadlocks or lock contention
 * 
 * @param Database $db Database instance
 * @param string $operationType Type of operation being performed
 * @return bool True if operation is safe to perform, false otherwise
 */
function is_database_operation_safe($db, $queueDb, $operationType) {
    try {
        // For high-risk operations like remove, perform an additional check
        if (in_array($operationType, ['remove'])) {
            // Try a simple query with a short timeout to check database availability
            $db->getSQLite()->busyTimeout(500); // Set a short timeout for this check
            $db->query("PRAGMA quick_check");
            $db->getSQLite()->busyTimeout(5000); // Reset to normal timeout
        }
        return true;
    } catch (\Exception $e) {
        log_message("Database appears to be under contention, delaying operation: " . $e->getMessage(), 'WARNING');
        $db->getSQLite()->busyTimeout(5000); // Reset to normal timeout
        return false;
    }
}

// Get components
$logger = Logger::getInstance();
$db = Database::getInstance();
$queueDb = QueueDatabase::getInstance();
$config = Config::getInstance();

// Check debug logging setting
$debugLoggingEnabled = $config->get('debug.debug_logging', false);
log_message("Debug logging enabled: " . ($debugLoggingEnabled ? 'true' : 'false'), 'DEBUG', ['setting' => $debugLoggingEnabled]);

log_message("Starting queue processor", 'DEBUG');

// Create a lock file to prevent multiple instances
$lockFile = '/tmp/par2protect/locks/processor.lock';
$myPid = getmypid();
log_message("Current process PID: $myPid", 'DEBUG');

// Ensure queue directory exists
$queueDir = dirname($lockFile);
if (!is_dir($queueDir)) {
    log_message("Creating queue directory: $queueDir", 'DEBUG');
    mkdir($queueDir, 0755, true);
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
    log_message("Queue processor already running, exiting", 'DEBUG');
    fclose($lockFp);
    exit(0);
}

// We have the lock, write our PID to the file
ftruncate($lockFp, 0);
fwrite($lockFp, $myPid);
fflush($lockFp);

// Set a shorter timeout for the lock file to ensure it's released quickly
// This helps the next queue processor start faster
touch($lockFile, time() + 60); // Set the lock file to expire in 60 seconds

// Register a shutdown function to release the lock and handle stuck operations
register_shutdown_function(function() use ($lockFp, $lockFile, $myPid, $db, $queueDb, $logger) {
    log_message("Cleaning up lock file for PID: $myPid", 'DEBUG', ['lock_file' => $lockFile]);
    
    // Check if the lock file handle is still valid
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    
    // Make sure the lock file is removed
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    
    // Check if there are any operations still marked as processing by this process
    try {
        $result = $queueDb->query("
            SELECT id FROM operation_queue
            WHERE status = 'processing' AND pid = :pid
        ", [':pid' => $myPid]);
        
        $stuckOperations = $queueDb->fetchAll($result);
        
        if (!empty($stuckOperations)) {
            log_message("Found " . count($stuckOperations) . " operations still marked as processing on shutdown", 'WARNING');
            
            // Mark them as failed
            foreach ($stuckOperations as $op) {
                log_message("Marking operation as failed on shutdown - ID: " . $op['id'], 'WARNING');
                
                try {
                    $queueDb->query("
                        UPDATE operation_queue
                        SET status = 'failed',
                            completed_at = :now,
                            updated_at = :now,
                            result = :result
                        WHERE id = :id
                    ", [
                        ':id' => $op['id'],
                        ':now' => time(),
                        ':result' => json_encode([
                            'success' => false,
                            'error' => 'Operation terminated unexpectedly during processing'
                        ])
                    ]);
                } catch (Exception $e) {
                    log_message("Failed to mark operation as failed: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    } catch (Exception $e) {
        log_message("Error checking for stuck operations on shutdown: " . $e->getMessage(), 'ERROR');
    }
    
    log_message("Queue processor finished", 'DEBUG');
});

log_message("Lock acquired for PID: $myPid", 'DEBUG');

// Set a maximum execution time for the script
$maxExecutionTime = $config->get('queue.max_execution_time', 1800); // 30 minutes in seconds
$startTime = time();

try {
    // Phase 4.1: Check if database is locked before starting new operations
    $isDatabaseLocked = false;
    try {
        // Try a simple query to check if the database is locked
        $db->query("PRAGMA quick_check");
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'database is locked') !== false) {
            $isDatabaseLocked = true;
            log_message("Database is currently locked, waiting before processing operations", 'WARNING');
            
            // Wait a moment before trying again
            sleep(2);
            
            // Try again
            try {
                $db->query("PRAGMA quick_check");
                $isDatabaseLocked = false;
            } catch (\Exception $e) {
                log_message("Database is still locked after waiting, will skip processing this cycle", 'WARNING');
            }
        }
    }
    
    // First, check for stuck operations
    $result = $queueDb->query("
        SELECT * FROM operation_queue
        WHERE status = 'processing' AND started_at < :timeout -- Check for operations stuck for more than 1 hour
        ORDER BY created_at ASC
    ", [':timeout' => time() - 3600]);
    
    $stuckOperations = $queueDb->fetchAll($result);
    
    // Handle stuck operations
    foreach ($stuckOperations as $stuckOp) {
        log_message("Found stuck operation - ID: " . $stuckOp['id'] . ", marking as failed", 'WARNING');
        
        // Mark as failed
        $queueDb->query("
            UPDATE operation_queue
            SET status = 'failed',
                completed_at = :now,
                updated_at = :now,
                result = :result
            WHERE id = :id
        ", [
            ':id' => $stuckOp['id'],
            ':now' => time(),
            ':result' => json_encode([
                'success' => false,
                'error' => 'Operation timed out or was interrupted'
            ])
        ]);
        
        log_message("Stuck operation marked as failed - ID: " . $stuckOp['id'], 'DEBUG');
    }
    
    // Only proceed with processing if the database is not locked
    if (!$isDatabaseLocked) {
        // Check the maximum concurrent operations setting
        $maxConcurrentOperations = $config->get('resource_limits.max_concurrent_operations', 2);
        
        // Count currently running operations
        $result = $queueDb->query("
            SELECT COUNT(*) as count FROM operation_queue
            WHERE status = 'processing'
        ");
        $row = $queueDb->fetchOne($result);
        $runningOperations = $row['count'];
        
        log_message("Current running operations: $runningOperations, Maximum allowed: $maxConcurrentOperations", 'DEBUG');
        
        // Only proceed if we haven't reached the maximum concurrent operations limit
        if ($runningOperations < $maxConcurrentOperations) {
            $result = $queueDb->query("
                SELECT * FROM operation_queue
                WHERE status = 'pending'
                ORDER BY
                    CASE
                        WHEN operation_type = 'remove' THEN 0  -- Process remove operations first
                        WHEN operation_type = 'repair' THEN 1  -- Then repair operations
                        WHEN operation_type = 'protect' THEN 2 -- Then protect operations
                        ELSE 3                                 -- Verify operations last
                    END, created_at ASC
                LIMIT 1
            ");
            
            $operation = $queueDb->fetchOne($result);
            
            if ($operation) {
                log_message("Processing operation - ID: " . $operation['id'] . ", Type: " . $operation['operation_type'] . ", Status: " . $operation['status'], 'DEBUG');
                
                // Mark as processing with our PID
                $queueDb->query("
                    UPDATE operation_queue
                    SET status = 'processing',
                        started_at = :now,
                        updated_at = :now,
                        pid = :pid
                    WHERE id = :id
                ", [
                    ':id' => $operation['id'],
                    ':now' => time(),
                    ':pid' => $myPid
                ]);
                
                // Record start time for minimum processing duration
                $operationStartTime = microtime(true);
                
                // Process based on operation type
                $parameters = json_decode($operation['parameters'], true);
                $result = null;
                
                // Validate parameters
                if (!is_array($parameters)) {
                    log_message("Invalid parameters for operation ID: " . $operation['id'] . " - Not a valid JSON object", 'ERROR');
                    $result = ['success' => false, 'error' => 'Invalid parameters: Not a valid JSON object'];
                }
                // Make sure either path or id parameter exists for all operations
                elseif ((!isset($parameters['path']) || empty($parameters['path'])) && 
                        (!isset($parameters['id']) || empty($parameters['id']))) {
                    log_message("Invalid parameters for operation ID: " . $operation['id'] . " - Missing both path and id parameters", 'ERROR');
                    $result = ['success' => false, 'error' => 'Invalid parameters: Either path or id parameter is required'];
                }
                // If parameters are valid, process the operation
                else {
                    // Register signal handlers
                    declare(ticks=1);
                    
                    // Handle termination signals
                    pcntl_signal(SIGTERM, function() use ($operation, $queueDb) {
                        log_message("Received termination signal for operation - ID: " . $operation['id'], 'WARNING');
                        
                        // Mark the operation as cancelled
                        try {
                            $queueDb->query("
                                UPDATE operation_queue
                                SET status = 'cancelled',
                                    completed_at = :now,
                                    updated_at = :now,
                                    result = :result
                                WHERE id = :id
                            ", [
                                ':id' => $operation['id'],
                                ':now' => time(),
                                ':result' => json_encode([
                                    'success' => false,
                                    'error' => 'Operation cancelled by user or system'
                                ])
                            ]);
                            log_message("Marked operation as cancelled due to termination - ID: " . $operation['id'], 'WARNING');
                        } catch (Exception $e) {
                            log_message("Failed to mark operation as cancelled: " . $e->getMessage(), 'ERROR');
                        }
                        
                        exit(1);
                    });
                    
                    // Process operation
                    switch ($operation['operation_type']) {
                        // Phase 4.2: Queue management improvements - check for potential deadlocks
                        case 'protect':
                            $protection = new Protection();
                            
                            // Extract advanced settings if provided
                            $advancedSettings = null;
                            if (isset($parameters['advanced_settings'])) {
                                // If advanced_settings is a string, decode it
                                if (is_string($parameters['advanced_settings'])) {
                                    $advancedSettings = json_decode($parameters['advanced_settings'], true);
                                    
                                    log_message("DIAGNOSTIC: Advanced settings decoded from JSON string", 'DEBUG', [
                                        'path' => $parameters['path'] ?? 'not set',
                                        'advanced_settings_string' => $parameters['advanced_settings'],
                                        'decoded_settings' => is_array($advancedSettings) ? json_encode($advancedSettings) : 'decode failed',
                                        'json_error' => json_last_error_msg()
                                    ]);
                                } else {
                                    $advancedSettings = $parameters['advanced_settings'];
                                }
                                
                                log_message("DIAGNOSTIC: Advanced settings found in queue parameters", 'DEBUG', [
                                    'path' => $parameters['path'] ?? 'not set',
                                    'advanced_settings' => is_array($advancedSettings) ? json_encode($advancedSettings) : $advancedSettings
                                ]);
                            }
                            
                            // Also check for individual advanced settings at the top level
                            if (!$advancedSettings) {
                                $advancedSettings = [];
                            }
                            
                            // Add any top-level advanced settings
                            if (isset($parameters['block_count'])) {
                                $advancedSettings['block_count'] = $parameters['block_count'];
                            }
                            if (isset($parameters['block_size'])) {
                                $advancedSettings['block_size'] = $parameters['block_size'];
                            }
                            if (isset($parameters['target_size'])) {
                                $advancedSettings['target_size'] = $parameters['target_size'];
                            }
                            if (isset($parameters['recovery_files'])) {
                                $advancedSettings['recovery_files'] = $parameters['recovery_files'];
                            }
                            
                            // If no advanced settings were found, set to null
                            if (empty($advancedSettings)) {
                                $advancedSettings = null;
                            }
                            
                            // Add diagnostic logging for protection parameters
                            log_message("DIAGNOSTIC: Processing protect operation", 'DEBUG', [
                                'path' => $parameters['path'] ?? 'not set',
                                'file_types' => isset($parameters['file_types']) ? 
                                    (is_array($parameters['file_types']) ? json_encode($parameters['file_types']) : $parameters['file_types']) : 'not set',
                                'file_types_count' => isset($parameters['file_types']) && is_array($parameters['file_types']) ? 
                                    count($parameters['file_types']) : 'not an array',
                                'file_categories' => isset($parameters['file_categories']) ?
                                    (is_array($parameters['file_categories']) ? json_encode($parameters['file_categories']) : $parameters['file_categories']) : 'not set',
                                'advanced_settings' => isset($advancedSettings) ?
                                    (is_array($advancedSettings) ? json_encode($advancedSettings) : $advancedSettings) : 'not set'
                            ]);
                            
                            $result = $protection->protect(
                                $parameters['path'],
                                $parameters['redundancy'] ?? null,
                                $parameters['file_types'] ?? null,
                                $parameters['file_categories'] ?? null,
                                $advancedSettings
                            );
                            break;
                            
                        case 'verify':
                            $verification = new Verification();
                            if (isset($parameters['id']) && !empty($parameters['id'])) {
                                // Use ID-based verification if ID is provided
                                $verifyMetadata = isset($parameters['verify_metadata']) ? (bool)$parameters['verify_metadata'] : false;
                                $autoRestoreMetadata = isset($parameters['auto_restore_metadata']) ? (bool)$parameters['auto_restore_metadata'] : false;
                                
                                $result = $verification->verifyById(
                                    $parameters['id'],
                                    isset($parameters['force']) ? $parameters['force'] : false,
                                    $verifyMetadata,
                                    $autoRestoreMetadata
                                );
                            } else {
                                // Fall back to path-based verification
                                $verifyMetadata = isset($parameters['verify_metadata']) ? (bool)$parameters['verify_metadata'] : false;
                                $autoRestoreMetadata = isset($parameters['auto_restore_metadata']) ? (bool)$parameters['auto_restore_metadata'] : false;
                                
                                $result = $verification->verify(
                                    $parameters['path'],
                                    isset($parameters['force']) ? $parameters['force'] : false,
                                    $verifyMetadata,
                                    $autoRestoreMetadata
                                );
                            }
                            
                            // Log debug information only (not for activity log)
                            $pathOrId = isset($parameters['id']) ? "ID: " . $parameters['id'] : "path: " . $parameters['path'];
                            log_message("Verification result for " . $pathOrId, 'DEBUG', [
                                'path' => $parameters['path'] ?? null,
                                'id' => $parameters['id'] ?? null
                            ]);
                            log_message("Status: " . ($result['status'] ?? 'unknown'), 'DEBUG', ['status' => $result['status'] ?? 'unknown']);
                            
                            // Ensure operations with VERIFIED status are always marked as successful
                            if (isset($result['status']) && $result['status'] === 'VERIFIED') {
                                log_message("DIAGNOSTIC: Ensuring VERIFIED operation is marked as successful", 'DEBUG', [
                                    'path' => $parameters['path'] ?? null,
                                    'id' => $parameters['id'] ?? null,
                                    'status' => $result['status']
                                ]);
                                $result['success'] = true;
                            }
                            
                            // Add diagnostic logging for verification parameters
                            log_message("DIAGNOSTIC: Verification parameters", 'DEBUG', [
                                'path' => $parameters['path'] ?? null,
                                'id' => $parameters['id'] ?? null,
                                'force_param' => isset($parameters['force']) ? ($parameters['force'] ? 'true' : 'false') : 'not set',
                                'force_type' => isset($parameters['force']) ? gettype($parameters['force']) : 'not set',
                                'verify_metadata' => isset($parameters['verify_metadata']) ? ($parameters['verify_metadata'] ? 'true' : 'false') : 'not set',
                                'auto_restore_metadata' => isset($parameters['auto_restore_metadata']) ? ($parameters['auto_restore_metadata'] ? 'true' : 'false') : 'not set',
                                'parameters_json' => json_encode($parameters),
                                'operation_id' => $operation['id']
                            ]);
                            
                            log_message("Success: " . ($result['success'] ? 'true' : 'false'), 'DEBUG', ['success' => $result['success']]);
                            break;
                            
                        case 'repair':
                            $verification = new Verification();
                            $restoreMetadata = isset($parameters['restore_metadata']) ? (bool)$parameters['restore_metadata'] : true;
                            
                            if (isset($parameters['id']) && !empty($parameters['id'])) {
                                // Use ID-based repair if ID is provided
                                $result = $verification->repairById(
                                    $parameters['id'],
                                    $restoreMetadata
                                );
                            } else {
                                // Fall back to path-based repair
                                $result = $verification->repair(
                                    $parameters['path'],
                                    $restoreMetadata
                                );
                            }
                            
                            // Add diagnostic logging for repair parameters
                            log_message("DIAGNOSTIC: Repair parameters", 'DEBUG', [
                                'path' => $parameters['path'] ?? null,
                                'id' => $parameters['id'] ?? null,
                                'restore_metadata' => isset($parameters['restore_metadata']) ? ($parameters['restore_metadata'] ? 'true' : 'false') : 'true (default)',
                                'parameters_json' => json_encode($parameters),
                                'operation_id' => $operation['id']
                            ]);
                            break;
                            
                        case 'remove':
                            // For remove operations, check if it's safe to proceed
                            if (!is_database_operation_safe($db, $queueDb, 'remove')) {
                                log_message("Delaying remove operation due to potential database contention", 'WARNING');
                                sleep(3); // Wait a bit before trying again
                            }
                            
                            // Add diagnostic logging for remove operation parameters
                            log_message("DIAGNOSTIC: Processing remove operation", 'DEBUG', [
                                'path' => $parameters['path'] ?? 'not set',
                                'path_type' => isset($parameters['path']) ? gettype($parameters['path']) : 'not set',
                                'is_numeric_path' => isset($parameters['path']) && is_numeric($parameters['path']) ? 'true' : 'false',
                                'id' => $parameters['id'] ?? 'not set',
                                'operation_id' => $operation['id'],
                                'operation_type' => 'remove'
                            ]);
                            
                            $protection = new Protection();
                            if (isset($parameters['id']) && !empty($parameters['id'])) {
                                // Use ID-based removal if ID is provided
                                log_message("DIAGNOSTIC: Using ID-based removal", 'DEBUG', [
                                    'id' => $parameters['id'],
                                    'operation_id' => $operation['id']
                                ]);
                                $result = $protection->removeById(
                                    $parameters['id']
                                );
                            } else {
                                // Fall back to path-based removal
                                log_message("DIAGNOSTIC: Using path-based removal", 'DEBUG', [
                                    'path' => $parameters['path'],
                                    'path_type' => gettype($parameters['path']),
                                    'is_numeric' => is_numeric($parameters['path']) ? 'true' : 'false',
                                    'operation_id' => $operation['id']
                                ]);
                                $result = $protection->remove(
                                    $parameters['path']
                                );
                            }
                            
                            // Log debug information only (not for activity log)
                            $pathOrId = isset($parameters['id']) ? "ID: " . $parameters['id'] : "path: " . $parameters['path'];
                            log_message("Removal result for " . $pathOrId, 'DEBUG', [
                                'path' => $parameters['path'] ?? null,
                                'id' => $parameters['id'] ?? null
                            ]);
                            log_message("Success: " . ($result['success'] ? 'true' : 'false'), 'DEBUG', ['success' => $result['success']]);
                            break;
                            
                        default:
                            log_message("Unknown operation type: " . $operation['operation_type'], 'ERROR');
                            $result = ['success' => false, 'error' => 'Unknown operation type'];
                    }
                    
                }
                
                // Calculate elapsed time and ensure minimum processing time (5 seconds)
                $elapsedTime = microtime(true) - $operationStartTime;
                $minProcessingTime = 5.0; // 5 seconds minimum processing time
                
                if ($elapsedTime < $minProcessingTime) {
                    $sleepTime = ceil(($minProcessingTime - $elapsedTime) * 1000000);
                    log_message("Operation completed too quickly, sleeping for " . ($sleepTime / 1000000) . " seconds to ensure visibility in UI", 'DEBUG', 
                        ['sleep_time' => $sleepTime / 1000000]);
                    usleep($sleepTime);
                }
                
                // Mark as completed
                $queueDb->query("
                    UPDATE operation_queue
                    SET status = :status,
                        completed_at = :now,
                        updated_at = :now,
                        result = :result
                    WHERE id = :id
                ", [
                    ':id' => $operation['id'],
                    ':status' => isset($result['skipped']) && $result['skipped'] ? 'skipped' : ($result['success'] ? 'completed' : 'failed'),
                    ':now' => time(),
                    ':result' => json_encode($result)
                ]);
                // Emit an event for operation completion
                $eventSystem = \Par2Protect\Core\EventSystem::getInstance();
                $eventSystem->addEvent('operation.completed', [
                    'id' => $operation['id'],
                    'operation_type' => $operation['operation_type'],
                    'status' => isset($result['skipped']) && $result['skipped'] ? 'skipped' : ($result['success'] ? 'completed' : 'failed'),
                    'result' => $result,
                    'path' => isset($parameters['path']) ? $parameters['path'] : null,
                    'completed_at' => time()
                ]);
                log_message("Operation event emitted - ID: " . $operation['id'], 'DEBUG');
                
                log_message("Operation processed - ID: " . $operation['id'] .
                           ", Type: " . $operation['operation_type'] .
                           ", Status: " . (isset($result['skipped']) && $result['skipped'] ? 'skipped' : ($result['success'] ? 'completed' : 'failed')) .
                           ", Duration: " . round(microtime(true) - $operationStartTime, 2) . " seconds", 'DEBUG',
                           [
                               'operation_id' => $operation['id'],
                               'operation_type' => $operation['operation_type'],
                               'status' => isset($result['skipped']) && $result['skipped'] ? 'skipped' : ($result['success'] ? 'completed' : 'failed'),
                               'duration' => round(microtime(true) - $operationStartTime, 2)
                           ]);
                
                // Check if there are more pending operations
                $result = $queueDb->query("
                    SELECT COUNT(*) as count FROM operation_queue
                    WHERE status = 'pending'
                ");
                $row = $queueDb->fetchOne($result);
                $pendingCount = $row['count'];
                
                // Check if we just completed a verification operation
                if ($operation['operation_type'] === 'verify') {
                    // Check if this was the last verification task in a batch
                    $result = $queueDb->query("
                        SELECT COUNT(*) as count FROM operation_queue
                        WHERE operation_type = 'verify'
                        AND status = 'pending'
                    ");
                    $row = $queueDb->fetchOne($result);
                    $pendingVerifyCount = $row['count'];
                    
                    // If no more pending verification tasks, check if we should send a notification
                    if ($pendingVerifyCount === 0) {
                        // Get all recently completed verification tasks (last 10 minutes)
                        $recentTime = time() - 600; // 10 minutes ago
                        $result = $queueDb->query("
                            SELECT COUNT(*) as total,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                            FROM operation_queue
                            WHERE operation_type = 'verify'
                            AND status IN ('completed', 'failed')
                            AND completed_at >= :recent_time
                        ", [':recent_time' => $recentTime]);
                        
                        $stats = $queueDb->fetchOne($result);
                        
                        // If we have completed verification tasks, send a notification
                        if ($stats && $stats['total'] > 0) {
                            log_message("All verification tasks completed. Sending notification.", 'DEBUG');
                            
                            // Get details of failed operations if any
                            $failedItems = [];
                            if ($stats['failed'] > 0) {
                                $result = $queueDb->query("
                                    SELECT parameters FROM operation_queue
                                    WHERE operation_type = 'verify'
                                    AND status = 'failed'
                                    AND completed_at >= :recent_time
                                ", [':recent_time' => $recentTime]);
                                
                                $failedOps = $queueDb->fetchAll($result);
                                foreach ($failedOps as $op) {
                                    $params = json_decode($op['parameters'], true);
                                    if (isset($params['path'])) {
                                        $failedItems[] = $params['path'];
                                    }
                                }
                            }
                            
                            // Get details of items with issues (damaged, missing, error)
                            $problemItems = [];
                            try {
                                // Use main database connection ($db) instead of queue database ($queueDb)
                                // since protected_items table exists in the main database
                                $result = $db->query("
                                    SELECT path, last_status FROM protected_items
                                    WHERE last_status IN ('DAMAGED', 'MISSING', 'ERROR', 'REPAIR_FAILED')
                                    AND last_verified >= :recent_time
                                ", [':recent_time' => date('Y-m-d H:i:s', $recentTime)]);
                                
                                $problemOps = $db->fetchAll($result);
                            } catch (\Exception $e) {
                                // Log the error but continue processing
                                log_message("Error querying problem items: " . $e->getMessage(), 'WARNING');
                                $problemOps = [];
                            }
                            foreach ($problemOps as $op) {
                                $problemItems[] = [
                                    'path' => $op['path'],
                                    'status' => $op['last_status']
                                ];
                            }
                            
                            // Check if notifications are enabled
                            $notificationsEnabled = $config->get('notifications.enabled', true);
                            
                            if ($notificationsEnabled) {
                                // Prepare notification message
                                $subject = "PAR2Protect Verification Results";
                                
                                // Check if we have any problem items
                                $hasProblems = !empty($problemItems);
                                
                                if ($hasProblems) {
                                    $description = "Verification completed with issues for " . $stats['total'] . " items";
                                    $severity = "warning";
                                    $message = "Verification completed. " . $stats['success'] . " operations succeeded, " . $stats['failed'] . " operations failed.\n\n";
                                    
                                    if (!empty($problemItems)) {
                                        $message .= "Items with issues:\n";
                                        foreach ($problemItems as $item) {
                                            $message .= "- " . $item['path'] . " (" . $item['status'] . ")\n";
                                        }
                                        $message .= "\n";
                                    }
                                    
                                    if (!empty($failedItems)) {
                                        $message .= "Failed operations:\n";
                                        foreach ($failedItems as $item) {
                                            $message .= "- $item\n";
                                        }
                                    }
                                } else if ($stats['failed'] > 0) {
                                    $description = "Verification completed with failed operations for " . $stats['total'] . " items";
                                    $severity = "warning";
                                    $message = "Verification completed with issues. " . $stats['success'] . " succeeded, " . $stats['failed'] . " failed.\n\nFailed operations:\n";
                                    foreach ($failedItems as $item) {
                                        $message .= "- $item\n";
                                    }
                                } else {
                                    $description = "Verification completed for " . $stats['total'] . " items";
                                    $severity = "normal";
                                    $message = "All " . $stats['total'] . " verification tasks completed successfully.";
                                }
                                
                                // Send notification using Unraid notification system
                                $notifyScript = "/usr/local/emhttp/plugins/dynamix/scripts/notify";
                                $event = "par2protect_verification";
                                
                                // Build the command
                                $command = "$notifyScript";
                                $command .= " -e \"$event\"";
                                $command .= " -s \"$subject\"";
                                $command .= " -d \"$description\"";
                                $command .= " -i \"$severity\"";
                                $command .= " -m \"$message\"";
                                $command .= " -l \"PAR2Protect\"";
                                
                                // Execute the command
                                log_message("Sending notification: $command", 'DEBUG', ['command' => $command]);
                                exec($command, $notifyOutput, $notifyReturnCode);
                                
                                if ($notifyReturnCode === 0) {
                                    log_message("Notification sent successfully", 'INFO', ['return_code' => $notifyReturnCode]);
                                } else {
                                    log_message("Failed to send notification. Return code: $notifyReturnCode", 'ERROR', ['return_code' => $notifyReturnCode]);
                                }
                            } else {
                                log_message("Notifications are disabled in settings, skipping notification", 'DEBUG');
                            }
                        }
                    }
                }
                
                if ($pendingCount > 0) {
                    log_message("Found $pendingCount more pending operations, restarting queue processor", 'DEBUG');
                    
                    // Release our lock
                    if (is_resource($lockFp)) {
                        flock($lockFp, LOCK_UN);
                        fclose($lockFp);
                    }
                    
                    // Start a new queue processor immediately
                    $processorPath = __FILE__;
                    $command = "nohup php $processorPath " .
                              ">> /boot/config/plugins/par2protect/queue_processor.log 2>&1 &";
                    exec($command);
                    
                    log_message("Started new queue processor to handle pending operations", 'DEBUG');
                    exit(0);
                }
            }
        } else {
            log_message("Maximum concurrent operations limit reached, skipping processing", 'DEBUG');
        }
    } else {
        log_message("No pending operations in queue", 'DEBUG');
    }
    
    // Phase 4.2: Queue management improvements - prioritize operations
    // If we have pending operations, prioritize them based on type
    $queueDb->query("
        UPDATE operation_queue
        SET updated_at = CASE
            WHEN operation_type = 'verify' THEN updated_at + 10 -- Lower priority for verify operations
            ELSE updated_at                -- Keep normal priority for other operations
        END WHERE status = 'pending'");
} catch (Exception $e) {
    log_message("Error processing queue: " . $e->getMessage(), 'ERROR');
    
    // If we have an active operation, mark it as failed
    if (isset($operation) && $operation) {
        try {
            // Clear any alarm that might be set
            pcntl_alarm(0);
            
            // Mark the operation as failed
            $queueDb->query("
                UPDATE operation_queue
                SET status = 'failed',
                    completed_at = :now,
                    updated_at = :now,
                    result = :result
                WHERE id = :id
            ", [
                ':id' => $operation['id'],
                ':now' => time(),
                ':result' => json_encode([
                    'success' => false,
                    'error' => 'Operation failed with error: ' . $e->getMessage()
                ])
            ]);
            
            log_message("Marked operation as failed due to error - ID: " . $operation['id'], 'DEBUG');
        } catch (Exception $innerException) {
            log_message("Failed to mark operation as failed: " . $innerException->getMessage(), 'ERROR');
        }
    }
}

// Lock file cleanup is handled by the shutdown function registered earlier