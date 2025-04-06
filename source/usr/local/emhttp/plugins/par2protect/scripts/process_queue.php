<?php
/**
 * Queue Processor Script
 * This script processes operations from the queue.
 */

// Load bootstrap
error_log("Par2Protect: process_queue.php script started execution."); // Use error_log for pre-bootstrap logging
require_once(__DIR__ . '/../core/bootstrap.php');

// Explicitly include the custom exception to bypass potential autoload issues
require_once(__DIR__ . '/../core/exceptions/Par2FilesExistException.php');

// Required Services (Type hints primarily)
use Par2Protect\Services\Protection;
use Par2Protect\Services\Verification;
use Par2Protect\Core\Database;
use Par2Protect\Core\QueueDatabase;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

// --- Global Variables ---
$logger = null;
$config = null;


/**
 * Check if a database operation is safe to perform
 */
function is_database_operation_safe(Database $db, QueueDatabase $queueDb, $operationType) {
    global $logger;
    try {
        if (in_array($operationType, ['remove'])) {
            $db->getSQLite()->busyTimeout(500); $db->query("PRAGMA quick_check"); $db->getSQLite()->busyTimeout(5000);
        } return true;
    } catch (\Exception $e) {
        $logger->warning("Database appears to be under contention, delaying operation: " . $e->getMessage());
        try { $db->getSQLite()->busyTimeout(5000); } catch (\Exception $_) {}
        return false;
    }
}

// --- Main Script Execution ---
$container = get_container();
$logger = $container->get('logger'); // Assign to global
$config = $container->get('config'); // Assign to global

// Enable console output for this script
$logger->enableStdoutLogging(true);
$db = $container->get('database');
$queueDb = $container->get('queueDb');
$eventSystem = $container->get('eventSystem');

$logger->debug("Debug logging enabled: " . ($config->get('debug.debug_logging', false) ? 'true' : 'false'));
$logger->debug("Starting queue processor");

$lockFile = '/tmp/par2protect/locks/processor.lock';
$myPid = getmypid();
$logger->debug("Current process PID: $myPid");
$queueDir = dirname($lockFile);
if (!is_dir($queueDir)) { $logger->debug("Creating queue directory: $queueDir"); @mkdir($queueDir, 0755, true); }

$lockFp = fopen($lockFile, 'c+');
if (!$lockFp) { $logger->error("Could not open lock file: $lockFile"); exit(1); }
if (!flock($lockFp, LOCK_EX | LOCK_NB)) { $logger->debug("Queue processor already running, exiting"); fclose($lockFp); exit(0); }

$logger->debug("Successfully acquired lock file: " . $lockFile);
ftruncate($lockFp, 0); fwrite($lockFp, $myPid); fflush($lockFp); touch($lockFile, time() + 60);

register_shutdown_function(function() use ($lockFp, $lockFile, $myPid, $queueDb, $logger) {
    $logger->debug("Cleaning up lock file for PID: $myPid");
    if (is_resource($lockFp)) { flock($lockFp, LOCK_UN); fclose($lockFp); }
    if (file_exists($lockFile)) { @unlink($lockFile); }
    try {
        $result = $queueDb->query("SELECT id FROM operation_queue WHERE status = 'processing' AND pid = :pid", [':pid' => $myPid]);
        $stuckOperations = $queueDb->fetchAll($result);
        if (!empty($stuckOperations)) {
            $logger->warning("Found " . count($stuckOperations) . " operations still marked as processing on shutdown");
            foreach ($stuckOperations as $op) {
                $logger->warning("Marking operation as failed on shutdown - ID: " . $op['id']);
                try {
                    $queueDb->query("UPDATE operation_queue SET status = 'failed', completed_at = :now, updated_at = :now, result = :result WHERE id = :id", [
                        ':id' => $op['id'], ':now' => time(), ':result' => json_encode(['success' => false, 'error' => 'Operation terminated unexpectedly'])
                    ]);
                } catch (\Exception $e) { $logger->error("Failed to mark operation as failed: " . $e->getMessage()); }
            }
        }
    } catch (\Exception $e) { $logger->error("Error checking for stuck operations on shutdown: " . $e->getMessage()); }
    $logger->debug("Queue processor finished");
});

