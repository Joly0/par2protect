<?php
namespace Par2Protect\Services;

use Par2Protect\Core\Database;
use Par2Protect\Core\QueueDatabase;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Exceptions\ApiException;

class Queue {
    private $db;
    private $logger;
    private $queueDb;
    private $config;
    
    /**
     * Queue service constructor
     */
    public function __construct(
        Database $db,
        QueueDatabase $queueDb,
        Logger $logger,
        Config $config
    ) {
        $this->db = $db;
        $this->queueDb = $queueDb;
        $this->logger = $logger;
        $this->config = $config;

        // Ensure queue table exists
        $this->queueDb->initializeQueueTable();
    }
    
    /**
     * Initialize queue table
     *
     * @return void
     */
    private function initializeQueueTable() {} // Deprecated - now handled by QueueDatabase
    
    /**
     * Add operation to queue
     *
     * @param string $type Operation type (protect, verify, remove)
     * @param array $parameters Operation parameters
     * @return array
     */
    public function addOperation($type, $parameters) {
        $this->logger->debug("Adding operation to queue", [
            'type' => $type,
            'parameters' => $parameters
        ]);
        
        // Add diagnostic logging for protection operations
        if ($type === 'protect' && isset($parameters['file_types'])) {
            $this->logger->debug("DIAGNOSTIC: Protection operation details", [
                'path' => $parameters['path'] ?? 'unknown',
                'file_types' => is_array($parameters['file_types']) ?
                    json_encode($parameters['file_types']) : $parameters['file_types'],
                'file_types_type' => gettype($parameters['file_types']),
                'file_types_count' => is_array($parameters['file_types']) ?
                    count($parameters['file_types']) : 'not an array',
                'file_categories' => isset($parameters['file_categories']) ?
                    (is_array($parameters['file_categories']) ?
                    json_encode($parameters['file_categories']) : $parameters['file_categories']) : 'not set'
            ]);
        }

        // Add diagnostic logging for verification operations
        if ($type === 'verify') {
            $this->logger->debug("DIAGNOSTIC: Verification operation details", [
                'id' => $parameters['id'] ?? 'not set',
                'path' => $parameters['path'] ?? 'unknown',
                'force' => isset($parameters['force']) ? ($parameters['force'] ? 'true' : 'false') : 'not set',
                'force_type' => isset($parameters['force']) ? gettype($parameters['force']) : 'not set',
                'verify_metadata' => isset($parameters['verify_metadata']) ? ($parameters['verify_metadata'] ? 'true' : 'false') : 'not set',
                'auto_restore_metadata' => isset($parameters['auto_restore_metadata']) ? ($parameters['auto_restore_metadata'] ? 'true' : 'false') : 'not set',
                'parameters_json' => json_encode($parameters)
            ]);
        }
        
        // Add diagnostic logging for repair operations
        if ($type === 'repair') {
            $this->logger->debug("DIAGNOSTIC: Repair operation details", [
                'id' => $parameters['id'] ?? 'not set',
                'path' => $parameters['path'] ?? 'unknown',
                'restore_metadata' => isset($parameters['restore_metadata']) ? ($parameters['restore_metadata'] ? 'true' : 'false') : 'not set',
                'parameters_json' => json_encode($parameters)
            ]);
        }
        
        try {
            // Validate operation type
            $validTypes = ['protect', 'verify', 'remove', 'repair'];
            if (!in_array($type, $validTypes)) {
                throw ApiException::badRequest("Invalid operation type: $type");
            }
            
            // Validate parameters - either path or id must be provided
            if ((!isset($parameters['path']) || empty($parameters['path'])) &&
                (!isset($parameters['id']) || empty($parameters['id']))) {
                throw ApiException::badRequest("Either path or id parameter is required");
            }
            
            // Prepare the parameters outside the transaction to minimize transaction duration
            $now = time();
            $encodedParameters = json_encode($parameters);
            
            // Begin transaction - QueueDatabase handles retries internally via queryWithRetry and busy_timeout
            $this->queueDb->beginTransaction();

            $result = $this->queueDb->query(
                "INSERT INTO operation_queue (operation_type, parameters, status, created_at, updated_at)
                VALUES (:type, :parameters, 'pending', :now, :now)",
                [
                    ':type' => $type,
                    ':parameters' => $encodedParameters,
                    ':now' => $now
                ]
            );

            // Get operation ID within the same transaction
            $operationId = $this->queueDb->lastInsertId();
            $this->queueDb->commit();

            $this->logger->debug("Operation added to queue successfully", [
                'operation_id' => $operationId,
                'type' => $type,
                'operation_type' => $type,
                'id' => $parameters['id'] ?? null,
                'path' => $parameters['path'] ?? null,
                'status' => 'Success',
                'action' => ucfirst($type)
            ]);
            
            // Start queue processor
            $this->startQueueProcessor();
            
            return [
                'success' => true,
                'operation_id' => $operationId,
                'message' => 'Operation added to queue'
            ];
            
        } catch (ApiException $e) {
            // Rollback if an API exception occurred during the transaction
            if ($this->queueDb->inTransaction()) {
                $this->queueDb->rollback();
            }
            throw $e;
        } catch (\Exception $e) { // Catch generic exceptions (including DatabaseException for locks from QueueDatabase)
            if ($this->queueDb->inTransaction()) {
                $this->queueDb->rollback();
            }

            // Log the original exception details for better debugging
            $this->logger->error("Caught exception while adding operation to queue", [
                'exception_class' => get_class($e),
                'original_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                // Optionally include stack trace if needed, but can be verbose
                // 'trace' => $e->getTraceAsString()
            ]);

            // Re-throw as ApiException for consistent API error handling
            // Let QueueDatabase's retry handle the lock, otherwise throw API exception
            throw new ApiException("Failed to add operation to queue: " . $e->getMessage(), 500, $e); // Pass original exception
        }
    }
    
