<?php
namespace Par2Protect\Core;

/**
 * Logger class for handling application logging
 */
class Logger {
    // Log levels as constants
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;
    const CRITICAL = 4;
    
    private static $instance = null;
    private $logFile;
    private $tmpLogFile;
    private $backupPath;
    private $maxSize = 10485760; // 10MB
    private $maxFiles = 5;
    private $levels = [
        'DEBUG' => self::DEBUG,
        'INFO' => self::INFO,
        'WARNING' => self::WARNING,
        'ERROR' => self::ERROR,
        'CRITICAL' => self::CRITICAL
    ];
    private $backupIntervals = [
        'hourly' => 3600,      // 1 hour in seconds
        'daily' => 86400,      // 24 hours in seconds
        'weekly' => 604800     // 7 days in seconds
    ];
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        // Get config
        $config = Config::getInstance();
        
        // Set log file paths from config
        $this->logFile = $config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
        $this->tmpLogFile = $config->get('logging.tmp_path', '/tmp/par2protect/logs/par2protect.log');
        $this->backupPath = $config->get('logging.backup_path', '/boot/config/plugins/par2protect/logs');
        
        // Convert max_size from MB to bytes (config stores it in MB)
        $maxSizeMB = $config->get('logging.max_size', $this->maxSize / 1048576);
        $this->maxSize = $maxSizeMB * 1048576; // Convert MB to bytes
        
        $this->maxFiles = $config->get('logging.max_files', $this->maxFiles);

        // Create tmp log directory if it doesn't exist
        $tmpLogDir = dirname($this->tmpLogFile);
        if (!is_dir($tmpLogDir)) {
            @mkdir($tmpLogDir, 0755, true);
        }
        
        // Create backup log directory if it doesn't exist
        if (!is_dir($this->backupPath)) {
            @mkdir($this->backupPath, 0755, true);
        }

