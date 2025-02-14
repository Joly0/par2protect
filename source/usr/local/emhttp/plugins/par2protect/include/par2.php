<?php
namespace Par2Protect;

class Par2 {
    private static $instance = null;
    private $logger;
    private $config;
    private $par2Binary = '/usr/local/bin/par2';
    private $activeProcesses = [];
    private $processOutputs = [];
    
    private function __construct() {
        try {
            $this->logger = Logger::getInstance();
            $this->config = Config::getInstance();
            
            // Verify par2 binary path
            if (!file_exists($this->par2Binary)) {
                throw new \Exception("par2 binary not found at: " . $this->par2Binary);
            }
            if (!is_executable($this->par2Binary)) {
                throw new \Exception("par2 binary is not executable: " . $this->par2Binary);
            }
            
            $this->logger->debug("Par2 class initialized", [
                'par2_binary' => $this->par2Binary,
                'binary_exists' => file_exists($this->par2Binary),
                'binary_executable' => is_executable($this->par2Binary)
            ]);
        } catch (\Exception $e) {
            // Log error before throwing to ensure it's captured
            if ($this->logger) {
                $this->logger->error("Par2 initialization failed", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw new \Exception("Failed to initialize Par2 class: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        $logger = Logger::getInstance();
        try {
            if (self::$instance === null) {
                $logger->debug("Creating new Par2 instance");
                self::$instance = new self();
                $logger->debug("Par2 instance created successfully");
            } else {
                $logger->debug("Returning existing Par2 instance");
            }
            return self::$instance;
        } catch (\Exception $e) {
            $logger->error("Failed to create Par2 instance", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception("Failed to initialize Par2 instance: " . $e->getMessage());
        }
    }
    
    /**
     * Execute PAR2 command
     */
    private function execute($command, $options = []) {
        try {
            // Execute command and capture output
            $descriptorspec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w']  // stderr
            ];
            
            $process = [
                'command' => $command,
                'output' => '',
                'status' => null,
                'pid' => null
            ];
            
            // Log detailed information about the command execution
            $this->logger->info("Executing par2 command:", ['request_id' => $options['operation_id']]);
            $this->logger->info("----------------------------------------");
            $this->logger->info("Command: " . $command);
            $this->logger->info("----------------------------------------");
            
            // Also log to error_log for immediate visibility during development
            error_log("PAR2 COMMAND: " . $command);
            
            $proc = proc_open($command, $descriptorspec, $pipes);
            if (is_resource($proc)) {
                $procStatus = proc_get_status($proc);
                $process['pid'] = $procStatus['pid'];
                
                // Store process info and output
                $this->activeProcesses[$process['pid']] = [
                    'command' => $command,
                    'operation_id' => $options['operation_id'],
                    'path' => $options['path'],
                    'start_time' => time()
                ];
                
                $this->processOutputs[$process['pid']] = [
                    'operation_id' => $options['operation_id'],
                    'path' => $options['path'],
                    'output' => '',
                    'status' => null
                ];
                
                // Read output with timeout
                $output = '';
                while (!feof($pipes[1]) || !feof($pipes[2])) {
                    $read = [$pipes[1], $pipes[2]];
                    $write = null;
                    $except = null;
                    
                    if (stream_select($read, $write, $except, 1)) {
                        foreach ($read as $pipe) {
                            $output .= fread($pipe, 8192);
                        }
                    }
                }
                
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $status = proc_close($proc);
                
                // Store output and status
                $this->processOutputs[$process['pid']]['output'] = $output;
                $this->processOutputs[$process['pid']]['status'] = $status;
                
                $process['output'] = $output;
                $process['status'] = $status;
                
                // Log command completion with detailed output
                $this->logger->info("Command completed:", ['request_id' => $options['operation_id']]);
                $this->logger->info("----------------------------------------");
                $this->logger->info("Status: " . $status);
                $this->logger->info("Output:");
                $this->logger->info($output);
                $this->logger->info("----------------------------------------");
                
                // Also log to error_log for immediate visibility during development
                error_log("PAR2 COMMAND OUTPUT:");
                error_log("Status: " . $status);
                error_log("Output: " . $output);
                
            } else {
                throw new Exceptions\VerificationException(
                    "Failed to execute command",
                    null,
                    'verify',
                    ['command' => $command]
                );
            }
            
            return [
                'success' => $process['status'] === 0,
                'command' => $process['command'],
                'output' => $process['output'],
                'status' => $process['status'],
                'pid' => $process['pid']
            ];
            
        } catch (\Exception $e) {
            $this->logger->error("Command execution failed", [
                'command' => $command,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Verify files
     */
    public function verify($path, $par2Path, $operationId, $options = []) {
        try {
            /**
             * Par2 Verify Command Structure:
             * verify:  par2 v -B'basepath' parfile.par2
             * 
             * - basepath: The top-level directory selected by the user
             * - parfile.par2: Full path to the par2 file
             */
            
            // Build verify command
            $cmd = $this->par2Binary;
            $cmd .= " v";
            $cmd .= " -B" . escapeshellarg($path);
            $cmd .= " -t" . ($options['threads'] ?? $this->config->get('resource_limits.max_cpu_usage', 50));
            
            // Add quiet mode unless debug logging is enabled
            if (!$this->config->get('debug.enabled', false)) {
                $cmd .= " -q";
            }
            
            // Add par2 file path
            $cmd .= " " . escapeshellarg($par2Path);
            $cmd .= " 2>&1";
            
            $this->logger->info("Executing verify command:", [
                'command' => $cmd,
                'path' => $path,
                'par2_path' => $par2Path
            ]);
            
            $options['operation_id'] = $operationId;
            $options['path'] = $path;
            $options['parity'] = $par2Path;
            
            $result = $this->execute($cmd, $options);
            
            // Parse verification output
            $status = 'UNKNOWN';
            $details = [];
            
            if ($result['success']) {
                $output = $result['output'];
                
                // Check for perfect state (both normal and quiet mode outputs)
                if (preg_match('/All files are correct|Repair is not needed/i', $output)) {
                    $status = 'PROTECTED';
                    $details['integrity'] = 'full';
                    $details['verified_count'] = 1; // Count this as a verified file
                }
                // Check for repair needed
                elseif (preg_match('/Repair (?:is possible|required)/i', $output)) {
                    $status = 'DAMAGED';
                    $details['needs_repair'] = true;
                    
                    // Capture specific damaged files
                    preg_match_all('/Target: "([^"]+)" - damaged\./', $output, $matches);
                    if (!empty($matches[1])) {
                        $details['damaged_files'] = $matches[1];
                    }
                }
                // Check for missing files
                elseif (preg_match_all('/Target: "([^"]+)" - missing\./', $output, $matches)) {
                    $status = 'MISSING';
                    $details['missing_files'] = $matches[1];
                }
                // Check for critical errors
                elseif (strpos($output, 'No PAR2 recovery blocks') !== false) {
                    $status = 'ERROR';
                    $details['error'] = 'No recovery blocks found';
                }
                // Log unhandled output for debugging
                else {
                    $this->logger->warning("Unhandled verification output", [
                        'output_snippet' => substr($output, 0, 500),
                        'path' => $path
                    ]);
                }
                
                $this->logger->debug("Parsed verification output", [
                    'path' => $path,
                    'status' => $status,
                    'details' => $details,
                    'operation_id' => $operationId
                ]);
            }
            
            return [
                'success' => $result['success'],
                'command' => $result['command'],
                'output' => $result['output'],
                'status' => $status,
                'details' => $details,
                'pid' => $result['pid']
            ];
        } catch (\Exception $e) {
            $this->logger->error("Verification failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'operation_id' => $operationId
            ]);
            throw $e;
        }
    }
    
    /**
     * Repair files
     */
    public function repair($path, $par2Path, $operationId, $options = []) {
        try {
            /**
             * Par2 Repair Command Structure:
             * repair:  par2 r -B'basepath' parfile.par2
             * 
             * - basepath: The top-level directory selected by the user
             * - parfile.par2: Full path to the par2 file
             */
            
            // Build repair command
            $cmd = $this->par2Binary;
            $cmd .= " r";
            $cmd .= " -B" . escapeshellarg($path);
            $cmd .= " -t" . ($options['threads'] ?? $this->config->get('resource_limits.max_cpu_usage', 50));
            
            // Add quiet mode unless debug logging is enabled
            if (!$this->config->get('debug.enabled', false)) {
                $cmd .= " -q";
            }
            
            if (!empty($options['memory'])) {
                $cmd .= " -m" . $options['memory'];
            }
            
            if ($options['purge'] ?? false) {
                $cmd .= " --purge";
            }
            
            // Add par2 file path
            $cmd .= " " . escapeshellarg($par2Path);
            $cmd .= " 2>&1";
            
            $this->logger->info("Executing repair command:", [
                'command' => $cmd,
                'path' => $path,
                'par2_path' => $par2Path
            ]);
            
            $options['operation_id'] = $operationId;
            $options['path'] = $path;
            $options['parity'] = $par2Path;
            
            return $this->execute($cmd, $options);
        } catch (\Exception $e) {
            $this->logger->error("Repair failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'operation_id' => $operationId
            ]);
            throw $e;
        }
    }
    
    /**
     * Get command result for a completed process
     */
    public function getCommandResult($pid) {
        if (isset($this->processOutputs[$pid])) {
            $result = $this->processOutputs[$pid];
            unset($this->processOutputs[$pid]);
            return $result;
        }
        return null;
    }
    
    /**
     * Get current status
     */
    public function getStatus() {
        $processes = [];
        
        // Check active processes
        foreach ($this->activeProcesses as $pid => $process) {
            if (file_exists("/proc/{$pid}")) {
                $processes[] = [
                    'pid' => $pid,
                    'command' => $process['command'],
                    'operation_id' => $process['operation_id'],
                    'path' => $process['path'],
                    'runtime' => time() - $process['start_time']
                ];
            } else {
                unset($this->activeProcesses[$pid]);
            }
        }
        
        // Also check for any par2 processes we might have missed
        $output = [];
        exec("ps aux | grep '{$this->par2Binary}' | grep -v grep", $output);
        
        foreach ($output as $line) {
            if (preg_match('/\s+(\d+)\s+/', $line, $matches)) {
                $pid = $matches[1];
                if (!isset($this->activeProcesses[$pid])) {
                    $processes[] = [
                        'pid' => $pid,
                        'command' => $line,
                        'operation_id' => null,
                        'path' => null,
                        'runtime' => 0
                    ];
                }
            }
        }
        
        return [
            'running' => !empty($processes),
            'processes' => $processes
        ];
    }
    
    /**
     * Cancel process
     */
    public function cancel($operationId) {
        foreach ($this->activeProcesses as $pid => $process) {
            if ($process['operation_id'] === $operationId) {
                posix_kill($pid, SIGTERM);
                unset($this->activeProcesses[$pid]);
                unset($this->processOutputs[$pid]);
                return true;
            }
        }
        return false;
    }
    
    /**
     * Create PAR2 files for protection
     */
    public function protect($path, $par2Path, $redundancy, $operationId, $options = []) {
        try {
            /**
             * Par2 Command Structure:
             * create:  par2 c -B'basepath' output.par2 file1 file2 ...
             * 
             * - basepath: The top-level directory selected by the user
             * - output.par2: Full path to the par2 file in the .parity directory
             * - file1, file2: Full paths to the files being protected
             */
            
            // Verify par2 binary exists and is executable
            if (!file_exists($this->par2Binary)) {
                throw new \Exception("par2 binary not found at: " . $this->par2Binary);
            }
            if (!is_executable($this->par2Binary)) {
                throw new \Exception("par2 binary is not executable: " . $this->par2Binary);
            }
            
            $this->logger->debug("Starting protection", [
                'path' => $path,
                'par2Path' => $par2Path,
                'redundancy' => $redundancy,
                'operation_id' => $operationId,
                'par2_binary' => $this->par2Binary
            ]);
            
            // Ensure the par2 directory exists
            if (!is_dir($par2Path)) {
                mkdir($par2Path, 0755, true);
            }
            
            $options['operation_id'] = $operationId;
            $options['path'] = $path;
            $options['parity'] = $par2Path . '/base.par2';
            $options['redundancy'] = $redundancy;
            
            // Build the command
            $cmd = $this->par2Binary;
            $cmd .= " c";
            $cmd .= " -B" . escapeshellarg($path);
            $cmd .= " -r" . $redundancy;
            $cmd .= " -t" . ($options['threads'] ?? $this->config->get('resource_limits.max_cpu_usage', 50));
            
            // Add quiet mode unless debug logging is enabled
            if (!$this->config->get('debug.enabled', false)) {
                $cmd .= " -q";
            }
            
            if (!empty($options['memory'])) {
                $cmd .= " -m" . $options['memory'];
            }
            
            // Add output par2 file path
            $cmd .= " " . escapeshellarg($options['parity']);
            
            // Add source path
            if (is_dir($path)) {
                // For directories, we need to add each file individually
                $fileList = [];
                
                // Function to recursively get all files
                $getAllFiles = function($dir) use (&$getAllFiles, &$fileList) {
                    $files = scandir($dir);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..' || $file === '.parity') {
                            continue;
                        }
                        
                        $fullPath = $dir . '/' . $file;
                        if (is_dir($fullPath)) {
                            $getAllFiles($fullPath);
                        } elseif (is_file($fullPath)) {
                            // Use full path for each file
                            $fileList[] = escapeshellarg($fullPath);
                            $this->logger->debug("Adding file to protect", [
                                'full_path' => $fullPath
                            ]);
                        }
                    }
                };
                
                // Get all files recursively
                $getAllFiles($path);
                
                if (empty($fileList)) {
                    throw new \Exception("No files found in directory to protect");
                }
                
                // Log files being added
                $this->logger->info("Files to protect:", [
                    'file_count' => count($fileList),
                    'directory' => $path
                ]);
                foreach ($fileList as $file) {
                    $this->logger->debug("  - " . trim($file, "'\""));
                }
                
                // Add files to command
                $cmd .= " " . implode(" ", $fileList);
            } else {
                // For single files
                $cmd .= " " . escapeshellarg($path);
            }
            
            $cmd .= " 2>&1";
            
            $this->logger->info("Executing par2 command:", [
                'command' => $cmd,
                'path' => $path,
                'par2_path' => $options['parity']
            ]);
            
            return $this->execute($cmd, $options);
            
        } catch (\Exception $e) {
            $this->logger->error("Protection failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'operation_id' => $operationId
            ]);
            throw $e;
        }
    }
}