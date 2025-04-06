<?php
namespace Par2Protect\Core;

/**
 * Cache Class
 * 
 * This class provides caching functionality for frequently accessed data.
 * It uses the filesystem to store cache data in /tmp for improved performance.
 */
class Cache {
    // private static $instance = null; // Removed for DI
    private $cacheDir;
    private $logger;
    
    /**
     * Private constructor to enforce singleton pattern
     */
    // Make constructor public and inject Logger
    public function __construct(Logger $logger) {
        $this->logger = $logger;
        $this->cacheDir = '/tmp/par2protect/cache';
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            $this->logger->debug("Cache directory does not exist, creating: {$this->cacheDir}");
            if (!@mkdir($this->cacheDir, 0755, true)) {
                $this->logger->error("Failed to create cache directory: {$this->cacheDir}");
            } else {
                $this->logger->debug("Cache directory created successfully: {$this->cacheDir}");
            }
        }
        
        // Check if the cache directory is writable
        if (!is_writable($this->cacheDir)) {
            $this->logger->error("Cache directory is not writable: {$this->cacheDir}");
            // Try to fix permissions
            @chmod($this->cacheDir, 0777);
            if (!is_writable($this->cacheDir)) {
                $this->logger->error("Failed to make cache directory writable: {$this->cacheDir}");
            } else {
                $this->logger->debug("Cache directory permissions fixed: {$this->cacheDir}");
            }
        } else {
            $this->logger->debug("Cache directory is writable: {$this->cacheDir}");
        }
    }
    
    /**
     * Get singleton instance
     *
     * @return Cache
     */
    // Removed getInstance() method
    
    /**
     * Set a value in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (default: 3600 = 1 hour)
     * @return bool Success
     */
    public function set($key, $value, $ttl = 3600) {
        $cacheFile = $this->getCacheFilePath($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        $result = file_put_contents($cacheFile, serialize($data));
        
        if ($result === false) {
            $this->logger->error("Failed to write cache file", [
                'key' => $key,
                'file' => $cacheFile
            ]);
            return false;
        }
        
        // Cache set operations are not logged to reduce noise
        
        return true;
    }
    
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found or expired
     * @return mixed Cached value or default
     */
    public function get($key, $default = null) {
        if (!$this->has($key)) {
            return $default;
        }
        
        $cacheFile = $this->getCacheFilePath($key);
        $data = unserialize(file_get_contents($cacheFile));
        
        // Cache hits are not logged to reduce noise
        
        return $data['value'];
    }
    
    /**
     * Check if a key exists in the cache and is not expired
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has($key) {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $data = unserialize(file_get_contents($cacheFile));
        
        if (!isset($data['expires']) || !isset($data['value'])) {
            $this->logger->warning("Invalid cache data format", [
                'key' => $key,
                'file' => $cacheFile
            ]);
            return false;
        }
        
        // Check if cache has expired
        if ($data['expires'] < time()) {
            // Cache expiration is not logged to reduce noise
            return false;
        }
        
        return true;
    }
    
    /**
     * Remove a key from the cache
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function remove($key) {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (!file_exists($cacheFile)) {
            return true;
        }
        
        $result = @unlink($cacheFile);
        
        if ($result) {
            // Cache removal is not logged to reduce noise
        } else {
            $this->logger->error("Failed to remove cache", [
                'key' => $key,
                'file' => $cacheFile
            ]);
        }
        
        return $result;
    }
    
    /**
     * Clear all cache entries
     *
     * @return bool Success
     */
    public function clear() {
        // Check if the cache directory exists
        if (!is_dir($this->cacheDir)) {
            $this->logger->error("Cache directory does not exist: {$this->cacheDir}");
            return 0;
        }
        
        // Check if the cache directory is writable
        if (!is_writable($this->cacheDir)) {
            $this->logger->error("Cache directory is not writable: {$this->cacheDir}");
            return 0;
        }
        
        $files = glob($this->cacheDir . '/*');
        $count = 0;
        $failed = 0;
        
        $this->logger->debug("Clearing cache", [
            'cache_dir' => $this->cacheDir,
            'files_found' => count($files),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        if (empty($files)) {
            $this->logger->debug("No cache files found to clear");
            
            // Create a test file to verify write access
            $testFile = $this->cacheDir . '/test_' . time() . '.txt';
            if (file_put_contents($testFile, 'test')) {
                @unlink($testFile);
            } else {
                $this->logger->error("Failed to create test file: {$testFile}");
            }
            
            return 0;
        }
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $this->logger->debug("Removing cache file: {$file}");
                if (@unlink($file)) {
                    $count++;
                } else {
                    $failed++;
                    $this->logger->warning("Failed to remove cache file", [
                        'file' => $file,
                        'error' => error_get_last()
                    ]);
                }
            }
        }
        
        $this->logger->debug("Cache cleared", [
            'files_removed' => $count,
            'files_failed' => $failed,
            'total_files' => count($files),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return $count;
    }
    
    /**
     * Clean expired cache entries
     *
     * @return int Number of expired entries removed
     */
    public function cleanExpired() {
        $files = glob($this->cacheDir . '/*');
        $count = 0;
        
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            
            $data = unserialize(file_get_contents($file));
            
            if (!isset($data['expires'])) {
                // Invalid cache entry, remove it
                @unlink($file);
                $count++;
                continue;
            }
            
            if ($data['expires'] < time()) {
                // Expired cache entry, remove it
                @unlink($file);
                $count++;
            }
        }
        
        // Expired cache entries cleaning is not logged to reduce noise
        
        return $count;
    }
    
    /**
     * Get cache file path for a key
     *
     * @param string $key Cache key
     * @return string Cache file path
     */
    private function getCacheFilePath($key) {
       // Create a safe filename from the key
       $safeKey = md5($key);
       return $this->cacheDir . '/' . $safeKey . '.cache';
   }
   
   /**
    * Get the cache file path for a key (public method)
    *
    * @param string $key Cache key
    * @return string Cache file path
    */
   public function getPublicCacheFilePath($key) {
       $path = $this->getCacheFilePath($key);
       $this->logger->debug("Cache file path for key", [
           'key' => $key,
           'path' => $path,
           'exists' => file_exists($path) ? 'true' : 'false',
           'writable' => is_writable(dirname($path)) ? 'true' : 'false',
           'dir_exists' => is_dir(dirname($path)) ? 'true' : 'false'
       ]);
       return $path;
   }
}