$logger->debug("Lock acquired for PID: $myPid");
$maxExecutionTime = $config->get('queue.max_execution_time', 1800);
$startTime = time();

try {
    while (time() - $startTime < $maxExecutionTime) {
        try { $db->query("PRAGMA quick_check"); } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'database is locked') !== false) { $logger->warning("Main database locked, waiting..."); sleep(5); continue; } else { throw $e; }
        }

        $maxConcurrent = $config->get('resource_limits.max_concurrent_operations', 2);
        $result = $queueDb->query("SELECT COUNT(*) as count FROM operation_queue WHERE status = 'processing'");
        $processingCount = $queueDb->fetchOne($result)['count'] ?? 0;
        $logger->debug("Current processing: $processingCount, Max allowed: $maxConcurrent");
        if ($processingCount >= $maxConcurrent) { $logger->debug("Max concurrent ops reached, waiting..."); sleep(5); continue; }

        $result = $queueDb->query("SELECT * FROM operation_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $operation = $queueDb->fetchOne($result);
        if (!$operation) { $logger->debug("No pending operations found"); break; }

        $logger->debug("Processing operation - ID: " . $operation['id'] . ", Type: " . $operation['operation_type']);
        $queueDb->query("UPDATE operation_queue SET status = 'processing', started_at = :now, updated_at = :now, pid = :pid WHERE id = :id AND status = 'pending'", [':id' => $operation['id'], ':now' => time(), ':pid' => $myPid]);
        if ($queueDb->changes() === 0) { $logger->warning("Op ID " . $operation['id'] . " likely picked up by another process, skipping."); continue; }

        $operationStartTime = microtime(true);
        $opResult = ['success' => false, 'error' => 'Operation failed unexpectedly'];
        $parameters = json_decode($operation['parameters'], true) ?: [];
        $operationPath = $parameters['path'] ?? ($parameters['id'] ?? 'N/A');

        try {
            switch ($operation['operation_type']) {
                case 'protect': // Restored actual logic
                    $protection = $container->get(Protection::class);
                    $advancedSettings = $parameters['advanced_settings'] ?? null;
                    if (!$advancedSettings) $advancedSettings = [];
                    if (isset($parameters['block_count'])) $advancedSettings['block_count'] = $parameters['block_count'];
                    if (isset($parameters['block_size'])) $advancedSettings['block_size'] = $parameters['block_size'];
                    if (isset($parameters['target_size'])) $advancedSettings['target_size'] = $parameters['target_size'];
                    if (isset($parameters['recovery_files'])) $advancedSettings['recovery_files'] = $parameters['recovery_files'];
                    if (empty($advancedSettings)) $advancedSettings = null;
                    $logger->debug("DIAGNOSTIC: Processing protect operation", [ /* context */ ]);
                    $opResult = $protection->protect(
                        $parameters['path'], $parameters['redundancy'] ?? null, $parameters['file_types'] ?? null,
                        $parameters['file_categories'] ?? null, $advancedSettings
                    );
                    break;

                case 'verify':
                    $verification = $container->get(Verification::class);
                    $verifyMetadata = isset($parameters['verify_metadata']) ? filter_var($parameters['verify_metadata'], FILTER_VALIDATE_BOOLEAN) : false;
                    $autoRestoreMetadata = isset($parameters['auto_restore_metadata']) ? filter_var($parameters['auto_restore_metadata'], FILTER_VALIDATE_BOOLEAN) : false;
                    $force = isset($parameters['force']) ? filter_var($parameters['force'], FILTER_VALIDATE_BOOLEAN) : false;
                    $logger->debug("DIAGNOSTIC: Verification parameters", [ /* context */ ]);
                    if (isset($parameters['id']) && !empty($parameters['id'])) {
                        $opResult = $verification->verifyById($parameters['id'], $force, $verifyMetadata, $autoRestoreMetadata);
                    } else {
                        $opResult = $verification->verify($parameters['path'], $force, $verifyMetadata, $autoRestoreMetadata);
                    }
                    if (isset($opResult['status']) && $opResult['status'] === 'VERIFIED') $opResult['success'] = true;
                    break;

                case 'repair':
                    $verification = $container->get(Verification::class);
                    $restoreMetadata = isset($parameters['restore_metadata']) ? filter_var($parameters['restore_metadata'], FILTER_VALIDATE_BOOLEAN) : true;
                    $logger->debug("DIAGNOSTIC: Repair parameters", [ /* context */ ]);
                    if (isset($parameters['id']) && !empty($parameters['id'])) {
                        $opResult = $verification->repairById($parameters['id'], $restoreMetadata);
                    } else {
                        $opResult = $verification->repair($parameters['path'], $restoreMetadata);
                    }
                    break;

                case 'remove':
                    if (!is_database_operation_safe($db, $queueDb, 'remove')) { $logger->warning("Delaying remove op due to DB contention"); sleep(3); }
                    $protection = $container->get(Protection::class);
                    $logger->debug("DIAGNOSTIC: Processing remove operation", [ /* context */ ]);
                    if (isset($parameters['id']) && !empty($parameters['id'])) {
                        $opResult = $protection->removeById($parameters['id']);
                    } else {
                         $opResult = $protection->remove($parameters['path']);
                    }
                    break;

                default:
                    $logger->error("Unknown operation type: " . $operation['operation_type']);
                    $opResult = ['success' => false, 'error' => 'Unknown operation type'];
            } // End switch

            // --- Operation Outcome Handling ---
            $elapsedTime = microtime(true) - $operationStartTime;
            $minProcessingTime = 5.0;
            if ($elapsedTime < $minProcessingTime) {
                $sleepTime = ceil(($minProcessingTime - $elapsedTime) * 1000000);
                $logger->debug("Operation completed quickly, sleeping " . ($sleepTime / 1000000) . "s", ['sleep_time' => $sleepTime / 1000000]);
                usleep($sleepTime);
            }

            $finalDbStatus = isset($opResult['skipped']) && $opResult['skipped'] ? 'skipped' : ($opResult['success'] ? 'completed' : 'failed');
            $queueDb->query("UPDATE operation_queue SET status = :status, completed_at = :now, updated_at = :now, result = :result WHERE id = :id", [
                ':id' => $operation['id'], ':status' => $finalDbStatus, ':now' => time(), ':result' => json_encode($opResult)
            ]);

            $eventData = [
                'id' => $operation['id'], 'operation_type' => $operation['operation_type'], 'type' => $operation['operation_type'],
                'status' => $finalDbStatus, 'result' => $opResult, 'path' => $parameters['path'] ?? ($opResult['path'] ?? null),
                'completed_at' => time(), 'completedAt' => time()
            ];
            if (!isset($eventData['path']) && isset($parameters['id'])) {
                 try { $item = $container->get(Protection::class)->getStatusById($parameters['id']); $eventData['path'] = $item['path'] ?? null; } catch (\Exception $_) {}
            }
            $eventSystem->addEvent('operation.completed', $eventData);
            $logger->debug("Operation event emitted - ID: " . $operation['id']);

            $finalDisplayStatus = $opResult['status'] ?? $finalDbStatus;
            $finalDetails = $opResult['details'] ?? ($opResult['error'] ?? ($opResult['message'] ?? null));
            $logger->logOperationActivity(ucwords($operation['operation_type']), ucwords(strtolower($finalDisplayStatus)), $eventData['path'], $finalDetails);

            $logger->debug("Operation processed - ID: " . $operation['id'] . ", Type: " . $operation['operation_type'] . ", Status: " . $finalDisplayStatus . ", Duration: " . round(microtime(true) - $operationStartTime, 2) . "s");

        } catch (\Par2Protect\Core\Exceptions\Par2FilesExistException $e) { // Catch specific "files exist" case
            $errorMessage = $e->getMessage();
            $logger->debug("Operation skipped (files exist) - ID " . ($operation['id'] ?? 'unknown') . ": " . $errorMessage); // Log as INFO

            if (isset($operation) && is_array($operation)) {
                $logger->logOperationActivity(ucwords($operation['operation_type'] ?? 'Unknown'), 'Skipped', $parameters['path'] ?? ($parameters['id'] ?? ($operation['id'] ?? 'N/A')), $errorMessage);
                try {
                    $opResult = ['success' => true, 'skipped' => true, 'message' => $errorMessage]; // Mark as skipped, but technically not a failure
                    $logger->debug("Attempting to update DB status to skipped for ID: " . $operation['id']); // ADDED LOG
                    $queueDb->query("UPDATE operation_queue SET status = 'skipped', completed_at = :now, updated_at = :now, result = :result WHERE id = :id", [ // Set status to 'skipped'
                        ':id' => $operation['id'], ':now' => time(), ':result' => json_encode($opResult)
                    ]);

                    // Emit completion event with skipped status
                    $eventData = [
                        'id' => $operation['id'], 'operation_type' => $operation['operation_type'], 'type' => $operation['operation_type'],
                        'status' => 'skipped', 'result' => $opResult, 'path' => $parameters['path'] ?? ($opResult['path'] ?? null),
                        'completed_at' => time(), 'completedAt' => time()
                    ];
                     if (!isset($eventData['path']) && isset($parameters['id'])) {
                         try { $item = $container->get(Protection::class)->getStatusById($parameters['id']); $eventData['path'] = $item['path'] ?? null; } catch (\Exception $_) {}
                     }
                    $logger->debug("Attempting to add completion event for skipped job ID: " . $operation['id']); // ADDED LOG
                    $eventSystem->addEvent('operation.completed', $eventData);
                    $logger->debug("Successfully added completion event for skipped job ID: " . $operation['id']); // ADDED LOG
                    $logger->debug("Operation event emitted for skipped job - ID: " . $operation['id']);

                } catch (\Exception $dbE) {
                    $logger->error("Failed to mark operation as skipped in DB after Par2FilesExistException: " . $dbE->getMessage());
                }
            } else {
                 // Add this else block for debugging
                 $logger->warning("Skipped DB update/event emission in Par2FilesExistException catch block because 'operation' variable is not set or not an array.", ['operation_isset' => isset($operation), 'operation_is_array' => is_array($operation ?? null)]);
            }
            continue; // Continue to next iteration of the while loop

        } catch (\Exception $e) { // Catch ALL OTHER exceptions during specific operation execution
            $errorMessage = $e->getMessage();
            $logger->error("Error processing operation ID " . ($operation['id'] ?? 'unknown') . ": " . $errorMessage, []);
            if (isset($operation) && is_array($operation)) {
                 $logger->logOperationActivity(ucwords($operation['operation_type'] ?? 'Unknown'), 'Failed', $parameters['path'] ?? ($parameters['id'] ?? ($operation['id'] ?? 'N/A')), $errorMessage);
                try {
                     $queueDb->query("UPDATE operation_queue SET status = 'failed', completed_at = :now, updated_at = :now, result = :result WHERE id = :id", [
                         ':id' => $operation['id'], ':now' => time(), ':result' => json_encode(['success' => false, 'error' => $errorMessage])
                     ]);
                } catch (\Exception $dbE) { $logger->error("Failed to mark operation as failed in DB after exception: " . $dbE->getMessage()); }
            }
            continue;
        } // End main try/catch for operation processing

        $pendingResult = $queueDb->query("SELECT COUNT(*) as count FROM operation_queue WHERE status = 'pending'");
        $pendingCount = $queueDb->fetchOne($pendingResult)['count'] ?? 0;
        if ($pendingCount === 0) { $logger->debug("No more pending operations found after processing ID " . $operation['id']); break; }
        // usleep(100000);

    } // End while loop

} catch (\Exception $e) {
    $logger->critical("Queue processor encountered a fatal error: " . $e->getMessage());
    exit(1);
}

exit(0); // Exit normally