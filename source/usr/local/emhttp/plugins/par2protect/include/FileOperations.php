<?php
namespace Par2Protect;

class FileOperations {
    private static $instance = null;
    private $logger;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get directory size recursively
     */
    public function getDirectorySize($path) {
        try {
            $size = 0;
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
            return $size;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get directory size", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Get file extension
     */
    public function getExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Format file size for display
     */
    public function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Check if file should be protected based on extension
     */
    public function shouldProtectFile($filename) {
        $config = Config::getInstance();
        $protectedTypes = $config->get('protection.file_types', []);
        $extension = $this->getExtension($filename);
        return in_array($extension, $protectedTypes);
    }
    
    /**
     * Ensure directory exists and is writable
     */
    public function ensureDirectory($path, $mode = 0755) {
        if (!is_dir($path)) {
            if (!@mkdir($path, $mode, true)) {
                $error = error_get_last();
                throw new \Exception("Failed to create directory: " . ($error['message'] ?? 'Unknown error'));
            }
        }
        
        if (!is_writable($path)) {
            if (!@chmod($path, $mode)) {
                throw new \Exception("Failed to make directory writable: $path");
            }
        }
        
        return true;
    }
    
    /**
     * Get all files in directory recursively
     */
    public function getFiles($path, $extensions = null) {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    if ($extensions === null || in_array($this->getExtension($file->getPathname()), $extensions)) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to get files", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }
        
        return $files;
    }
    
    /**
     * Clean up old files
     */
    public function cleanupFiles($pattern, $maxAge = 86400) {
        $count = 0;
        try {
            foreach (glob($pattern) as $file) {
                if (is_file($file) && (time() - filemtime($file)) > $maxAge) {
                    if (@unlink($file)) {
                        $count++;
                    }
                }
            }
            
            if ($count > 0) {
                $this->logger->info("Cleaned up old files", [
                    'pattern' => $pattern,
                    'count' => $count
                ]);
            }
            
            return $count;
        } catch (\Exception $e) {
            $this->logger->error("Failed to cleanup files", [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}