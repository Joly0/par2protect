<?php
namespace Par2Protect;

class ResourceManager {
    private static $instance = null;
    private $logger;
    private $config;
    
    // Resource limits and thresholds
    private $cpuLimit = 80; // Default CPU usage limit (percentage)
    private $memoryLimit = 80; // Default memory usage limit (percentage)
    private $ioLimit = 70; // Default I/O usage limit (percentage)
    
    // Sampling intervals (in seconds)
    private $cpuSampleInterval = 5;
    private $memorySampleInterval = 10;
    private $ioSampleInterval = 15;
    
    // Resource usage history
    private $cpuHistory = [];
    private $memoryHistory = [];
    private $ioHistory = [];
    
    // Maximum history entries to keep
    private $maxHistoryEntries = 60;
    
    private static $initialized = false;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        
        // Load configuration
        $this->loadConfig();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            
            // Only log initialization on main page load
            if (!self::$initialized && basename($_SERVER['SCRIPT_NAME']) === 'template.php') {
                self::$instance->logger->info("ResourceManager initialized", [
                    'cpu_limit' => self::$instance->cpuLimit,
                    'memory_limit' => self::$instance->memoryLimit,
                    'io_limit' => self::$instance->ioLimit
                ]);
                self::$initialized = true;
            }
        }
        return self::$instance;
    }
    
    /**
     * Load resource management configuration
     */
    private function loadConfig() {
        $this->cpuLimit = $this->config->get('resources.cpu_limit', 80);
        $this->memoryLimit = $this->config->get('resources.memory_limit', 80);
        $this->ioLimit = $this->config->get('resources.io_limit', 70);
        
        $this->cpuSampleInterval = $this->config->get('resources.cpu_sample_interval', 5);
        $this->memorySampleInterval = $this->config->get('resources.memory_sample_interval', 10);
        $this->ioSampleInterval = $this->config->get('resources.io_sample_interval', 15);
    }
    
    /**
     * Get current CPU usage percentage
     */
    private function getCpuUsage() {
        $load = sys_getloadavg();
        $cores = $this->getProcessorCount();
        return ($load[0] / $cores) * 100;
    }
    
    /**
     * Get number of processor cores
     */
    private function getProcessorCount() {
        $count = 1; // Default to 1 core
        
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
        }
        
        return max(1, $count);
    }
    
    /**
     * Get current memory usage percentage
     */
    private function getMemoryUsage() {
        $memInfo = $this->getMemoryInfo();
        if ($memInfo === false) {
            return 0;
        }
        
        $used = $memInfo['total'] - $memInfo['free'] - $memInfo['cached'] - $memInfo['buffers'];
        return ($used / $memInfo['total']) * 100;
    }
    
    /**
     * Get detailed memory information
     */
    private function getMemoryInfo() {
        if (!is_file('/proc/meminfo')) {
            return false;
        }
        
        $meminfo = file_get_contents('/proc/meminfo');
        $data = array();
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', $line, $matches)) {
                $data[strtolower($matches[1])] = $matches[2] * 1024; // Convert to bytes
            }
        }
        
        return array(
            'total' => $data['memtotal'] ?? 0,
            'free' => $data['memfree'] ?? 0,
            'cached' => $data['cached'] ?? 0,
            'buffers' => $data['buffers'] ?? 0
        );
    }
    
    /**
     * Get current I/O usage percentage
     */
    private function getIoUsage() {
        static $lastStats = null;
        static $lastTime = null;
        
        $stats = $this->getDiskStats();
        if ($stats === false) {
            return 0;
        }
        
        $currentTime = microtime(true);
        
        if ($lastStats === null || $lastTime === null) {
            $lastStats = $stats;
            $lastTime = $currentTime;
            return 0;
        }
        
        $timeDiff = $currentTime - $lastTime;
        if ($timeDiff < 0.1) { // Minimum 100ms between measurements
            return 0;
        }
        
        $readDiff = ($stats['read_bytes'] - $lastStats['read_bytes']) / $timeDiff;
        $writeDiff = ($stats['write_bytes'] - $lastStats['write_bytes']) / $timeDiff;
        
        $lastStats = $stats;
        $lastTime = $currentTime;
        
        // Calculate percentage based on typical SSD throughput (500MB/s)
        $maxThroughput = 500 * 1024 * 1024; // 500MB/s in bytes
        $currentThroughput = $readDiff + $writeDiff;
        
        return min(100, ($currentThroughput / $maxThroughput) * 100);
    }
    
    /**
     * Get disk I/O statistics
     */
    private function getDiskStats() {
        if (!is_file('/proc/diskstats')) {
            return false;
        }
        
        $stats = array('read_bytes' => 0, 'write_bytes' => 0);
        $diskstats = file_get_contents('/proc/diskstats');
        
        foreach (explode("\n", $diskstats) as $line) {
            if (preg_match('/^\s*\d+\s+\d+\s+[sh]d[a-z]\d*\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                $stats['read_bytes'] += $matches[1] * 512; // Sectors are 512 bytes
                $stats['write_bytes'] += $matches[2] * 512;
            }
        }
        
        return $stats;
    }
    
    /**
     * Update resource usage history
     */
    private function updateResourceHistory() {
        $time = time();
        
        // Update CPU history
        if (empty($this->cpuHistory) || ($time - end($this->cpuHistory)['time']) >= $this->cpuSampleInterval) {
            $this->cpuHistory[] = array('time' => $time, 'value' => $this->getCpuUsage());
            if (count($this->cpuHistory) > $this->maxHistoryEntries) {
                array_shift($this->cpuHistory);
            }
        }
        
        // Update memory history
        if (empty($this->memoryHistory) || ($time - end($this->memoryHistory)['time']) >= $this->memorySampleInterval) {
            $this->memoryHistory[] = array('time' => $time, 'value' => $this->getMemoryUsage());
            if (count($this->memoryHistory) > $this->maxHistoryEntries) {
                array_shift($this->memoryHistory);
            }
        }
        
        // Update I/O history
        if (empty($this->ioHistory) || ($time - end($this->ioHistory)['time']) >= $this->ioSampleInterval) {
            $this->ioHistory[] = array('time' => $time, 'value' => $this->getIoUsage());
            if (count($this->ioHistory) > $this->maxHistoryEntries) {
                array_shift($this->ioHistory);
            }
        }
    }
    
    /**
     * Check if resources are available for an operation
     */
    public function checkResourceAvailability() {
        $this->updateResourceHistory();
        
        $cpuUsage = $this->getCpuUsage();
        $memoryUsage = $this->getMemoryUsage();
        $ioUsage = $this->getIoUsage();
        
        $available = true;
        $reasons = [];
        
        if ($cpuUsage >= $this->cpuLimit) {
            $available = false;
            $reasons[] = "CPU usage too high ({$cpuUsage}%)";
        }
        
        if ($memoryUsage >= $this->memoryLimit) {
            $available = false;
            $reasons[] = "Memory usage too high ({$memoryUsage}%)";
        }
        
        if ($ioUsage >= $this->ioLimit) {
            $available = false;
            $reasons[] = "I/O usage too high ({$ioUsage}%)";
        }
        
        if (!$available) {
            $this->logger->warning("Resource limits exceeded", [
                'cpu_usage' => $cpuUsage,
                'memory_usage' => $memoryUsage,
                'io_usage' => $ioUsage,
                'reasons' => $reasons
            ]);
        }
        
        return array(
            'available' => $available,
            'reasons' => $reasons,
            'metrics' => array(
                'cpu' => $cpuUsage,
                'memory' => $memoryUsage,
                'io' => $ioUsage
            )
        );
    }
    
    /**
     * Get resource usage history
     */
    public function getResourceHistory() {
        return array(
            'cpu' => $this->cpuHistory,
            'memory' => $this->memoryHistory,
            'io' => $this->ioHistory
        );
    }
    
    /**
     * Get current resource limits
     */
    public function getResourceLimits() {
        return array(
            'cpu' => $this->cpuLimit,
            'memory' => $this->memoryLimit,
            'io' => $this->ioLimit
        );
    }
    
    /**
     * Update resource limits
     */
    public function updateResourceLimits($limits) {
        $updated = false;
        
        if (isset($limits['cpu']) && is_numeric($limits['cpu'])) {
            $this->cpuLimit = max(0, min(100, (float)$limits['cpu']));
            $updated = true;
        }
        
        if (isset($limits['memory']) && is_numeric($limits['memory'])) {
            $this->memoryLimit = max(0, min(100, (float)$limits['memory']));
            $updated = true;
        }
        
        if (isset($limits['io']) && is_numeric($limits['io'])) {
            $this->ioLimit = max(0, min(100, (float)$limits['io']));
            $updated = true;
        }
        
        if ($updated) {
            $this->logger->info("Resource limits updated", [
                'cpu_limit' => $this->cpuLimit,
                'memory_limit' => $this->memoryLimit,
                'io_limit' => $this->ioLimit
            ]);
        }
        
        return $updated;
    }
    
    /**
     * Calculate adaptive resource limits based on system load
     */
    public function calculateAdaptiveLimits() {
        $this->updateResourceHistory();
        
        // Calculate averages from history
        $cpuAvg = array_sum(array_column($this->cpuHistory, 'value')) / max(1, count($this->cpuHistory));
        $memoryAvg = array_sum(array_column($this->memoryHistory, 'value')) / max(1, count($this->memoryHistory));
        $ioAvg = array_sum(array_column($this->ioHistory, 'value')) / max(1, count($this->ioHistory));
        
        // Adjust limits based on average usage
        $newLimits = array(
            'cpu' => min(90, max(50, $cpuAvg * 1.5)), // Allow 50% more than average, cap at 90%
            'memory' => min(90, max(50, $memoryAvg * 1.5)),
            'io' => min(90, max(50, $ioAvg * 1.5))
        );
        
        $this->updateResourceLimits($newLimits);
        
        return $newLimits;
    }
}