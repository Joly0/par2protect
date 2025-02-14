<?php
namespace Par2Protect\Core;

/**
 * Cache Class
 * 
 * This class provides caching functionality for frequently accessed data.
 * It uses the filesystem to store cache data in /tmp for improved performance.
 */
class Cache {
    private static $instance = null;
    private $cacheDir;
    private $logger;
    
    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->cacheDir = '/tmp/par2protect/cache';
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            if (!@mkdir($this->cacheDir, 0755, true)) {
                $this->logger->error("Failed to create cache directory: {$this->cacheDir}");
            } else {
                $this->logger->debug("Created cache directory: {$this->cacheDir}");
            }
        }
    }
    
    /**
     * Get singleton instance
     *
     * @return Cache
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
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
        
        $this->logger->debug("Cache set", [
            'key' => $key,
            'ttl' => $ttl,
            'expires' => date('Y-m-d H:i:s', time() + $ttl)
        ]);
        
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
        
        $this->logger->debug("Cache hit", [
            'key' => $key
        ]);
        
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
            $this->logger->debug("Cache expired", [
                'key' => $key,
                'expired_at' => date('Y-m-d H:i:s', $data['expires'])
            ]);
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
            $this->logger->debug("Cache removed", [
                'key' => $key
            ]);
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
        $files = glob($this->cacheDir . '/*');
        $count = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        
        $this->logger->info("Cache cleared", [
            'files_removed' => $count
        ]);
        
        return true;
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
        
        $this->logger->debug("Expired cache entries cleaned", [
            'entries_removed' => $count
        ]);
        
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
}