        // Schedule log backup if needed
        $this->scheduleLogBackup();
    }

    /**
     * Schedule log backup based on configuration
     */
    private function scheduleLogBackup() {
        $config = Config::getInstance();
        $backupInterval = $config->get('logging.backup_interval', 'daily');
        
        // If backup interval is 'never', don't schedule backup
        if ($backupInterval === 'never') {
            @mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Get singleton instance
     *
     * @return Logger
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Reset the singleton instance
     * This is useful when configuration changes and we need to reload settings
     *
     * @return void
     */
    public static function resetInstance() {
        self::$instance = null;
    }
    
    /**
     * Set log file path
     *
     * @param string $path Log file path
     * @return void
     */
    public function setLogFile($path) {
        $this->logFile = $path;
    }

    /**
     * Set temporary log file path
     */
    public function setTmpLogFile($path) {
        $this->tmpLogFile = $path;
        
        // Create tmp log directory if it doesn't exist
        $tmpLogDir = dirname($this->tmpLogFile);
        if (!is_dir($tmpLogDir)) {
            @mkdir($tmpLogDir, 0755, true);
        }
    }
    
    /**
     * Log debug message
     *
     * @param string $message Message to log
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function debug($message, $context = [], $showInDashboard = false) {
        return $this->log($message, self::DEBUG, $context, $showInDashboard);
    }
    
    /**
     * Log info message
     *
     * @param string $message Message to log
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function info($message, $context = [], $showInDashboard = false) {
        return $this->log($message, self::INFO, $context, $showInDashboard);
    }
    
    /**
     * Log warning message
     *
     * @param string $message Message to log
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function warning($message, $context = [], $showInDashboard = true) {
        return $this->log($message, self::WARNING, $context, $showInDashboard);
    }
    
    /**
     * Log error message
     *
     * @param string $message Message to log
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function error($message, $context = [], $showInDashboard = true) {
        return $this->log($message, self::ERROR, $context, $showInDashboard);
    }
    
    /**
     * Log critical message
     *
     * @param string $message Message to log
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function critical($message, $context = [], $showInDashboard = true) {
        return $this->log($message, self::CRITICAL, $context, $showInDashboard);
    }
    
    /**
     * Log to Unraid system logger
     *
     * @param string $message Message to log
     * @param string $level Log level (INFO, WARNING, ERROR)
     * @return bool Success status
     */
    private function logToUnraid($message, $level = 'INFO') {
        // Determine tag based on level
        $tag = 'PAR2Protect';
        if ($level === 'WARNING') {
            $tag = '[WARNING] PAR2Protect';
        } else if ($level === 'ERROR') {
            $tag = '[ERROR] PAR2Protect';
        }
        
        // Format message (keep it simpler than the detailed log)
        $formattedMessage = $message;
        
        // Execute logger command
        $command = sprintf('logger %s -t %s',
            escapeshellarg($formattedMessage),
            escapeshellarg($tag)
        );
        
        exec($command, $output, $returnCode);
        
        return ($returnCode === 0);
    }
    
    /**
     * Determine if we should log to Unraid based on configuration
     *
     * @param string $level Log level
     * @return bool Whether to log to Unraid
     */
    private function shouldLogToUnraid($level) {
        // INFO is always logged to Unraid
        if ($level === 'INFO') {
            return true;
        }
        
        // Get config
        $config = Config::getInstance();
        $errorLogMode = $config->get('logging.error_log_mode', 'both');
        
        return ($errorLogMode === 'syslog' || $errorLogMode === 'both');
    }
    
    /**
     * Determine if we should log to file based on configuration
     *
     * @param string $level Log level
     * @return bool Whether to log to file
     */
    private function shouldLogToFile($level) {
        // DEBUG is always logged to file if debug logging is enabled
        if ($level === 'DEBUG') {
            $config = Config::getInstance();
            return $config->get('debug.debug_logging', false);
        }
        
        // INFO is always logged to file
        if ($level === 'INFO') {
            return true;
        }
        
        // For WARNING and ERROR, check configuration
        $config = Config::getInstance();
        $errorLogMode = $config->get('logging.error_log_mode', 'both');
        
        return ($errorLogMode === 'logfile' || $errorLogMode === 'both');
    }
    
    /**
     * Check if log file needs rotation
     */
    private function checkRotation() {
        // Check if log file exists and is too large
        clearstatcache(true, $this->tmpLogFile); // Clear stat cache to get accurate file size
        $currentSize = file_exists($this->tmpLogFile) ? filesize($this->tmpLogFile) : 0;
        
        // Force log rotation if file is too large
        if ($currentSize > $this->maxSize) {
            // Add rotation message to the current log
            $rotationMessage = "[" . date('Y-m-d H:i:s') . "] DEBUG: Rotating log file. Current size: $currentSize bytes, Max size: {$this->maxSize} bytes" . PHP_EOL;
            file_put_contents($this->logFile, $rotationMessage, FILE_APPEND);
            
            // Rotate logs
            for ($i = $this->maxFiles - 1; $i > 0; $i--) {
                $oldFile = $this->tmpLogFile . '.' . $i;
                $newFile = $this->tmpLogFile . '.' . ($i + 1);
                
                if (file_exists($oldFile)) {
                    rename($oldFile, $newFile);
                }
            }
            
            // Rename current log to .1
            if (file_exists($this->tmpLogFile)) {
                if (!rename($this->tmpLogFile, $this->tmpLogFile . '.1')) {
                    // If rename fails, try to copy and delete
                    copy($this->tmpLogFile, $this->tmpLogFile . '.1');
                    unlink($this->tmpLogFile);
                }
            }
            
            // Create new log file
            touch($this->tmpLogFile);
            chmod($this->tmpLogFile, 0600); // Set proper permissions
        }
    }

    /**
     * Log message with level
     *
     * @param string $message Message to log
     * @param int $level Log level
     * @param array $context Context data
     * @param bool $showInDashboard Whether to show in dashboard
     * @return bool Success status
     */
    public function log($message, $level = self::INFO, $context = [], $showInDashboard = true) {
        // Determine level string and logging behavior
        $levelStr = 'INFO';
        $logToUnraid = false;
        $logToFile = true;
        
        switch ($level) {
            case self::DEBUG:
                $levelStr = 'DEBUG';
                $logToUnraid = false; // Never log DEBUG to Unraid
                
                // Only log DEBUG to file if debug logging is enabled
                $config = Config::getInstance();
                $logToFile = $config->get('debug.debug_logging', false);
                break;
                
            case self::INFO:
                $levelStr = 'INFO';
                $logToUnraid = true; // Always log INFO to Unraid
                $logToFile = true;   // Always log INFO to file
                break;
                
            case self::WARNING:
                $levelStr = 'WARNING';
                $logToUnraid = $this->shouldLogToUnraid('WARNING');
                $logToFile = $this->shouldLogToFile('WARNING');
                break;
                
            case self::ERROR:
            case self::CRITICAL:
                $levelStr = ($level === self::CRITICAL) ? 'CRITICAL' : 'ERROR';
                $logToUnraid = $this->shouldLogToUnraid('ERROR');
                $logToFile = $this->shouldLogToFile('ERROR');
                break;
        }
        
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Add dashboard flag to context
        $context['_dashboard'] = $showInDashboard;
        
        // Format context as JSON
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        
        // Format log line
        $logLine = "[$timestamp] $levelStr: $message$contextStr";
        
        if ($logToFile) {
            // Check log rotation
            $this->checkRotation();
            
            // Write to log file
            try {
                // Write to temporary log file
                if (file_put_contents($this->tmpLogFile, $logLine . PHP_EOL, FILE_APPEND) === false) {
                    error_log("Failed to write to temporary log file: {$this->tmpLogFile}");
                }
                // Also write to permanent log file
                if (file_put_contents($this->logFile, $logLine . PHP_EOL, FILE_APPEND) === false) {
                    error_log("Failed to write to permanent log file: {$this->logFile}");
                }
            } catch (\Exception $e) {
                error_log("Failed to write to log file: " . $e->getMessage());
            }
        }
        
        // Log to Unraid if needed
        if ($logToUnraid) {
            // Create a simplified message for Unraid
            $unraidMessage = $message;
            
            // Add path if available for better context
            if (isset($context['path'])) {
                $unraidMessage .= " - Path: " . $context['path'];
            }
            
            $this->logToUnraid($unraidMessage, $levelStr);
        }
        
        // Add to activity log if requested or if it's an INFO, WARNING, or ERROR level
        if ($showInDashboard || $level >= self::INFO) {
            $this->addToActivityLog($levelStr, $message, $context);
        }
        
        return true;
    }
    
    // Rotation logic moved directly into the log method
    
    /**
     * Add to activity log
     *
     * @param string $level Log level
     * @param string $message Message to log
     * @param array $context Context data
     * @return void
     */
    private function addToActivityLog($level, $message, $context = []) {
        // Get activity log file
        $activityLogFile = dirname($this->tmpLogFile) . '/activity.json';
        
        // Create activity log array
        $activity = [
            'time' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message
        ];
        
        // Add context data
        if (isset($context['path'])) {
            $activity['path'] = $context['path'];
        } else {
            // Skip adding to activity log if no path is specified
            if ($level !== 'ERROR' && $level !== 'CRITICAL') {
                return;
            }
            // For errors, still log but with a placeholder
            $activity['path'] = null;
        }
        
        // Determine action
        if (isset($context['action'])) {
            $activity['action'] = $context['action'];
        } else {
            // Try to determine action from message
            // Check for operation types first
            if (isset($context['operation_type'])) {
                // Use operation_type directly if available
                $opType = ucfirst(strtolower($context['operation_type']));
                $activity['action'] = $opType;
            } else if (stripos($message, 'protect') !== false) {
                $activity['action'] = 'Protect';
            } else if (stripos($message, 'verify') !== false) {
                $activity['action'] = 'Verify';
            } else if (stripos($message, 'repair') !== false) {
                $activity['action'] = 'Repair';
            } else if (stripos($message, 'remove') !== false) {
                $activity['action'] = 'Remove';
            } else if (stripos($message, 'queue') !== false) {
                // Check if we can extract operation type from the message
                if (preg_match('/queue.*?(protect|verify|repair|remove)/i', $message, $matches)) {
                    $activity['action'] = ucfirst(strtolower($matches[1]));
                } else {
                    $activity['action'] = 'Queue';
                }
            } else if (stripos($message, 'operation') !== false) {
                // Check if we can extract operation type from the message
                if (preg_match('/(protect|verify|repair|remove)/i', $message, $matches)) {
                    $activity['action'] = ucfirst(strtolower($matches[1]));
                } else {
                    $activity['action'] = 'Operation';
                }
            } else if (stripos($message, 'settings') !== false || stripos($message, 'config') !== false) {
                $activity['action'] = 'Settings';
            } else if ($level === 'INFO') {
                // Include all INFO level logs in the activity log
                $activity['action'] = 'Info';
            } else if ($level === 'WARNING') {
                $activity['action'] = 'Warning';
            } else if ($level === 'ERROR' || $level === 'CRITICAL') {
                $activity['action'] = 'Error';
            } else {
                // Skip DEBUG level messages unless explicitly marked as important
                if ($level === 'DEBUG' && (!isset($context['important']) || !$context['important'])) {
                    // Skip adding to activity log for non-important DEBUG messages
                    return;
                }
                $activity['action'] = 'System';
            }
        }
        
        if (isset($context['status'])) {
            $activity['status'] = $context['status'];
        } else if (stripos($message, 'success') !== false) {
            $activity['status'] = 'Success';
        } else if (stripos($message, 'added to queue') !== false) {
            // Special case for queue operations - adding to queue is a success
            $activity['status'] = 'Success';
        } else if (stripos($message, 'fail') !== false || stripos($message, 'error') !== false) {
            $activity['status'] = 'Failed';
        } else if ($level === 'ERROR') {
            $activity['status'] = 'Error';
        } else {
            $activity['status'] = 'Info';
        }
        
        if (isset($context['details'])) {
            $activity['details'] = $context['details'];
        }
        
        // Load existing activity log - simple approach
        $activities = [];
        if (file_exists($activityLogFile)) {
            $json = file_get_contents($activityLogFile);
            if ($json) {
                $activities = json_decode($json, true) ?: [];
            }
        }
        
        // Add new activity
        array_unshift($activities, $activity);
        
        // Limit to 100 activities
        $activities = array_slice($activities, 0, 100);
        
        // Save activity log - simple approach
        file_put_contents($activityLogFile, json_encode($activities));
    }

    /**
     * Backup logs to permanent storage
     * 
     * @param bool $force Force backup even if interval hasn't elapsed
     * @return bool Success status
     */
    public function backupLogs($force = false) {
        $config = Config::getInstance();
        $backupInterval = $config->get('logging.backup_interval', 'daily');
        
        // If backup interval is 'never', don't backup
        if ($backupInterval === 'never' && !$force) {
            return false;
        }
        
        // Get last backup time
        $lastBackupFile = $this->backupPath . '/last_backup';
        $lastBackupTime = file_exists($lastBackupFile) ? (int)file_get_contents($lastBackupFile) : 0;
        $now = time();
        
        // Check if it's time to backup
        $intervalSeconds = isset($this->backupIntervals[$backupInterval]) ? 
            $this->backupIntervals[$backupInterval] : $this->backupIntervals['daily'];
        
        if (!$force && ($now - $lastBackupTime) < $intervalSeconds) {
            return false; // Not time to backup yet
        }
        
        // Backup main log file
        $backupFile = $this->backupPath . '/par2protect_' . date('Y-m-d_H-i-s') . '.log';
        if (file_exists($this->tmpLogFile)) {
            copy($this->tmpLogFile, $backupFile);
        }
        
        // Backup activity log
        $activityLogFile = dirname($this->tmpLogFile) . '/activity.json';
        $backupActivityFile = $this->backupPath . '/activity_' . date('Y-m-d_H-i-s') . '.json';
        if (file_exists($activityLogFile)) {
            copy($activityLogFile, $backupActivityFile);
        }
        
        // Update last backup time
        file_put_contents($lastBackupFile, $now);
        
        return true;
    }
    
    /**
     * Get recent activity
     *
     * @param int $limit Maximum number of activities to return
     * @param string $type Filter by activity type
     * @param bool $includeSystem Whether to include System actions
     * @return array
     */
    public function getRecentActivity($limit = 10, $type = null, $includeSystem = false) {
        // Get activity log file
        $activityLogFile = dirname($this->tmpLogFile) . '/activity.json';
        
        // Log diagnostic information
        $this->debug("Getting recent activity", [
            'limit' => $limit,
            'type' => $type,
            'includeSystem' => $includeSystem,
            'activityLogFile' => $activityLogFile,
            'fileExists' => file_exists($activityLogFile)
        ]);
        
        // Load activity log
        $activities = [];
        if (file_exists($activityLogFile)) {
            $json = file_get_contents($activityLogFile);
            if ($json) {
                $activities = json_decode($json, true) ?: [];
                /* $this->debug("Loaded activity log", [
                    'totalActivities' => count($activities),
                    'firstFewActions' => array_slice(array_column($activities, 'action'), 0, 5)
                ]); */
            } else {
                $this->warning("Activity log file is empty or invalid", [
                    'path' => $activityLogFile
                ]);
            }
        } else {
            $this->warning("Activity log file does not exist", [
                'path' => $activityLogFile
            ]);
        }
        
        // Filter by type if specified
        if ($type !== null) {
            $activities = array_filter($activities, function($activity) use ($type) {
                return isset($activity['action']) && strtolower($activity['action']) === strtolower($type);
            });
            $this->debug("Filtered by type", [
                'type' => $type,
                'remainingActivities' => count($activities)
            ]);
        }
        // Filter out System actions unless explicitly requested
        else if (!$includeSystem) {
            $beforeCount = count($activities);
            $activities = array_filter($activities, function($activity) {
                return !isset($activity['action']) || strtolower($activity['action']) !== 'system';
            });
            $afterCount = count($activities);
            $this->debug("Filtered out System actions", [
                'beforeCount' => $beforeCount,
                'afterCount' => $afterCount,
                'systemActionsRemoved' => $beforeCount - $afterCount
            ]);
        }
        
        // Limit to requested number
        $result = array_slice($activities, 0, $limit);
        $this->debug("Returning recent activity", [
            'requestedLimit' => $limit,
            'returnedCount' => count($result)
        ]);
        
        return $result;
    }
}