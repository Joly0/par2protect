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

    // Properties for configuration (set via configure method)
    private $logFile = '/boot/config/plugins/par2protect/par2protect.log'; // Default
    private $tmpLogFile = '/tmp/par2protect/logs/par2protect.log'; // Default
    private $backupPath = '/boot/config/plugins/par2protect/logs'; // Default
    private $maxSize = 10485760; // 10MB (Default)
    private $maxFiles = 5;       // Default
    private $currentLogLevel = self::INFO; // Default log level back to INFO
    private $isConfigured = false; // Flag
    private $logToStdout = false; // Flag to control console output

    // Make levels public and static for access from helper functions
    public static $levels = [
        'DEBUG' => self::DEBUG,
        'INFO' => self::INFO,
        'WARNING' => self::WARNING,
        'ERROR' => self::ERROR,
        'CRITICAL' => self::CRITICAL
    ];
    private $backupIntervals = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800
    ];

    /**
     * Constructor - Sets defaults. Configuration happens in configure().
     */
    public function __construct() {
        // Ensure default tmp/backup dirs exist (best effort)
        $tmpLogDir = dirname($this->tmpLogFile);
        if (!is_dir($tmpLogDir)) { @mkdir($tmpLogDir, 0755, true); }
        if (!is_dir($this->backupPath)) { @mkdir($this->backupPath, 0755, true); }
    }

    /**
     * Configure the logger instance using settings from the Config object.
     * This should be called after the container has instantiated both Logger and Config.
     * @param Config $config
     */
    public function configure(Config $config): void
    {
        try {
            $this->setLogFile($config->get('logging.path', $this->logFile));
            $this->setTmpLogFile($config->get('logging.tmp_path', $this->tmpLogFile));
            $this->setBackupPath($config->get('logging.backup_path', $this->backupPath));
            $this->setRotationSettings(
                $config->get('logging.max_size', 10), // Default 10MB
                $config->get('logging.max_files', 5)   // Default 5 files
            );
            $this->setLogLevelFromConfig($config); // Call the method to set the level
            $this->isConfigured = true;
             // Log configuration success *after* level is potentially set (use internal _log)
             $this->_log("Logger configured successfully. Level set to: " . $this->currentLogLevel, self::DEBUG, []);
        } catch (\Exception $e) {
             // Use error_log as logger might not be fully functional if configure failed
             error_log("Par2Protect Logger: Failed during configure(): " . $e->getMessage());
        }
    }

    /** Check if a log level should be logged */
    public function shouldLog($level): bool {
        // Restore original logic
        if ($level >= self::ERROR) return true;
        return $level >= $this->currentLogLevel;
    }

    /** Set log file path */
    public function setLogFile($path): void {
        $this->logFile = $path;
        $permLogDir = dirname($this->logFile);
         if (!is_dir($permLogDir)) { @mkdir($permLogDir, 0755, true); }
    }

    /** Set temporary log file path */
    public function setTmpLogFile($path): void {
        $this->tmpLogFile = $path;
        $tmpLogDir = dirname($this->tmpLogFile);
        if (!is_dir($tmpLogDir)) { @mkdir($tmpLogDir, 0755, true); }
    }

     /** Set backup path */
     public function setBackupPath($path): void {
         $this->backupPath = $path;
         if (!is_dir($this->backupPath)) { @mkdir($this->backupPath, 0755, true); }
     }

     /** Set rotation settings */
      public function setRotationSettings(int $maxSizeMB, int $maxFiles): void {
          $this->maxSize = $maxSizeMB * 1048576;
          $this->maxFiles = $maxFiles;
      }

     /** Set the current log level based on Config object */
     // Restored original logic
     public function setLogLevelFromConfig(Config $config): void {
         if ($config->get('debug.debug_logging', false)) {
             $configLevelSetting = $config->get('logging.level', self::DEBUG);
             $this->currentLogLevel = is_int($configLevelSetting) && $configLevelSetting >= self::DEBUG ? $configLevelSetting : self::DEBUG;
         } else {
             $configLevelSetting = $config->get('logging.level', self::INFO);
             $levelToUse = is_int($configLevelSetting) && $configLevelSetting >= self::DEBUG ? $configLevelSetting : self::INFO;
             $this->currentLogLevel = min($levelToUse, self::INFO);
         }
         // Removed diagnostic log
     }

    /** Get temporary log file path */
    public function getTmpLogFile(): string {
        return $this->tmpLogFile ?? '/tmp/par2protect/logs/par2protect.log';
    }

    /** Enable or disable logging to standard output */
    public function enableStdoutLogging(bool $enable): void {
        $this->logToStdout = $enable;
    }

    // --- Public Logging Methods ---

    public function debug($message, $context = []) { $this->_log($message, self::DEBUG, $context); }
    public function info($message, $context = []) { $this->_log($message, self::INFO, $context); }
    public function warning($message, $context = []) { $this->_log($message, self::WARNING, $context); }
    public function error($message, $context = []) { $this->_log($message, self::ERROR, $context); }
    public function critical($message, $context = []) { $this->_log($message, self::CRITICAL, $context); }

    /** Logs an operation activity specifically for the dashboard "Recent Activity". */
    public function logOperationActivity(string $action, string $status, ?string $path = null, ?string $details = null): void
    {
         $this->addToActivityLog($action, $status, $path, $details);
         $this->_log(
             "$action operation $status" . ($path ? " for $path" : ""),
             self::INFO,
             ['action' => $action, 'status' => $status, 'path' => $path, 'details_summary' => substr($details ?? '', 0, 100)]
         );
    }

    // --- Internal Implementation ---

    /** Internal log processing */
    private function _log(string $message, int $level, array $context = []): bool
    {
        // Use the shouldLog method to check level
        if (!$this->shouldLog($level)) {
            return false;
        }

        $config = null;
        if ($this->isConfigured) {
            try { $config = get_container()->get('config'); } catch (\Exception $_) {}
        }

        if (!$config && $level < self::ERROR) {
             if (!$this->isConfigured) error_log("Par2Protect Logger (pre-config): [$level] $message " . json_encode($context));
             return false;
        }

        $levelStr = array_search($level, self::$levels) ?: 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        if (!isset($context['_caller'])) {
             $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
             $context['_caller'] = isset($backtrace[1]) ? basename($backtrace[1]['file']) . ':' . $backtrace[1]['line'] : 'unknown';
        }
        $contextString = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $logEntry = "[$timestamp] $levelStr: $message" . ($contextString ? " $contextString" : "") . PHP_EOL;

        // Log to stdout if enabled
        if ($this->logToStdout) {
            echo $logEntry;
        }

        $this->checkRotation($config);

        $loggedToFile = false;
        $shouldLogToFileResult = $config ? $this->shouldLogToFile($levelStr, $config) : true;
        // Removed diagnostic log

        if ($shouldLogToFileResult) {
             $logPathTarget = $this->tmpLogFile ?? 'NULL_PATH';
             // Removed diagnostic log
             $writeResult = @file_put_contents($logPathTarget, $logEntry, FILE_APPEND);
             if ($writeResult === false) {
                  $errorDetails = error_get_last(); $errorMsg = $errorDetails['message'] ?? 'Unknown error';
                  error_log("Par2Protect Logger _log: FAILED to write to tmp log file: [" . $logPathTarget . "]. Error: " . $errorMsg);
             } else {
                  $loggedToFile = true;
             }
        } elseif (!$config && $level >= self::ERROR) {
             error_log("Par2Protect Logger (pre-config): " . $logEntry);
        }

        if ($config && $this->shouldLogToUnraid($levelStr, $config)) {
            $this->logToUnraid($message, $levelStr);
        }

        return $loggedToFile;
    }

    /** Log message to Unraid syslog */
    private function logToUnraid(string $message, string $level = 'INFO'): bool {
        $tag = '[INFO] PAR2Protect';
        if ($level === 'WARNING') $tag = '[WARNING] PAR2Protect';
        else if ($level === 'ERROR') $tag = '[ERROR] PAR2Protect';
        else if ($level === 'CRITICAL') $tag = '[CRITICAL] PAR2Protect';
        // Use absolute path for logger command
        $command = sprintf('/usr/bin/logger %s -t %s', escapeshellarg($message), escapeshellarg($tag));
        @exec($command, $output, $returnCode);
        return ($returnCode === 0);
    }

    /** Determine if we should log to Unraid based on configuration */
    private function shouldLogToUnraid(string $level, Config $config): bool {
        if (!$config) return false;
        $errorLogMode = $config->get('logging.error_log_mode', 'both');
        $levelNum = self::$levels[$level] ?? self::INFO;
        if ($levelNum === self::INFO) {
             return ($errorLogMode === 'syslog' || $errorLogMode === 'both');
        }
        return ($levelNum >= self::WARNING && ($errorLogMode === 'syslog' || $errorLogMode === 'both'));
    }

    /** Determine if we should log to file based on configuration */
    private function shouldLogToFile(string $level, Config $config): bool {
         if (!$config) return true;
         $levelNum = self::$levels[$level] ?? self::INFO;
         // Removed redundant call to shouldLog
         // Check error_log_mode for WARNING/ERROR/CRITICAL
         if ($levelNum >= self::WARNING) {
             $errorLogMode = $config->get('logging.error_log_mode', 'both');
             return ($errorLogMode === 'logfile' || $errorLogMode === 'both');
         }
         // Log DEBUG/INFO only if primary shouldLog check passed (implicit in _log)
         return true;
    }

    /** Check if log file needs rotation */
    private function checkRotation(?Config $config = null): void {
         $logPath = $this->tmpLogFile ?? null;
         if (!$logPath || !file_exists(dirname($logPath))) return;
         clearstatcache(true, $logPath);
         $currentSize = file_exists($logPath) ? @filesize($logPath) : 0;
         if ($currentSize === false) $currentSize = 0;
         $maxSizeBytes = $this->maxSize; $maxFilesCount = $this->maxFiles;
         if ($config) {
             $maxSizeMB = $config->get('logging.max_size', $this->maxSize / 1048576);
             $maxSizeBytes = $maxSizeMB * 1048576;
             $maxFilesCount = $config->get('logging.max_files', $this->maxFiles);
         }
         if ($currentSize > $maxSizeBytes) {
             $rotationMessage = "[" . date('Y-m-d H:i:s') . "] DEBUG: Rotating log file..." . PHP_EOL;
             @file_put_contents($logPath, $rotationMessage, FILE_APPEND);
             for ($i = $maxFilesCount - 1; $i > 0; $i--) {
                 $oldFile = $logPath . '.' . $i; $newFile = $logPath . '.' . ($i + 1);
                 if (file_exists($oldFile)) { @rename($oldFile, $newFile); }
             }
             if (file_exists($logPath)) {
                 if (!@rename($logPath, $logPath . '.1')) { @copy($logPath, $logPath . '.1'); @unlink($logPath); }
             }
             @touch($logPath); @chmod($logPath, 0600);
         }
    }

    /** Add entry to activity log (JSON file) */
    private function addToActivityLog(string $action, string $status, ?string $path = null, ?string $details = null): void {
        $logPath = $this->tmpLogFile ?? null; if (!$logPath) return;
        $activityLogFile = dirname($logPath) . '/activity.json';
        $maxEntries = 50; $maxDetailsLength = 1000;
        try {
            $activity = [];
            if (file_exists($activityLogFile)) {
                $content = @file_get_contents($activityLogFile);
                if ($content) { $activity = json_decode($content, true); if (!is_array($activity)) $activity = []; }
            }
            $newEntry = [
                'time' => date('Y-m-d H:i:s'), 'action' => $action, 'path' => $path,
                'status' => $status, 'details' => is_string($details) ? substr($details, 0, $maxDetailsLength) : null
            ];
            array_unshift($activity, $newEntry);
            if (count($activity) > $maxEntries) $activity = array_slice($activity, 0, $maxEntries);
            @file_put_contents($activityLogFile, json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Exception $e) { error_log("Par2Protect Logger: Exception writing activity log: " . $e->getMessage()); }
    }

    /** Backup logs to permanent storage */
    public function backupLogs($force = false): bool {
        $config = null;
        try { $config = get_container()->get('config'); } catch (\Exception $e) { error_log("Par2Protect Logger: Failed to get config for backupLogs: " . $e->getMessage()); return false; }
        $backupInterval = $config->get('logging.backup_interval', 'daily');
        if ($backupInterval === 'never' && !$force) return false;
        $lastBackupFile = $this->backupPath . '/last_backup';
        $lastBackupTime = file_exists($lastBackupFile) ? (int)@file_get_contents($lastBackupFile) : 0;
        $now = time(); $intervalSeconds = $this->backupIntervals[$backupInterval] ?? $this->backupIntervals['daily'];
        if (!$force && ($now - $lastBackupTime) < $intervalSeconds) return false;
        $logPath = $this->tmpLogFile ?? null;
        if ($logPath) {
             $backupFile = $this->backupPath . '/par2protect_' . date('Y-m-d_H-i-s') . '.log';
             if (file_exists($logPath)) @copy($logPath, $backupFile);
             $activityLogFile = dirname($logPath) . '/activity.json';
             $backupActivityFile = $this->backupPath . '/activity_' . date('Y-m-d_H-i-s') . '.json';
             if (file_exists($activityLogFile)) @copy($activityLogFile, $backupActivityFile);
        }
        @file_put_contents($lastBackupFile, $now);
        $retentionDays = $config->get('logging.retention_days', 7);
        if ($retentionDays > 0) {
             $cutoffTime = time() - ($retentionDays * 86400);
             $backupFiles = glob($this->backupPath . '/par2protect_*.log');
             $activityFiles = glob($this->backupPath . '/activity_*.json');
             $allBackupFiles = array_merge($backupFiles ?: [], $activityFiles ?: []);
             foreach ($allBackupFiles as $file) { if (@filemtime($file) < $cutoffTime) @unlink($file); }
        }
        return true;
    }

    /** Get recent activity */
    public function getRecentActivity($limit = 10, $type = null, $includeSystem = false): array {
        $logPath = $this->tmpLogFile ?? null; if (!$logPath) return [];
        $activityLogFile = dirname($logPath) . '/activity.json';
        if (!file_exists($activityLogFile)) return [];
        $content = @file_get_contents($activityLogFile); if (!$content) return [];
        $activity = json_decode($content, true); if (!is_array($activity)) return [];
        if ($type || !$includeSystem) {
            $activity = array_filter($activity, function($entry) use ($type, $includeSystem) {
                $typeMatch = !$type || (isset($entry['action']) && strtolower($entry['action']) === strtolower($type));
                $systemMatch = $includeSystem || (isset($entry['action']) && strtolower($entry['action']) !== 'system');
                return $typeMatch && $systemMatch;
            });
        }
        $activity = array_values($activity); // Re-index
        return array_slice($activity, 0, $limit);
    }
} // End of Logger class