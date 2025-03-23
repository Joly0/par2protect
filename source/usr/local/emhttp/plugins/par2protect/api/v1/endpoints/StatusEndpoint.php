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
            // Use a static variable to cache the entire status response
            static $cachedStatus = null;
            static $cacheTime = 0;
            static $cacheExpiry = 5; // Cache for 5 seconds by default
            
            // Force refresh if requested
            $forceRefresh = isset($params['refresh']) && $params['refresh'] === 'true';
            
            // Use cached response if available and not expired
            if (!$forceRefresh && $cachedStatus !== null && (time() - $cacheTime) < $cacheExpiry) {
                Response::success($cachedStatus);
                return;
            }
            
            // Get par2 version (already cached internally)
            $par2Version = $this->getPar2Version();
            
            // Get database status (already cached internally)
            $dbStatus = $this->getDatabaseStatus();
            
            // Get queue status (already cached internally)
            $queueStatus = $this->getQueueStatus();
            
            // Get disk usage (already cached internally)
            $diskUsage = $this->getDiskUsage();
            
            // Get recent activity (already cached internally)
            $recentActivity = $this->getRecentActivity();
            
            // Get last verification date with caching
            static $cachedLastVerification = null;
            static $lastVerificationCacheTime = 0;
            
            $lastVerification = 'Never';
            if (!$forceRefresh && $cachedLastVerification !== null && (time() - $lastVerificationCacheTime) < 60) {
                $lastVerification = $cachedLastVerification;
            } else {
                try {
                    $result = $this->db->query("
                        SELECT MAX(verification_date) as last_date
                        FROM verification_history
                    ");
                    $row = $this->db->fetchOne($result);
                    if ($row && $row['last_date']) {
                        $lastVerification = $row['last_date'];
                    }
                    $cachedLastVerification = $lastVerification;
                    $lastVerificationCacheTime = time();
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to get last verification date", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Calculate protection health with caching
            static $cachedHealth = null;
            static $healthCacheTime = 0;
            
            $health = 'unknown';
            if (!$forceRefresh && $cachedHealth !== null && (time() - $healthCacheTime) < 60) {
                $health = $cachedHealth;
            } else {
                try {
                    // Get health stats with a single query instead of multiple queries
                    $result = $this->db->query("
                        SELECT
                            COUNT(*) as total_count,
                            SUM(CASE WHEN last_status IN ('PROTECTED', 'VERIFIED', 'REPAIRED') THEN 1 ELSE 0 END) as healthy_count
                        FROM protected_items
                    ");
                    $row = $this->db->fetchOne($result);
                    $totalItems = $row['total_count'];
                    $healthyItems = $row['healthy_count'];
                    
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
                    $cachedHealth = $health;
                    $healthCacheTime = time();
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to calculate protection health", [
                        'error' => $e->getMessage()
                    ]);
                }
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
            
            // Cache the status
            $cachedStatus = $status;
            $cacheTime = time();
            
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
            // Use a static variable to cache the result
            static $cachedVersion = null;
            
            // Par2 version won't change during the lifetime of the plugin,
            // so we can cache it indefinitely
            if ($cachedVersion !== null) {
                return $cachedVersion;
            }
            
            exec('par2 -V 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0 || empty($output)) {
                $cachedVersion = 'Unknown';
                return $cachedVersion;
            }
            
            // Extract version from output
            $versionLine = $output[0];
            if (preg_match('/par2cmdline\s+version\s+([0-9.]+)/i', $versionLine, $matches)) {
                $cachedVersion = $matches[1];
                return $cachedVersion;
            }
            
            $cachedVersion = $versionLine;
            return $cachedVersion;
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
            // Use a static variable to cache the result for the duration of the request
            static $cachedDbStatus = null;
            static $cacheTime = 0;
            
            // Cache database status for 30 seconds to reduce file I/O and database queries
            if ($cachedDbStatus !== null && (time() - $cacheTime) < 30) {
                return $cachedDbStatus;
            }
            
            // Get database file path
            $dbPath = $this->config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
            
            // Check if database file exists
            $exists = file_exists($dbPath);
            
            // Get database size
            $size = $exists ? filesize($dbPath) : 0;
            
            // Get table counts with a single query instead of multiple queries
            $protectedItemsCount = 0;
            $verificationHistoryCount = 0;
            $operationQueueCount = 0;
            
            if ($exists) {
                // Use a single query to get all table counts
                $tables = ['protected_items', 'verification_history', 'operation_queue'];
                $counts = [];
                
                foreach ($tables as $table) {
                    $result = $this->db->query("SELECT COUNT(*) as count FROM $table");
                    $row = $this->db->fetchOne($result);
                    $counts[$table] = $row['count'];
                }
                
                $protectedItemsCount = $counts['protected_items'];
                $verificationHistoryCount = $counts['verification_history'];
                $operationQueueCount = $counts['operation_queue'];
            }
            
            $cachedDbStatus = [
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
            $cacheTime = time();
            
            return $cachedDbStatus;
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
            // Use a static variable to cache the result for the duration of the request
            static $cachedQueueStatus = null;
            static $cacheTime = 0;
            
            // Cache queue status for 5 seconds to reduce database queries
            if ($cachedQueueStatus !== null && (time() - $cacheTime) < 5) {
                return $cachedQueueStatus;
            }
            
            // Get queue counts with a single query
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
            
            $cachedQueueStatus = [
                'counts' => $counts,
                'active_operations' => $activeOperations
            ];
            $cacheTime = time();
            
            return $cachedQueueStatus;
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
            // Use a more efficient query to get aggregated data directly from the database
            // This avoids fetching all items and looping through them
            $result = $this->db->query("
                SELECT
                    COUNT(*) as item_count,
                    SUM(size) as total_size,
                    SUM(par2_size) as total_par2_size
                FROM protected_items
            ");
            
            $row = $this->db->fetchOne($result);
            
            $totalProtectedSize = $row['total_size'] ?? 0;
            $totalPar2Size = $row['total_par2_size'] ?? 0;
            $itemCount = $row['item_count'] ?? 0;
            
            return [
                'protected_items' => [
                    'count' => $itemCount,
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
            // Use a static variable to cache the result for the duration of the request
            static $cachedActivity = null;
            static $cacheTime = 0;
            
            // Cache activity for 10 seconds to reduce file I/O
            if ($cachedActivity !== null && (time() - $cacheTime) < 10) {
                return $cachedActivity;
            }
            
            // Include System actions since they may be the only ones available
            $cachedActivity = $this->logger->getRecentActivity(5, null, true);
            $cacheTime = time();
            
            return $cachedActivity;
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