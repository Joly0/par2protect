<?php
namespace Par2Protect;

class Functions {
    private static $fileOps = null;
    
    private static function getFileOps() {
        if (self::$fileOps === null) {
            self::$fileOps = FileOperations::getInstance();
        }
        return self::$fileOps;
    }
    
    public static function formatSize($bytes) {
        return self::getFileOps()->formatSize($bytes);
    }
    
    public static function getProtectedPaths() {
        try {
            $db = DatabaseManager::getInstance();
            $result = $db->query("SELECT path FROM protected_items");
            $paths = [];
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $paths[] = $row['path'];
            }
            return $paths;
        } catch (\Exception $e) {
            $logger = Logger::getInstance();
            $logger->error("Failed to get protected paths", ['exception' => $e]);
            return [];
        }
    }
    
    public static function isPathProtected($path) {
        try {
            $db = DatabaseManager::getInstance();
            $result = $db->query("SELECT path FROM protected_items");
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                if (strpos($path, $row['path']) === 0) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            $logger = Logger::getInstance();
            $logger->error("Failed to check if path is protected", ['exception' => $e]);
            return false;
        }
    }
    
    public static function getExtension($filename) {
        return self::getFileOps()->getExtension($filename);
    }
    
    public static function shouldProtectFile($filename) {
        return self::getFileOps()->shouldProtectFile($filename);
    }
    
    /**
     * Generate operation ID
     * 
     * @return string Unique operation ID
     */
    public static function generateOperationId() {
        return uniqid('op_', true);
    }
    
    /**
     * Check system resource usage
     * 
     * @return array Resource usage information
     */
    public static function getSystemResources() {
        $logger = Logger::getInstance();
        
        try {
            $cpu = sys_getloadavg();
            $memory = [];
            
            if (is_readable('/proc/meminfo')) {
                $meminfo = file_get_contents('/proc/meminfo');
                preg_match_all('/^(.+?):\s+(\d+)/m', $meminfo, $matches);
                $memory = array_combine($matches[1], $matches[2]);
            }
            
            $resources = [
                'cpu' => [
                    'load_1' => $cpu[0],
                    'load_5' => $cpu[1],
                    'load_15' => $cpu[2]
                ],
                'memory' => [
                    'total' => $memory['MemTotal'] ?? 0,
                    'free' => $memory['MemFree'] ?? 0,
                    'available' => $memory['MemAvailable'] ?? 0
                ]
            ];
            
            $logger->debug("System resources retrieved", $resources);
            return $resources;
            
        } catch (\Exception $e) {
            $logger->error("Failed to get system resources", ['exception' => $e]);
            return [
                'cpu' => ['load_1' => 0, 'load_5' => 0, 'load_15' => 0],
                'memory' => ['total' => 0, 'free' => 0, 'available' => 0]
            ];
        }
    }

    public static function getProtectionStats() {
        $logger = Logger::getInstance();
        $fileOps = self::getFileOps();
        
        try {
            $logger->debug("Getting protection stats");
            $db = DatabaseManager::getInstance();
            $result = $db->query("SELECT * FROM protected_items");
            $items = [];
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $items[] = $row;
            }
            $logger->debug("Retrieved protected items", ['count' => count($items)]);
            
            $totalFiles = 0;
            $totalSize = 0;
            $lastVerification = null;
            $verificationErrors = 0;
            $healthCounts = [
                'PROTECTED' => 0,
                'DAMAGED' => 0,
                'MISSING' => 0,
                'ERROR' => 0,
                'UNKNOWN' => 0
            ];
            
            foreach ($items as $item) {
                if ($item['mode'] === 'file') {
                    $totalFiles++;
                    $totalSize += $item['size'];
                } else if ($item['mode'] === 'directory') {
                    $totalFiles += substr_count($item['file_types'] ?? '', ',') + 1;
                    $totalSize += $item['size'];
                }
                
                $status = $item['last_status'] ?? 'UNKNOWN';
                $healthCounts[$status] = ($healthCounts[$status] ?? 0) + 1;
                
                if (in_array($status, ['DAMAGED', 'ERROR'])) {
                    $verificationErrors++;
                }
                
                if (!empty($item['last_verified'])) {
                    if (!$lastVerification || strtotime($item['last_verified']) > strtotime($lastVerification)) {
                        $lastVerification = $item['last_verified'];
                    }
                }
            }
            
            $health = 'unknown';
            if (!empty($items)) {
                if ($healthCounts['PROTECTED'] === count($items)) {
                    $health = 'good';
                } else if ($healthCounts['DAMAGED'] > 0 || $healthCounts['ERROR'] > 0) {
                    $health = 'error';
                } else if ($healthCounts['MISSING'] > 0) {
                    $health = 'warning';
                }
            }
            
            $stats = [
                'total_files' => $totalFiles,
                'total_size' => $fileOps->formatSize($totalSize),
                'health' => $health,
                'last_verification' => $lastVerification,
                'verification_errors' => $verificationErrors,
                'health_details' => $healthCounts
            ];
            
            $logger->debug("Protection stats calculated", $stats);
            return $stats;
            
        } catch (\Exception $e) {
            $logger->error("Failed to get protection stats", ['exception' => $e]);
            return [
                'total_files' => 0,
                'total_size' => '0 B',
                'health' => 'unknown',
                'last_verification' => null,
                'verification_errors' => 0,
                'health_details' => []
            ];
        }
    }
}