<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Database;
use Par2Protect\Services\Queue;

class StatusEndpoint {
    private $config;
    private $logger;
    private $db;
    private $queueService;
    
    /**
     * StatusEndpoint constructor
     */
    public function __construct() {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();
        $this->db = Database::getInstance();
        $this->queueService = new Queue();
    }
    
    /**
     * Get system status
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getStatus($params) {
        try {
            // Get par2 version
            $par2Version = $this->getPar2Version();
            
            // Get database status
            $dbStatus = $this->getDatabaseStatus();
            
            // Get queue status
            $queueStatus = $this->getQueueStatus();
            
            // Get disk usage
            $diskUsage = $this->getDiskUsage();
            
            // Get recent activity
            $recentActivity = $this->getRecentActivity();
            
            // Get last verification date
            $lastVerification = 'Never';
            try {
                $result = $this->db->query("
                    SELECT MAX(verification_date) as last_date
                    FROM verification_history
                ");
                $row = $this->db->fetchOne($result);
                if ($row && $row['last_date']) {
                    $lastVerification = $row['last_date'];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to get last verification date", [
                    'error' => $e->getMessage()
                ]);
            }
            
            // Calculate protection health
            $health = 'unknown';
            try {
                // Get total count of protected items
                $result = $this->db->query("
                    SELECT COUNT(*) as total_count
                    FROM protected_items
                ");
                $totalRow = $this->db->fetchOne($result);
                $totalItems = $totalRow['total_count'];
                
                // Get count of items with healthy status (PROTECTED, VERIFIED, or REPAIRED)
                $result = $this->db->query("
                    SELECT COUNT(*) as healthy_count
                    FROM protected_items
                    WHERE last_status IN ('PROTECTED', 'VERIFIED', 'REPAIRED')
                ");
                $healthyRow = $this->db->fetchOne($result);
                $healthyItems = $healthyRow['healthy_count'];
                
                /* $this->logger->debug("Health calculation", [
                    'total_items' => $totalItems,
                    'healthy_items' => $healthyItems
                ]); */
                
                if ($totalItems > 0) {
                    $healthPercent = ($healthyItems / $totalItems) * 100;
                    
                    if ($healthPercent >= 90) {
                        $health = 'good';
                    } else if ($healthPercent >= 70) {
                        $health = 'warning';
                    } else {
                        $health = 'critical';
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to calculate protection health", [
                    'error' => $e->getMessage()
                ]);
            }
            
            // Create stats object expected by dashboard
            $stats = [
                'total_files' => $dbStatus['tables']['protected_items'],
                'total_size' => $diskUsage['protected_items']['size_formatted'],
                'last_verification' => $lastVerification,
                'health' => $health
            ];
            
            // Extract active operations from queue
            $activeOperations = $queueStatus['active_operations'] ?? [];
            
            // Combine all status information
            $status = [
                'version' => '2.0.0',
                'par2_version' => $par2Version,
                'database' => $dbStatus,
                'queue' => $queueStatus,
                'disk_usage' => $diskUsage,
                'recent_activity' => $recentActivity,
                'stats' => $stats,
                'active_operations' => $activeOperations
            ];
            
            Response::success($status);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get system status: " . $e->getMessage());
        }
    }
    
    /**
     * Get par2 version
     *
     * @return string
     */
    private function getPar2Version() {
        try {
            exec('par2 -V 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0 || empty($output)) {
                return 'Unknown';
            }
            
            // Extract version from output
            $versionLine = $output[0];
            if (preg_match('/par2cmdline\s+version\s+([0-9.]+)/i', $versionLine, $matches)) {
                return $matches[1];
            }
            
            return $versionLine;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get par2 version", [
                'error' => $e->getMessage()
            ]);
            
            return 'Error';
        }
    }
    
    /**
     * Get database status
     *
     * @return array
     */
    private function getDatabaseStatus() {
        try {
            // Get database file path
            $dbPath = $this->config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
            
            // Check if database file exists
            $exists = file_exists($dbPath);
            
            // Get database size
            $size = $exists ? filesize($dbPath) : 0;
            
            // Get table counts
            $protectedItemsCount = 0;
            $verificationHistoryCount = 0;
            $operationQueueCount = 0;
            
            if ($exists) {
                $result = $this->db->query("SELECT COUNT(*) as count FROM protected_items");
                $row = $this->db->fetchOne($result);
                $protectedItemsCount = $row['count'];
                
                $result = $this->db->query("SELECT COUNT(*) as count FROM verification_history");
                $row = $this->db->fetchOne($result);
                $verificationHistoryCount = $row['count'];
                
                $result = $this->db->query("SELECT COUNT(*) as count FROM operation_queue");
                $row = $this->db->fetchOne($result);
                $operationQueueCount = $row['count'];
            }
            
            return [
                'exists' => $exists,
                'path' => $dbPath,
                'size' => $size,
                'size_formatted' => $this->formatSize($size),
                'tables' => [
                    'protected_items' => $protectedItemsCount,
                    'verification_history' => $verificationHistoryCount,
                    'operation_queue' => $operationQueueCount
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get database status", [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get queue status
     *
     * @return array
     */
    private function getQueueStatus() {
        try {
            // Get queue counts
            $result = $this->db->query("
                SELECT status, COUNT(*) as count
                FROM operation_queue
                GROUP BY status
            ");
            $statusCounts = $this->db->fetchAll($result);
            
            // Format counts
            $counts = [
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'cancelled' => 0
            ];
            
            foreach ($statusCounts as $row) {
                $counts[$row['status']] = $row['count'];
            }
            
            // Get active operations
            $activeOperations = $this->queueService->getActiveOperations();
            
            return [
                'counts' => $counts,
                'active_operations' => $activeOperations
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get queue status", [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get disk usage
     *
     * @return array
     */
    private function getDiskUsage() {
        try {
            // Get par2 files directory
            $parityDir = $this->config->get('protection.parity_dir', '.parity');
            
            // Get all protected items
            $result = $this->db->query("SELECT * FROM protected_items");
            $items = $this->db->fetchAll($result);
            
            // Calculate total size of protected items and par2 files
            $totalProtectedSize = 0;
            $totalPar2Size = 0;
            
            foreach ($items as $item) {
                $totalProtectedSize += $item['size'];
                
                // Get par2 files size
                $par2Path = $item['par2_path'];
                if (file_exists($par2Path)) {
                    $par2Dir = dirname($par2Path);
                    $par2Base = pathinfo($par2Path, PATHINFO_FILENAME);
                        // Only match files with .par2 extension for consistency
                    $par2Files = glob($par2Dir . '/' . $par2Base . '*.par2');
                    
                    foreach ($par2Files as $file) {
                        if (file_exists($file)) {
                            $totalPar2Size += filesize($file);
                        }
                    }
                }
            }
            
            return [
                'protected_items' => [
                    'count' => count($items),
                    'size' => $totalProtectedSize,
                    'size_formatted' => $this->formatSize($totalProtectedSize)
                ],
                'par2_files' => [
                    'size' => $totalPar2Size,
                    'size_formatted' => $this->formatSize($totalPar2Size)
                ],
                'ratio' => $totalProtectedSize > 0 ? round(($totalPar2Size / $totalProtectedSize) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get disk usage", [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get recent activity
     *
     * @return array
     */
    private function getRecentActivity() {
        try {
            // Include System actions since they may be the only ones available
            return $this->logger->getRecentActivity(5, null, true);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get recent activity", [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Format size in human-readable format
     *
     * @param int $bytes Size in bytes
     * @return string
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}