<?php
namespace Par2Protect;

class ProtectionManager {
    private static $instance = null;
    private $logger;
    private $fileOps;
    private $config;
    private $db;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->fileOps = FileOperations::getInstance();
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Start protection task for a path
     */
    public function protect($path, $redundancy = null) {
        try {
            $this->logger->info("Starting protection task", [
                'path' => $path,
                'redundancy' => $redundancy
            ]);
            
            // Validate path
            $this->logger->info("Validating path", ['path' => $path]);
            if (!file_exists($path)) {
                throw new \Exception("Path does not exist: $path");
            }
            
            // Get redundancy setting
            $this->logger->info("Getting redundancy setting");
            if ($redundancy === null) {
                $redundancy = $this->config->get('protection.default_redundancy', 5);
                $this->logger->info("Redundancy is null, setting default redundancy", ['redundancy' => $redundancy]);
            }
            
            // Generate operation ID
            $this->logger->info("Generating operation ID");
            $operationId = Functions::generateOperationId();
            
            // Determine mode and prepare parameters
            $this->logger->info("Determining mode and preparing parameters");
            $mode = is_dir($path) ? 'directory' : 'file';
            $this->logger->info("Mode determined", ['mode' => $mode]);
            $size = $mode === 'directory' ? 
                $this->fileOps->getDirectorySize($path) : 
                filesize($path);
            $this->logger->info("Size determined", ['size' => $size]);
            
            // Get file types for directory
            $fileTypes = null;
            if ($mode === 'directory') {
                $this->logger->info("Getting file types for directory");
                $protectedTypes = $this->config->get('protection.file_types', []);
                $files = $this->fileOps->getFiles($path, $protectedTypes);
                $fileTypes = implode(',', array_map([$this->fileOps, 'getExtension'], $files));
                $this->logger->info("File types determined", ['fileTypes' => $fileTypes]);
            }
            
            // Generate par2 path
            $this->logger->info("Generating par2 path");
            $par2Path = rtrim($path, '/') . '/.parity';
            $this->logger->info("Par2 path generated", ['par2Path' => $par2Path]);
            
            // Ensure par2 directory exists
            $this->logger->info("Ensuring par2 directory exists");
            $this->fileOps->ensureDirectory($par2Path);
            
            // Get database instance for transaction
            $db = Database::getInstance();
            
            // Start transaction
            $db->exec('BEGIN TRANSACTION');
            
            try {
                // Add to database
                $this->logger->info("Adding to database", [
                    'path' => $path,
                    'mode' => $mode,
                    'redundancy' => $redundancy,
                    'par2Path' => $par2Path,
                    'fileTypes' => $fileTypes,
                    'request_id' => $operationId
                ]);
                
                // Add to database using instance method
                $stmt = $db->prepare("
                    INSERT INTO protected_items
                    (path, mode, redundancy, protected_date, par2_path, file_types, size)
                    VALUES (:path, :mode, :redundancy, datetime('now', 'localtime'), :par2_path, :file_types, :size)
                ");

                $size = is_dir($path) ? $this->fileOps->getDirectorySize($path) : filesize($path);
                
                $stmt->bindValue(':path', $path, SQLITE3_TEXT);
                $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
                $stmt->bindValue(':redundancy', $redundancy, SQLITE3_INTEGER);
                $stmt->bindValue(':par2_path', $par2Path, SQLITE3_TEXT);
                $stmt->bindValue(':file_types', $fileTypes, SQLITE3_TEXT);
                $stmt->bindValue(':size', $size, SQLITE3_INTEGER);
                
                $stmt->execute();
                $this->logger->info("Database entry added successfully", ['request_id' => $operationId]);
                
                // Start protection process
                $this->logger->info("Starting protection process", [
                    'path' => $path,
                    'par2Path' => $par2Path,
                    'redundancy' => $redundancy,
                    'operationId' => $operationId,
                    'request_id' => $operationId
                ]);

                // Get Par2 instance
                $par2 = Par2::getInstance();
                $this->logger->debug("Got Par2 instance", ['request_id' => $operationId]);

                // Start protection
                $result = $par2->protect($path, $par2Path, $redundancy, $operationId);
                $this->logger->debug("Protection command executed", [
                    'result' => $result,
                    'command' => $result['command'] ?? 'unknown',
                    'output' => $result['output'] ?? 'no output',
                    'status' => $result['status'] ?? 'unknown',
                    'request_id' => $operationId
                ]);

                if (!$result || !isset($result['success']) || !$result['success']) {
                    $error = "Failed to start protection process";
                    if (isset($result['output'])) {
                        $error .= ": " . $result['output'];
                    }
                    if (isset($result['status'])) {
                        $error .= " (Status: " . $result['status'] . ")";
                    }
                    throw new \Exception($error);
                }

                // Update verification status using instance method
                $stmt = $db->prepare("
                    UPDATE protected_items
                    SET last_verified = datetime('now', 'localtime'),
                        last_status = :status
                    WHERE path = :path
                ");
                
                $stmt->bindValue(':path', $path, SQLITE3_TEXT);
                $stmt->bindValue(':status', 'PROTECTED', SQLITE3_TEXT);
                $stmt->execute();
                
                // Add to verification history
                $stmt = $db->prepare("
                    INSERT INTO verification_history
                    (protected_item_id, verification_date, status, details)
                    SELECT id, datetime('now', 'localtime'), :status, :details
                    FROM protected_items
                    WHERE path = :path
                ");
                
                $stmt->bindValue(':path', $path, SQLITE3_TEXT);
                $stmt->bindValue(':status', 'PROTECTED', SQLITE3_TEXT);
                $stmt->bindValue(':details', 'Initial protection successful', SQLITE3_TEXT);
                $stmt->execute();
                
                $this->logger->info("Updated verification status to PROTECTED", ['request_id' => $operationId]);

                // If we got here, everything succeeded, so commit the transaction
                $db->exec('COMMIT');
                
                $this->logger->info("Protection process completed successfully", [
                    'path' => $path,
                    'par2Path' => $par2Path,
                    'request_id' => $operationId
                ]);

                return [
                    'success' => true,
                    'operation_id' => $operationId,
                    'command' => $result['command'],
                    'output' => $result['output']
                ];
                
            } catch (\Exception $e) {
                // Rollback transaction on any error
                $db->exec('ROLLBACK');
                
                $this->logger->error("Protection process failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request_id' => $operationId,
                    'path' => $path,
                    'par2Path' => $par2Path
                ]);
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to start protection task", [
                'path' => $path,
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    /**
     * Cancel protection task
     */
    public function cancel($operationId) {
        try {
            $this->logger->info("Cancelling protection task", [
                'operation_id' => $operationId
            ]);
            
            $par2 = Par2::getInstance();
            $result = $par2->cancel($operationId);
            
            if (!$result) {
                throw new \Exception("Failed to cancel protection task");
            }
            
            $this->logger->info("Protection task cancelled", [
                'operation_id' => $operationId
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to cancel protection task", [
                'operation_id' => $operationId,
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    /**
     * Remove protection from path
     */
    public function remove($path) {
        try {
            $this->logger->info("Removing protection", ['path' => $path]);
            
            // Remove from database
            $this->db->removeProtectedItem($path);
            
            // Remove par2 files
            $par2Path = rtrim($path, '/') . '/.parity';
            
            if (is_dir($par2Path)) {
                $this->fileOps->cleanupFiles($par2Path . '/*.par2');
                @rmdir($par2Path);
            }
            
            $this->logger->info("Protection removed", ['path' => $path]);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protection", [
                'path' => $path,
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    /**
     * Get protection status
     */
    public function getStatus($path = null) {
        try {
            if ($path) {
                $items = [$this->db->getProtectedItem($path)];
            } else {
                $items = $this->db->getProtectedItems();
            }
            
            $status = [];
            foreach ($items as $item) {
                if (!$item) continue;
                
                $itemStatus = [
                    'path' => $item['path'],
                    'mode' => $item['mode'],
                    'size' => $this->fileOps->formatSize($item['size']),
                    'redundancy' => $item['redundancy'],
                    'status' => $item['last_status'] ?? 'UNKNOWN',
                    'last_verified' => $item['last_verified']
                ];
                
                if ($item['mode'] === 'directory') {
                    $itemStatus['file_types'] = explode(',', $item['file_types'] ?? '');
                }
                
                $status[] = $itemStatus;
            }
            
            return $status;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protection status", [
                'path' => $path,
                'exception' => $e
            ]);
            throw $e;
        }
    }
}