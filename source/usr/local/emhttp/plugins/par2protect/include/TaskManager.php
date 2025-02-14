<?php
namespace Par2Protect;

class TaskManager {
    private static $instance = null;
    private $logger;
    private $config;
    private $par2;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        $this->par2 = Par2::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Configure system resources for task execution
     */
    public function configureResources() {
        $resources = Functions::getSystemResources();
        $cpuCores = (int)trim(shell_exec('nproc'));
        $cpuThreads = max(1, min($cpuCores, intval($cpuCores * 0.75)));
        $memoryLimit = min(1024, intval($resources['memory']['available'] / 1024 / 2));
        
        $this->par2->setResourceLimits($cpuThreads, $memoryLimit);
        
        // Set I/O priority
        $ioPriority = $this->config->get('resource_limits.io_priority', 'low');
        switch ($ioPriority) {
            case 'high':
                exec('ionice -c 1 -n 4 -p ' . getmypid());
                break;
            case 'normal':
                exec('ionice -c 2 -n 0 -p ' . getmypid());
                break;
            case 'low':
                exec('ionice -c 3 -p ' . getmypid());
                break;
        }
        
        return [
            'cpu_threads' => $cpuThreads,
            'memory_limit' => $memoryLimit,
            'io_priority' => $ioPriority
        ];
    }
    
    /**
     * Check if maximum concurrent operations reached
     */
    public function checkConcurrentOperations() {
        $maxOperations = $this->config->get('resource_limits.max_concurrent_operations', 2);
        
        if ($this->par2->isRunning()) {
            $status = $this->par2->getStatus();
            if (count($status['processes'] ?? []) >= $maxOperations) {
                throw new \Exception('Maximum number of concurrent operations reached. Please try again later.');
            }
        }
    }
    
    /**
     * Check available disk space using df command
     */
    /**
     * Convert human readable size to bytes
     */
    private function humanReadableToBytes($size) {
        $size = trim($size);
        $last = strtolower($size[strlen($size)-1]);
        $value = floatval($size);
        
        switch($last) {
            case 't': $value *= 1024;
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Check available disk space using df command
     */
    private function checkDiskSpace($path) {
        // Handle Unraid paths
        if (strpos($path, '/mnt/user') === 0) {
            // For /mnt/user paths, check the actual underlying disk
            $userShare = trim(shell_exec('readlink -f ' . escapeshellarg($path)));
            if (!empty($userShare)) {
                $path = $userShare;
                $this->logger->debug("Resolved user share path", [
                    'original_path' => $path,
                    'resolved_path' => $userShare
                ]);
            }
        }
        
        // Get filesystem info using df with human-readable sizes
        $command = 'df -h ' . escapeshellarg($path);
        $df = shell_exec($command);
        
        // Log the full df output for debugging
        $this->logger->debug("Disk space check command", [
            'command' => $command,
            'output' => $df,
            'path' => $path
        ]);
        
        if (empty($df)) {
            throw new \Exception("Failed to get disk space information");
        }
        
        // Parse df output
        $lines = explode("\n", trim($df));
        if (count($lines) < 2) {
            throw new \Exception("Invalid df output format");
        }
        
        // Get last line which contains the values
        $values = preg_split('/\s+/', $lines[1]);
        if (count($values) < 4) {
            throw new \Exception("Invalid df values format");
        }
        
        // Convert available space to bytes using helper method
        $availableBytes = $this->humanReadableToBytes($values[3]) * 1024; // Convert from KB to bytes
        $availableGB = round($availableBytes / (1024 * 1024 * 1024), 2);
        
        $this->logger->debug("Disk space details", [
            'path' => $path,
            'filesystem' => $values[0],
            'available_str' => $values[3],
            'available_gb' => $availableGB,
            'df_values' => $values
        ]);
        
        // Require at least 1GB free space
        if ($availableGB < 1) {
            throw new \Exception("Insufficient disk space. At least 1GB required. Available: {$availableGB}GB");
        }
        
        return $availableBytes;
    }
    
    /**
     * Create directory with proper permissions
     */
    private function createDirectory($path) {
        if (!file_exists($path)) {
            $this->logger->debug("Creating directory", ['path' => $path]);
            
            // Try to create directory with sudo if needed
            if (!@mkdir($path, 0755, true)) {
                $error = error_get_last();
                $this->logger->debug("Failed to create directory normally, trying with sudo", [
                    'path' => $path,
                    'error' => $error['message'] ?? 'Unknown error'
                ]);
                
                // Try with sudo
                $result = shell_exec('sudo mkdir -p ' . escapeshellarg($path) . ' 2>&1');
                if (!empty($result)) {
                    throw new \Exception("Failed to create directory with sudo: $result");
                }
            }
            
            // Double-check directory was created
            if (!is_dir($path)) {
                throw new \Exception("Failed to verify directory creation: $path");
            }
        }
        
        // Ensure directory is writable
        if (!is_writable($path)) {
            $this->logger->debug("Making directory writable", ['path' => $path]);
            
            // Try chmod with sudo
            $result = shell_exec('sudo chmod 755 ' . escapeshellarg($path) . ' 2>&1');
            if (!empty($result)) {
                throw new \Exception("Failed to make directory writable: $result");
            }
        }
        
        // Log directory permissions and ownership
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        $owner = posix_getpwuid(fileowner($path));
        $group = posix_getgrgid(filegroup($path));
        
        $this->logger->debug("Directory details", [
            'path' => $path,
            'permissions' => $perms,
            'owner' => $owner['name'],
            'group' => $group['name']
        ]);
    }
    
    /**
     * Validate paths exist
     */
    public function validatePaths(array $paths) {
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                throw new \Exception("Path does not exist: $path");
            }
            
            // Check disk space for each path
            $this->checkDiskSpace(dirname($path));
        }
    }
    
