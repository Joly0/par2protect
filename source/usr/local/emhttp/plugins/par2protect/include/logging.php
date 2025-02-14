<?php
namespace Par2Protect;

class Logger {
    private static $instance = null;
    private static $initialized = false;
    private $logFile = '';
    private $config;
    private $timezone;
    private $requestId;
    private $maxLogSize = 10485760; // 10MB default
    private $maxLogFiles = 5; // Keep 5 rotated files by default
    private $previousDebugMode = null;
    
    const LOG_LEVELS = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'NOTICE'  => 2,
        'WARNING' => 3,
        'ERROR'   => 4,
        'CRITICAL'=> 5
    ];
    private function __construct() {
        $this->config = Config::getInstance();
        $this->timezone = new \DateTimeZone('Europe/Berlin');
        $this->requestId = $this->generateRequestId();
        
        // Get log configuration
        $this->maxLogSize = $this->config->get('logging.max_size', 10) * 1024 * 1024; // Convert MB to bytes
        $this->maxLogFiles = $this->config->get('logging.max_files', 5);
        
        // Use plugin directory for all logs
        $baseLogPath = '/boot/config/plugins/par2protect';
        
        // Ensure base directory exists
        if (!is_dir($baseLogPath)) {
            if (!@mkdir($baseLogPath, 0755, true)) {
                $error = error_get_last();
                throw new \Exception("Failed to create log directory: " . ($error['message'] ?? 'Unknown error'));
            }
        }
        
        // Set log file
        $this->logFile = $baseLogPath . '/par2protect.log';
        
        // Initialize debug mode tracking
        $this->previousDebugMode = $this->config->get('debug.debug_logging', false);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            
            // Only log initialization on main page load
            if (basename($_SERVER['SCRIPT_NAME']) === 'template.php') {
                self::$instance->info("Logger initialized", [
                    'log_file' => self::$instance->logFile,
                    'debug_enabled' => self::$instance->previousDebugMode,
                    'request_id' => self::$instance->requestId
                ]);
            }
        }
        return self::$instance;
        return self::$instance;
    }
    
    /**
     * Generate unique request ID
     */
    private function generateRequestId() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Format timestamp with timezone
     */
    private function formatTimestamp() {
        $date = new \DateTime('now', $this->timezone);
        return $date->format('d-M-Y H:i:s T');
    }
    
    /**
     * Format context data for logging
     */
    private function formatContext($context) {
        if (empty($context)) {
            return '';
        }
        
        // Add request ID
        $context['request_id'] = $this->requestId;
        
        // Handle exceptions with minimal data
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['error'] = $e->getMessage();
            $context['error_code'] = $e->getCode();
            unset($context['exception']); // Remove full exception object
        }
        
        // Remove any large data structures
        foreach ($context as $key => $value) {
            if (is_array($value) && count($value) > 100) {
                $context[$key] = '[Large array with ' . count($value) . ' items]';
            }
        }
        
        try {
            return ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return ' {"error":"Context too large to encode"}';
        }
    }
    
    /**
     * Check if debug mode has changed and handle rotation
     */
    private function checkDebugModeChange() {
        // Get current debug mode setting
        $currentDebugMode = filter_var(
            $this->config->get('debug.debug_logging', false),
            FILTER_VALIDATE_BOOLEAN
        );
        
        // If debug mode has changed
        if ($this->previousDebugMode !== null && $currentDebugMode !== $this->previousDebugMode) {
            // Simple log message before changing mode
            $this->info("Debug logging " . ($currentDebugMode ? "enabled" : "disabled"));
        }
        
        // Update stored mode
        $this->previousDebugMode = $currentDebugMode;
        
        return $currentDebugMode;
    }
    
    /**
     * Get total size of all log files
     */
    private function getTotalLogSize($baseFile) {
        $total = 0;
        if (file_exists($baseFile)) {
            $total += filesize($baseFile);
        }
        for ($i = 1; $i <= $this->maxLogFiles; $i++) {
            $rotatedFile = $baseFile . '.' . $i;
            if (file_exists($rotatedFile)) {
                $total += filesize($rotatedFile);
            }
            $compressedFile = $rotatedFile . '.gz';
            if (file_exists($compressedFile)) {
                $total += filesize($compressedFile);
            }
        }
        return $total;
    }

    /**
     * Clean up old log files
     */
    private function cleanupOldLogs($baseFile) {
        for ($i = $this->maxLogFiles + 1; $i <= $this->maxLogFiles + 5; $i++) {
            $oldFile = $baseFile . '.' . $i;
            $oldCompressed = $oldFile . '.gz';
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
            if (file_exists($oldCompressed)) {
                @unlink($oldCompressed);
            }
        }
    }

    /**
     * Rotate log file with compression
     */
    private function rotateLog($file) {
        try {
            // Clean up any files beyond our retention limit
            $this->cleanupOldLogs($file);
            
            // Remove oldest log file if it exists
            $oldestLog = $file . '.' . $this->maxLogFiles;
            $oldestCompressed = $oldestLog . '.gz';
            if (file_exists($oldestLog)) {
                @unlink($oldestLog);
            }
            if (file_exists($oldestCompressed)) {
                @unlink($oldestCompressed);
            }
            
            // Rotate existing log files
            for ($i = $this->maxLogFiles - 1; $i >= 1; $i--) {
                $oldFile = $file . '.' . $i;
                $newFile = $file . '.' . ($i + 1);
                $oldCompressed = $oldFile . '.gz';
                $newCompressed = $newFile . '.gz';
                
                if (file_exists($oldCompressed)) {
                    rename($oldCompressed, $newCompressed);
                } elseif (file_exists($oldFile)) {
                    // Compress while rotating using streams to avoid memory issues
                    $input = fopen($oldFile, 'rb');
                    $output = gzopen($newCompressed, 'wb9');
                    
                    if ($input && $output) {
                        while (!feof($input)) {
                            gzwrite($output, fread($input, 8192));
                        }
                        fclose($input);
                        gzclose($output);
                        unlink($oldFile);
                    } else {
                        // If compression fails, just rename
                        rename($oldFile, $newFile);
                    }
                }
            }
            
            // Rotate current log file
            if (file_exists($file)) {
                rename($file, $file . '.1');
            }
            
            // Check total log size and clean up if needed
            if ($this->getTotalLogSize($file) > ($this->maxLogSize * ($this->maxLogFiles + 1))) {
                $this->cleanupOldLogs($file);
            }
        } catch (\Exception $e) {
            error_log("Log rotation failed: " . $e->getMessage());
            // Continue without rotation rather than disrupting logging
        }
    }
    
    /**
     * Check if log rotation is needed
     */
    private function checkRotation($file) {
        if (file_exists($file) && filesize($file) >= $this->maxLogSize) {
            $this->rotateLog($file);
        }
    }
    
    /**
     * Write log entry to file
     */
    private function writeLog($file, $entry) {
        // Create directory if it doesn't exist
        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                throw new \Exception("Failed to create log directory: $dir");
            }
        }
        
        // Create file if it doesn't exist
        if (!file_exists($file)) {
            if (file_put_contents($file, '') === false) {
                throw new \Exception("Failed to create log file: $file");
            }
            chmod($file, 0666);
        }
        
        // Check rotation
        $this->checkRotation($file);
        
        // Write log entry
        if (@file_put_contents($file, $entry . PHP_EOL, FILE_APPEND) === false) {
            throw new \Exception("Failed to write to log file: $file");
        }
    }
    
    private $debugMode = null;

    private function isDebugEnabled() {
        if ($this->debugMode === null) {
            $this->debugMode = filter_var(
                $this->config->get('debug.debug_logging', false),
                FILTER_VALIDATE_BOOLEAN
            );
        }
        return $this->debugMode;
    }

    public function log($message, $level = 'INFO', $context = []) {
        // Skip debug messages early if debug logging is disabled
        if ($level === 'DEBUG' && !$this->isDebugEnabled()) {
            return true;
        }
        
        // Validate and normalize log level
        if (!isset(self::LOG_LEVELS[$level])) {
            $level = 'INFO';
        }
        
        // Format log line directly without intermediate array
        $logLine = sprintf(
            "[%s] %s: %s%s",
            $this->formatTimestamp(),
            $level,
            $message,
            empty($context) ? '' : $this->formatContext($context)
        );
        
        try {
            $this->writeLog($this->logFile, $logLine);
        } catch (\Exception $e) {
            error_log("Logger failed: " . $e->getMessage());
            error_log("Original message: " . $logLine);
        }
        
        return true;
    }
    
    public function debug($message, $context = []) {
        return $this->log($message, 'DEBUG', $context);
    }
    
    public function info($message, $context = []) {
        return $this->log($message, 'INFO', $context);
    }
    
    public function warning($message, $context = []) {
        return $this->log($message, 'WARNING', $context);
    }
    
    public function error($message, $context = []) {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['stack_trace'] = $e->getTraceAsString();
        }
        return $this->log($message, 'ERROR', $context);
    }
    
    public function critical($message, $context = []) {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['stack_trace'] = $e->getTraceAsString();
        }
        return $this->log($message, 'CRITICAL', $context);
    }
    
    /**
     * Get request ID
     */
    public function getRequestId() {
        return $this->requestId;
    }

    public function getRecentActivity($limit = 10, $type = null) {
        if (!file_exists($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }

        $activities = [];
        $handle = @fopen($this->logFile, 'r');
        if (!$handle) {
            return [];
        }

        // Read from end of file
        fseek($handle, -8192, SEEK_END); // Start from last 8KB
        $position = ftell($handle);
        if ($position > 0) {
            // Read and discard partial line
            fgets($handle);
        }

        $lines = [];
        while (!feof($handle) && count($lines) < $limit * 2) { // Read extra lines for filtering
            $line = fgets($handle);
            if ($line !== false) {
                $lines[] = $line;
            }
        }
        fclose($handle);

        $lines = array_reverse($lines);
        
        foreach ($lines as $line) {
            if (count($activities) >= $limit) {
                break;
            }

            if (preg_match('/\[(.*?)\] (.*?): (.*?)( \{.*\})?$/', $line, $matches)) {
                $time = $matches[1];
                $level = $matches[2];
                $message = $matches[3];
                
                // Extract activity type from message
                $activityType = '';
                if (strpos($message, 'verification') !== false) {
                    $activityType = 'verify';
                } else if (strpos($message, 'protection') !== false) {
                    $activityType = 'protect';
                }

                // Skip if type filter is set and doesn't match
                if ($type !== null && $activityType !== $type) {
                    continue;
                }

                // Format activity entry
                $activity = [
                    'time' => $time,
                    'action' => trim($message),
                    'status' => $level === 'ERROR' ? 'error' : 'success'
                ];

                // Only parse context if needed
                if (isset($matches[4])) {
                    $contextJson = trim($matches[4]);
                    try {
                        $context = json_decode($contextJson, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // Add path if available
                            foreach (['path', 'paths', 'file', 'directory', 'source'] as $key) {
                                if (isset($context[$key])) {
                                    $activity['path'] = is_array($context[$key]) ?
                                        implode(', ', $context[$key]) : $context[$key];
                                    break;
                                }
                            }
                            
                            // Add error details or stats
                            if ($level === 'ERROR' && isset($context['error'])) {
                                $activity['details'] = $context['error'];
                            } elseif (isset($context['stats'])) {
                                $activity['details'] = json_encode($context['stats']);
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignore context parsing errors
                    }
                }

                $activities[] = $activity;
            }
        }

        return $activities;
    }
}