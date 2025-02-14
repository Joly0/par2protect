<?php
namespace Par2Protect;

class VerificationManager {
    private static $instance = null;
    private $logger;
    private $fileOps;
    private $config;
    private $db;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->fileOps = FileOperations::getInstance();
        $this->config = Config::getInstance();
        $this->db = DatabaseManager::getInstance();
    }

    private function batchUpdateVerificationStatuses($verificationResults, $target) {
        $verificationTasks = [];
        
        foreach ($verificationResults as $vr) {
            try {
                $this->updateStatus($vr['path'], $vr['result']['status'], [
                    'basepath' => dirname($vr['par2_path']),
                    'par2_path' => $vr['par2_path'],
                    'operation_id' => $vr['operation_id'],
                    'output' => $vr['result']['output'],
                    'details' => $vr['result']['details']
                ]);
                
                $verificationTasks[] = [
                    'path' => $vr['path'],
                    'operation_id' => $vr['operation_id'],
                    'status' => $vr['result']['status'],
                    'details' => $vr['result']['details']
                ];
            } catch (\Exception $e) {
                $this->logger->error("Failed to update verification status for path", [
                    'path' => $vr['path'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        return $verificationTasks;
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function verify($target = null, $force = false) {
        try {
            $this->logger->info("Starting verification task", [
                'target' => $target,
                'force' => $force
            ]);
            
            // Get items to verify
            if ($target === 'all') {
                $result = $this->db->query("SELECT * FROM protected_items");
                $items = [];
                while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                    $items[] = $row;
                }
            } else if ($target) {
                $stmt = $this->db->query(
                    "SELECT * FROM protected_items WHERE path = :path",
                    ['path' => $target]
                );
                $items = [$stmt->fetchArray(\SQLITE3_ASSOC)];
                
                if (!$items[0]) {
                    throw new Exceptions\VerificationException(
                        "Path not found in protection database",
                        $target,
                        'verify'
                    );
                }
            } else {
                // Get items needing verification
                $result = $this->db->query(
                    "SELECT * FROM protected_items WHERE last_verified IS NULL OR
                    strftime('%s', 'now') - strftime('%s', last_verified) > :interval",
                    ['interval' => 24 * 60 * 60] // 24 hours in seconds
                );
                $items = [];
                while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                    $items[] = $row;
                }
            }
            
            if (empty($items) && !$force) {
                return [
                    'message' => 'No items need verification at this time',
                    'stats' => [
                        'total_files' => 0,
                        'verified_files' => 0,
                        'failed_files' => 0
                    ]
                ];
            }
            
            $verificationTasks = [];
            $stats = [
                'total_files' => count($items),
                'verified_files' => 0,
                'failed_files' => 0,
                'skipped_files' => 0
            ];

            $this->logger->debug("Starting verification with stats", [
                'total_files' => $stats['total_files'],
                'items' => array_map(function($item) { return $item['path']; }, $items)
            ]);
            
            // Collect all verification results first
            $verificationResults = [];
            
            foreach ($items as $item) {
                try {
                    $operationId = Functions::generateOperationId();
                    $par2 = Par2::getInstance();
                    $result = $par2->verify(
                        $item['path'],
                        $item['par2_path'] ?? dirname($item['path']) . '/.parity/' . basename($item['path']) . '.par2',
                        $operationId,
                        ['basepath' => $item['basepath'] ?? dirname($item['path'])]
                    );
                    
                    $this->logger->info("Verification completed", [
                        'path' => $item['path'],
                        'operation_id' => $operationId,
                        'result' => $result
                    ]);
                    
                    $verificationResults[] = [
                        'path' => $item['path'],
                        'operation_id' => $operationId,
                        'result' => $result
                    ];
                    
                    // Update stats based on verification result
                    switch ($result['status']) {
                        case 'PROTECTED':
                            // Use verified_count from details if available
                            if (isset($result['details']['verified_count'])) {
                                $stats['verified_files'] += $result['details']['verified_count'];
                            } else {
                                $stats['verified_files']++;
                            }
                            break;
                        case 'DAMAGED':
                        case 'MISSING':
                            $stats['failed_files']++;
                            break;
                        case 'UNKNOWN':
                        default:
                            $this->logger->error("Verification failed", [
                                'path' => $item['path'],
                                'error' => $result['output']
                            ]);
                            $stats['failed_files']++;
                            break;
                    }

                    $this->logger->debug("Updated verification stats", [
                        'path' => $item['path'],
                        'status' => $result['status'],
                        'verified' => $stats['verified_files'],
                        'failed' => $stats['failed_files']
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to verify item", [
                        'path' => $item['path'],
                        'exception' => $e
                    ]);
                    $stats['failed_files']++;
                }
            }
            
            // Update database with verification results
            if (!empty($verificationResults)) {
                $verificationTasks = $this->batchUpdateVerificationStatuses($verificationResults, $target);
            }
            
            $response = [
                'success' => true,
                'tasks' => $verificationTasks,
                'stats' => [
                    'total_files' => $stats['total_files'],
                    'verified_files' => $stats['verified_files'],
                    'failed_files' => $stats['failed_files']
                ],
                'message' => sprintf(
                    "Verification completed:\nFiles processed: %d\nVerified: %d\nFailed: %d",
                    $stats['total_files'],
                    $stats['verified_files'],
                    $stats['failed_files']
                )
            ];

            $this->logger->info("Verification completed", [
                'total_files' => $stats['total_files'],
                'verified_files' => $stats['verified_files'],
                'failed_files' => $stats['failed_files'],
                'tasks' => count($verificationTasks)
            ]);
            return $response;
            
        } catch (Exceptions\VerificationException $e) {
            $this->logger->error("Verification error", [
                'target' => $target,
                'context' => $e->getContext(),
                'exception' => $e
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to start verification tasks", [
                'target' => $target,
                'exception' => $e
            ]);
            throw new Exceptions\VerificationException(
                "Failed to start verification: " . $e->getMessage(),
                $target,
                'verify',
                null,
                0,
                $e
            );
        }
    }
    
    public function updateStatus($path, $status, $details = null) {
        try {
            $this->logger->info("Updating verification status", [
                'path' => $path,
                'status' => $status,
                'details' => $details
            ]);

            // First get or create protected item
            $stmt = $this->db->query(
                "SELECT id, path, last_verified, last_status FROM protected_items WHERE path = :path",
                ['path' => $path]
            );
            $item = $this->db->fetchOne($stmt);
            
            $itemId = null;
            if ($item) {
                $itemId = $item['id'];
                // Update existing item
                $updateSql = "UPDATE protected_items SET
                    last_verified = datetime('now'),
                    last_status = :status
                    WHERE id = :id";
                
                $this->db->query($updateSql, [
                    'id' => $itemId,
                    'status' => $status
                ]);
            } else {
                // Insert new item
                $this->db->query(
                    "INSERT INTO protected_items (path, mode, redundancy, protected_date, last_verified, last_status)
                    VALUES (:path, 'file', 5, datetime('now'), datetime('now'), :status)",
                    [
                        'path' => $path,
                        'status' => $status
                    ]
                );
                $itemId = $this->db->lastInsertId();
            }

            // Add verification history entry
            $this->db->query(
                "INSERT INTO verification_history
                (protected_item_id, verification_date, status, details)
                VALUES (:item_id, datetime('now'), :status, :details)",
                [
                    'item_id' => $itemId,
                    'status' => $status,
                    'details' => $details ? json_encode($details) : null
                ]
            );

            // If damaged, try to repair
            if ($status === 'DAMAGED') {
                $stmt = $this->db->query(
                    "SELECT * FROM protected_items WHERE path = :path",
                    ['path' => $path]
                );
                $item = $stmt->fetchArray(\SQLITE3_ASSOC);
                
                if ($item) {
                    $operationId = Functions::generateOperationId();
                    $par2 = Par2::getInstance();
                    $result = $par2->repair($path, $item['par2_path'], $operationId);
                    
                    if ($result) {
                        $this->logger->info("Started repair task", [
                            'path' => $path,
                            'operation_id' => $operationId
                        ]);
                    } else {
                        throw new Exceptions\VerificationException(
                            "Failed to start repair task",
                            $path,
                            'repair'
                        );
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to update verification status", [
                'path' => $path,
                'status' => $status,
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    public function cancel($operationId) {
        try {
            $this->logger->info("Cancelling verification task", [
                'operation_id' => $operationId
            ]);
            
            $par2 = Par2::getInstance();
            $result = $par2->cancel($operationId);
            
            if (!$result) {
                throw new Exceptions\VerificationException(
                    "Failed to cancel verification task",
                    null,
                    'cancel',
                    ['operation_id' => $operationId]
                );
            }
            
            $this->logger->info("Verification task cancelled", [
                'operation_id' => $operationId
            ]);
            
            return true;
            
        } catch (Exceptions\VerificationException $e) {
            $this->logger->error("Verification cancellation error", [
                'operation_id' => $operationId,
                'context' => $e->getContext(),
                'exception' => $e
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to cancel verification task", [
                'operation_id' => $operationId,
                'exception' => $e
            ]);
            throw new Exceptions\VerificationException(
                "Failed to cancel verification: " . $e->getMessage(),
                null,
                'cancel',
                ['operation_id' => $operationId],
                0,
                $e
            );
        }
    }
    
    public function getStatus($path = null) {
        try {
            $items = [];
            if ($path) {
                $stmt = $this->db->query(
                    "SELECT * FROM protected_items WHERE path = :path",
                    ['path' => $path]
                );
                $item = $stmt->fetchArray(\SQLITE3_ASSOC);
                if ($item) {
                    $items = [$item];
                }
            } else {
                $items = $this->db->query("SELECT * FROM protected_items")->fetchAll(\SQLITE3_ASSOC);
            }
            
            $status = [];
            foreach ($items as $item) {
                $lastVerified = $item['last_verified'];
                $needsVerification = !$lastVerified || 
                    (strtotime($lastVerified) < (time() - (24 * 60 * 60))); // 24 hours
                
                $status[] = [
                    'path' => $item['path'],
                    'last_verified' => $lastVerified,
                    'status' => $item['last_status'] ?? 'UNKNOWN',
                    'needs_verification' => $needsVerification,
                    'details' => $item['status_details'] ? json_decode($item['status_details'], true) : null
                ];
            }
            
            return $status;
            
        } catch (Exceptions\DatabaseException $e) {
            $this->logger->error("Database error while getting verification status", [
                'path' => $path,
                'context' => $e->getContext(),
                'exception' => $e
            ]);
            throw new Exceptions\VerificationException(
                "Failed to get verification status: Database error",
                $path,
                'status_check',
                null,
                0,
                $e
            );
        } catch (\Exception $e) {
            $this->logger->error("Failed to get verification status", [
                'path' => $path,
                'exception' => $e
            ]);
            throw new Exceptions\VerificationException(
                "Failed to get verification status: " . $e->getMessage(),
                $path,
                'status_check',
                null,
                0,
                $e
            );
        }
    }
}