    /**
     * Process file types configuration
     */
    public function processFileTypes($fileTypesInput) {
        if (empty($fileTypesInput)) {
            return [];
        }
        
        if (is_array($fileTypesInput)) {
            return array_map('trim', $fileTypesInput);
        }
        
        $fileTypesData = json_decode($fileTypesInput, true);
        if (is_array($fileTypesData)) {
            return array_map('trim', $fileTypesData);
        }
        
        return [];
    }
    
    /**
     * Get protected items from database
     */
    public function getProtectedItems() {
        try {
            $items = Database::getProtectedItems();
            return [
                'items' => $items,
                'paths' => array_column($items, 'path')
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve protected items", [
                'exception' => $e
            ]);
            throw new \Exception("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Add protected item to database
     */
    public function addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes = null) {
        try {
            Database::addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes);
            Database::updateVerificationStatus($path, 'PROTECTED', 'Initial protection successful');
            
            $this->logger->debug("Added item to database", [
                'path' => $path,
                'mode' => $mode,
                'redundancy' => $redundancy,
                'par2_path' => $par2Path
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update database", [
                'path' => $path,
                'exception' => $e
            ]);
            throw new \Exception("Database error while protecting item: " . $e->getMessage());
        }
    }
    
    /**
     * Process directory for protection
     */
    public function processDirectory($path, $fileTypes, $redundancy) {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filename = $file->getPathname();
                $extension = Functions::getExtension($filename);
                
                if (!empty($fileTypes) && !in_array($extension, $fileTypes)) {
                    continue;
                }
                
                $files[] = $filename;
            }
        }
        
        if (empty($files)) {
            return [
                'success' => false,
                'error' => 'No matching files found in directory'
            ];
        }
        
        // Create .parity directory
        $outputPath = $path . '/.parity/directory';
        $outputPath = preg_replace('#/+#', '/', $outputPath);
        $parityDir = dirname($outputPath);
        
        try {
            // Check disk space before creating directory
            $this->checkDiskSpace(dirname($parityDir));
            
            // Create .parity directory
            $this->createDirectory($parityDir);
            
            $this->logger->debug("Processing directory", [
                'directory' => $path,
                'output_path' => $outputPath,
                'file_count' => count($files)
            ]);
            
            return [
                'success' => true,
                'files' => $files,
                'output_path' => $outputPath
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to prepare directory", [
                'directory' => $path,
                'exception' => $e
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}