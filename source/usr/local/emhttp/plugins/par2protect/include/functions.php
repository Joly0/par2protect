<?php
namespace Par2Protect;

class Functions {
    /**
     * Format file size for display
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    public static function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get all protected paths
     * 
     * @return array Protected paths
     */
    public static function getProtectedPaths() {
        $config = Config::getInstance();
        $paths = $config->get('protection.paths', []);
        return is_array($paths) ? $paths : [];
    }
    
    /**
     * Check if path is protected
     * 
     * @param string $path Path to check
     * @return bool
     */
    public static function isPathProtected($path) {
        $paths = self::getProtectedPaths();
        foreach ($paths as $protectedPath) {
            if (strpos($path, $protectedPath) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get file extension
     * 
     * @param string $filename Filename
     * @return string Extension
     */
    public static function getExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Check if file should be protected based on extension
     * 
     * @param string $filename Filename
     * @return bool
     */
    public static function shouldProtectFile($filename) {
        $config = Config::getInstance();
        $protectedTypes = $config->get('protection.file_types', []);
        $extension = self::getExtension($filename);
        return in_array($extension, $protectedTypes);
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
        $cpu = sys_getloadavg();
        $memory = [];
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match_all('/^(.+?):\s+(\d+)/m', $meminfo, $matches);
            $memory = array_combine($matches[1], $matches[2]);
        }
        
        return [
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
    }
}