    /**
     * Get all operations
     *
     * @param int $limit Maximum number of operations to return
     * @return array
     */
    public function getAllOperations($limit = 10) {
        try {
            $result = $this->queueDb->query(
                "SELECT * FROM operation_queue 
                ORDER BY 
                    CASE 
                        WHEN status = 'pending' THEN 0
                        WHEN status = 'processing' THEN 1
                        ELSE 2
                    END,
                    CASE WHEN operation_type = 'remove' THEN 0 ELSE 1 END, -- Prioritize remove operations
                    created_at DESC 
                LIMIT :limit",
                [':limit' => $limit]
            );
            $operations = $this->queueDb->fetchAll($result);
            
            $formattedOperations = array_map([$this, 'formatOperation'], $operations);
            
            return $formattedOperations;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get operations", [
                'error' => $e->getMessage()
            ]);
            
            throw new ApiException("Failed to get operations: " . $e->getMessage());
        }
    }
    
    /**
     * Get operation status
     *
     * @param int $operationId Operation ID
     * @return array
     */
    public function getOperationStatus($operationId) {
        try {
            $result = $this->queueDb->query(
                "SELECT * FROM operation_queue WHERE id = :id",
                [':id' => $operationId]
            );
            $operation = $this->queueDb->fetchOne($result);
            
            if (!$operation) {
                throw ApiException::notFound("Operation not found: $operationId");
            }
            
            return $this->formatOperation($operation);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get operation status", [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
            
            throw new ApiException("Failed to get operation status: " . $e->getMessage());
        }
    }
    
    /**
     * Get active operations
     *
     * @return array
     */
    public function getActiveOperations() {
        try {
            // Include processing, pending, and recently finished (within 60 seconds) operations
            $recentTime = time() - 60; // 60 seconds ago

            $result = $this->queueDb->query(
                "SELECT * FROM operation_queue
                WHERE status IN ('processing', 'pending')
                   OR (status IN ('completed', 'failed', 'skipped', 'cancelled') AND completed_at >= :recent_time)
                ORDER BY
                    CASE
                        WHEN status = 'pending' THEN 0
                        WHEN status = 'processing' THEN 1
                        ELSE 2 -- Recently finished statuses
                    END,
                    created_at ASC", // Keep secondary sort by creation time
                [':recent_time' => $recentTime] // Bind the recent time parameter
            );
            
            $operations = $this->queueDb->fetchAll($result);
            
            $formattedOperations = array_map([$this, 'formatOperation'], $operations);
            
            return $formattedOperations;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get active operations", [
                'error' => $e->getMessage()
            ]);
            
            throw new ApiException("Failed to get active operations: " . $e->getMessage());
        }
    }
    
    /**
     * Cancel operation
     *
     * @param int $operationId Operation ID
     * @return array
     */
    public function cancelOperation($operationId) {
        try {
            // Prepare the result JSON outside the transaction
            $now = time();
            $resultJson = json_encode(['success' => false, 'error' => 'Operation cancelled by user']);
            $this->queueDb->beginTransaction();
            
            // Get operation
            $result = $this->queueDb->query(
                "SELECT * FROM operation_queue WHERE id = :id",
                [':id' => $operationId]
            );
            $operation = $this->queueDb->fetchOne($result);
            
            if (!$operation) {
                $this->queueDb->rollback();
                throw ApiException::notFound("Operation not found: $operationId");
            }
            
            // If operation is processing, try to kill the process
            if ($operation['status'] === 'processing' && $operation['pid']) {
                // Send SIGTERM to process
                posix_kill($operation['pid'], 15);
                
                // Wait a moment
                sleep(1);
                
                // Check if process is still running
                if (posix_kill($operation['pid'], 0)) {
                    // Process still running, send SIGKILL
                    posix_kill($operation['pid'], 9);
                }
            }
            
            // Update operation status
            $this->queueDb->query(
                "UPDATE operation_queue SET status = 'cancelled', updated_at = :now, completed_at = :now, result = :result
                WHERE id = :id",
                [
                    ':id' => $operationId,
                    ':now' => $now,
                    ':result' => $resultJson
                ]
            );
            
            $this->queueDb->commit();
            
            $this->logger->debug("Operation cancelled", [
                'operation_id' => $operationId,
                'action' => 'Cancel',
                'operation_type' => 'cancel',
                'status' => 'Success'
            ]);
            
            return [
                'success' => true,
                'message' => 'Operation cancelled'
            ];
        } catch (ApiException $e) {
            if (isset($this->queueDb) && $this->queueDb->inTransaction) {
                $this->queueDb->rollback();
            }
            throw $e;
        } catch (\Exception $e) {
            if (isset($this->queueDb) && $this->queueDb->inTransaction) {
                $this->queueDb->rollback();
            }
            
            $this->logger->error("Failed to cancel operation", [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
            
            throw new ApiException("Failed to cancel operation: " . $e->getMessage());
        }
    }
    
    /**
     * Format operation for response
     *
     * @param array $operation Operation from database
     * @return array
     */
    private function formatOperation($operation) {
        $parameters = json_decode($operation['parameters'], true);
        $result = $operation['result'] ? json_decode($operation['result'], true) : null;
        
        // Extract path from parameters if available
        $path = null;
        $id = null;
        if ($parameters && isset($parameters['path'])) {
            $path = $parameters['path'];
        }
        if ($parameters && isset($parameters['id'])) {
            $id = $parameters['id'];
        }
        
        return [
            'id' => $operation['id'],
            'operation_type' => $operation['operation_type'],
            'parameters' => $parameters,
            'path' => $path, // Add path directly to the operation object
            'item_id' => $id, // Add item ID directly to the operation object
            'status' => $operation['status'],
            'created_at' => date('Y-m-d H:i:s', $operation['created_at']),
            'started_at' => $operation['started_at'] ? date('Y-m-d H:i:s', $operation['started_at']) : null,
            'completed_at' => $operation['completed_at'] ? date('Y-m-d H:i:s', $operation['completed_at']) : null,
            'result' => $result
        ];
    }
    /**
     * Start queue processor
     *
     * @return void
     */
    private function startQueueProcessor() {
        // Get processor path from config
        $processorPath = $this->config->get('queue.processor_path', '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php');
        
        // Check if there's already a queue processor running
        $lockFile = '/boot/config/plugins/par2protect/queue/processor.lock';
        $processorRunning = false;
        
        if (file_exists($lockFile)) {
            // Read the PID from the lock file
            $pid = trim(file_get_contents($lockFile));
            
            // Check if the process is still running
            if ($pid && file_exists("/proc/$pid")) {
                $processorRunning = true;
                $this->logger->debug("Queue processor already running with PID: $pid");
            }
        }
        
        // Only start a new processor if one isn't already running
        if (!$processorRunning) {
            // Get the temporary log directory from config
            $tmpLogPathSetting = $this->config->get('logging.tmp_path', '/tmp/par2protect/logs/par2protect.log');
            $tmpLogDir = dirname($tmpLogPathSetting);
            $queueLogFile = $tmpLogDir . '/queue_processor.log';

            // Ensure the temporary log directory exists
            if (!is_dir($tmpLogDir)) {
                @mkdir($tmpLogDir, 0755, true);
                $this->logger->debug("Created temporary log directory for queue processor", ['directory' => $tmpLogDir]);
            }

            // Execute the queue processor script in the background, logging to tmp
            $command = "nohup php $processorPath " .
                      ">> " . escapeshellarg($queueLogFile) . " 2>&1 &"; // Use escapeshellarg for safety
            exec($command);

            $this->logger->debug("Queue processor started", [
                'command' => $command,
                'log_file' => $queueLogFile
            ]);
        }
    }
    
    /**
     * Clean up old operations
     *
     * @param int $days Number of days to keep operations
     * @return int Number of operations deleted
     */
    public function cleanupOldOperations($days = 7) {
        try {
            $this->queueDb->beginTransaction();
            
            $cutoff = time() - ($days * 86400);
            
            $result = $this->queueDb->query(
                "DELETE FROM operation_queue 
                WHERE completed_at IS NOT NULL 
                AND completed_at < :cutoff 
                AND status IN ('completed', 'failed', 'cancelled', 'skipped')",
                [':cutoff' => $cutoff]
            );
            
            $count = $this->queueDb->changes();
            
            $this->queueDb->commit();
            
            $this->logger->debug("Cleaned up old operations", [
                'count' => $count,
                'days' => $days,
                'action' => 'Cleanup',
                'operation_type' => 'cleanup',
                'status' => 'Success'
            ]);
            
            return $count;
        } catch (\Exception $e) {
            if (isset($this->queueDb) && $this->queueDb->inTransaction) {
                $this->queueDb->rollback();
            }
            
            $this->logger->error("Failed to clean up old operations", [
                'error' => $e->getMessage()
            ]);
            
            throw new ApiException("Failed to clean up old operations: " . $e->getMessage());
        }
    }
}