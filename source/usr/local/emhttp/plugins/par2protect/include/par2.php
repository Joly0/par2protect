<?php
namespace Par2Protect;

class Par2 {
    private static $instance = null;
    private $config;
    private $logger;
    private $par2Binary = '/usr/local/bin/par2';
    private $running = [];
    
    private function __construct() {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();
        
        // Verify par2 binary exists
        if (!file_exists($this->par2Binary)) {
            throw new \Exception("PAR2 binary not found at {$this->par2Binary}");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create PAR2 files for a single file
     * 
     * @param string $sourcePath Source file path
     * @param int $redundancy Redundancy percentage (1-100)
     * @param string|null $outputPath Custom output path (optional)
     * @return array Operation result
     */
    public function createForFile($sourcePath, $redundancy = null, $outputPath = null) {
        if (!file_exists($sourcePath)) {
            throw new \Exception("Source file not found: {$sourcePath}");
        }
        
        // Use default redundancy if not specified
        if ($redundancy === null) {
            $redundancy = $this->config->get('protection.default_redundancy', 5);
        }
        
        // Determine output path
        if ($outputPath === null) {
            $outputPath = $this->getDefaultParityPath($sourcePath);
        }
        
        // Ensure output directory exists
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        
        $this->logger->info("Creating PAR2 files", [
            'source' => $sourcePath,
            'redundancy' => $redundancy,
            'output' => $outputPath
        ]);
        
        return $this->executeCommand('create', [
            'redundancy' => $redundancy,
            'source' => $sourcePath,
            'output' => $outputPath
        ]);
    }
    
    /**
     * Verify file(s) using PAR2
     * 
     * @param string $sourcePath Source file or directory
     * @param bool $quiet Suppress output
     * @return array Verification result
     */
    public function verify($sourcePath, $quiet = false) {
        if (!file_exists($sourcePath)) {
            throw new \Exception("Source path not found: {$sourcePath}");
        }
        
        $parityPath = $this->getDefaultParityPath($sourcePath);
        if (!file_exists($parityPath . '.par2')) {
            throw new \Exception("PAR2 files not found for: {$sourcePath}");
        }
        
        $this->logger->info("Verifying files", [
            'source' => $sourcePath,
            'parity' => $parityPath
        ]);
        
        return $this->executeCommand('verify', [
            'source' => $sourcePath,
            'parity' => $parityPath,
            'quiet' => $quiet
        ]);
    }
    
    /**
     * Repair file(s) using PAR2
     * 
     * @param string $sourcePath Source file or directory
     * @param bool $purge Remove damaged files
     * @return array Repair result
     */
    public function repair($sourcePath, $purge = false) {
        if (!file_exists($sourcePath)) {
            throw new \Exception("Source path not found: {$sourcePath}");
        }
        
        $parityPath = $this->getDefaultParityPath($sourcePath);
        if (!file_exists($parityPath . '.par2')) {
            throw new \Exception("PAR2 files not found for: {$sourcePath}");
        }
        
        $this->logger->info("Repairing files", [
            'source' => $sourcePath,
            'parity' => $parityPath,
            'purge' => $purge
        ]);
        
        return $this->executeCommand('repair', [
            'source' => $sourcePath,
            'parity' => $parityPath,
            'purge' => $purge
        ]);
    }
    
    /**
     * Execute PAR2 command with given options
     * 
     * @param string $command Command type (create|verify|repair)
     * @param array $options Command options
     * @return array Operation result
     */
    private function executeCommand($command, $options) {
        $cmd = [$this->par2Binary];
        
        switch ($command) {
            case 'create':
                $cmd[] = 'c';
                $cmd[] = '-r' . $options['redundancy'];
                $cmd[] = '-O'; // Optimize for newer processors
                $cmd[] = escapeshellarg($options['output']);
                $cmd[] = escapeshellarg($options['source']);
                break;
                
            case 'verify':
                $cmd[] = 'v';
                if ($options['quiet']) $cmd[] = '-q';
                $cmd[] = escapeshellarg($options['parity'] . '.par2');
                break;
                
            case 'repair':
                $cmd[] = 'r';
                if ($options['purge']) $cmd[] = '--purge';
                $cmd[] = escapeshellarg($options['parity'] . '.par2');
                break;
                
            default:
                throw new \Exception("Unknown command: {$command}");
        }
        
        $process = [
            'command' => implode(' ', $cmd),
            'output' => [],
            'status' => null
        ];
        
        // Execute command
        exec($process['command'] . ' 2>&1', $process['output'], $process['status']);
        
        $result = [
            'success' => $process['status'] === 0,
            'command' => $process['command'],
            'output' => $process['output'],
            'status' => $process['status']
        ];
        
        // Log result
        if ($result['success']) {
            $this->logger->info("PAR2 command completed successfully", [
                'command' => $command,
                'options' => $options
            ]);
        } else {
            $this->logger->error("PAR2 command failed", [
                'command' => $command,
                'options' => $options,
                'output' => $process['output']
            ]);
        }
        
        return $result;
    }
    
    /**
     * Get default parity file path for a source file
     * 
     * @param string $sourcePath Source file path
     * @return string Parity file path
     */
    private function getDefaultParityPath($sourcePath) {
        $parityBase = $this->config->get('paths.par2_storage');
        $relativePath = str_replace('/mnt/user/', '', $sourcePath);
        return $parityBase . '/' . $relativePath;
    }
    
    /**
     * Check if PAR2 process is currently running
     * 
     * @return bool
     */
    public function isRunning() {
        $output = [];
        exec("pgrep -f '{$this->par2Binary}'", $output);
        return !empty($output);
    }
    
    /**
     * Get current PAR2 process status
     * 
     * @return array Status information
     */
    public function getStatus() {
        if (!$this->isRunning()) {
            return ['running' => false];
        }
        
        $status = [
            'running' => true,
            'processes' => []
        ];
        
        $output = [];
        exec("ps aux | grep '{$this->par2Binary}' | grep -v grep", $output);
        
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $status['processes'][] = [
                'pid' => $parts[1],
                'cpu' => $parts[2],
                'mem' => $parts[3],
                'command' => implode(' ', array_slice($parts, 10))
            ];
        }
        
        return $status;
    }
}