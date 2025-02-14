<?php
/**
 * Monitor Script
 * 
 * This script monitors the plugin's status and health.
 * It can be run periodically via cron to check for issues.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

// Get components
$logger = Logger::getInstance();
$db = Database::getInstance();
$config = Config::getInstance();

// Function to log to both the logger and stdout
function log_message($message, $level = 'INFO') {
    global $logger;
    
    // Log to the logger
    if ($level === 'INFO') {
        $logger->info($message);
    } elseif ($level === 'ERROR') {
        $logger->error($message);
    } elseif ($level === 'WARNING') {
        $logger->warning($message);
    } elseif ($level === 'DEBUG') {
        $logger->debug($message);
    }
    
    // Also output to stdout for the script to capture
    echo date('[Y-m-d H:i:s]') . " $level: $message\n";
}

// Parse command line options
$options = getopt('', ['verbose::', 'fix::', 'email::']);
$verbose = isset($options['verbose']);
$fix = isset($options['fix']);
$email = isset($options['email']) ? $options['email'] : null;

log_message("Starting PAR2Protect monitor", $verbose ? 'DEBUG' : 'INFO');

// Check database
log_message("Checking database", $verbose ? 'DEBUG' : 'INFO');
$dbPath = $config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
$dbIssues = [];

// Check if database file exists
if (!file_exists($dbPath)) {
    $dbIssues[] = "Database file does not exist: $dbPath";
    log_message("Database file does not exist: $dbPath", 'ERROR');
    
    if ($fix) {
        log_message("Attempting to create database", 'WARNING');
        
        // Run init_db.php
        $initScript = __DIR__ . '/init_db.php';
        if (file_exists($initScript)) {
            $output = [];
            $returnCode = 0;
            exec("php $initScript 2>&1", $output, $returnCode);
            
            if ($returnCode === 0) {
                log_message("Database created successfully", 'INFO');
            } else {
                log_message("Failed to create database", 'ERROR');
                foreach ($output as $line) {
                    log_message("  $line", 'ERROR');
                }
            }
        } else {
            log_message("init_db.php not found at: $initScript", 'ERROR');
        }
    }
} else {
    // Check if database is readable
    try {
        // Check if required tables exist
        $tables = ['protected_items', 'verification_history', 'operation_queue'];
        foreach ($tables as $table) {
            $result = $db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=:name",
                [':name' => $table]
            );
            $row = $db->fetchOne($result);
            
            if (!$row) {
                $dbIssues[] = "Table '$table' does not exist";
                log_message("Table '$table' does not exist", 'ERROR');
                
                if ($fix) {
                    log_message("Attempting to reinitialize database", 'WARNING');
                    
                    // Run init_db.php
                    $initScript = __DIR__ . '/init_db.php';
                    if (file_exists($initScript)) {
                        $output = [];
                        $returnCode = 0;
                        exec("php $initScript 2>&1", $output, $returnCode);
                        
                        if ($returnCode === 0) {
                            log_message("Database reinitialized successfully", 'INFO');
                            break; // No need to check other tables
                        } else {
                            log_message("Failed to reinitialize database", 'ERROR');
                            foreach ($output as $line) {
                                log_message("  $line", 'ERROR');
                            }
                        }
                    } else {
                        log_message("init_db.php not found at: $initScript", 'ERROR');
                    }
                }
            }
        }
        
        // Check for stuck operations
        $result = $db->query(
            "SELECT * FROM operation_queue 
            WHERE status = 'processing' 
            AND started_at < :timeout",
            [':timeout' => time() - 3600] // 1 hour timeout
        );
        $stuckOperations = $db->fetchAll($result);
        
        if (!empty($stuckOperations)) {
            $dbIssues[] = "Found " . count($stuckOperations) . " stuck operations";
            log_message("Found " . count($stuckOperations) . " stuck operations", 'WARNING');
            
            if ($fix) {
                log_message("Attempting to fix stuck operations", 'WARNING');
                
                $db->beginTransaction();
                
                foreach ($stuckOperations as $op) {
                    log_message("Marking operation " . $op['id'] . " as failed", 'WARNING');
                    
                    $db->query(
                        "UPDATE operation_queue
                        SET status = 'failed',
                            completed_at = :now,
                            updated_at = :now,
                            result = :result
                        WHERE id = :id",
                        [
                            ':id' => $op['id'],
                            ':now' => time(),
                            ':result' => json_encode([
                                'success' => false,
                                'error' => 'Operation timed out or was interrupted'
                            ])
                        ]
                    );
                }
                
                $db->commit();
                log_message("Fixed stuck operations", 'INFO');
            }
        }
    } catch (Exception $e) {
        $dbIssues[] = "Database error: " . $e->getMessage();
        log_message("Database error: " . $e->getMessage(), 'ERROR');
    }
}

// Check queue processor
log_message("Checking queue processor", $verbose ? 'DEBUG' : 'INFO');
$queueIssues = [];

// Check if queue is enabled
$queueEnabled = $config->get('queue.enabled', true);
if (!$queueEnabled) {
    log_message("Queue is disabled in configuration", 'INFO');
} else {
    // Check if queue processor is running
    $queueLockFile = '/boot/config/plugins/par2protect/queue/processor.lock';
    $processorRunning = file_exists($queueLockFile);
    
    if (!$processorRunning) {
        // Check if there are pending operations
        try {
            $result = $db->query("SELECT COUNT(*) as count FROM operation_queue WHERE status = 'pending'");
            $row = $db->fetchOne($result);
            $pendingCount = $row['count'];
            
            if ($pendingCount > 0) {
                $queueIssues[] = "Queue processor not running but there are $pendingCount pending operations";
                log_message("Queue processor not running but there are $pendingCount pending operations", 'WARNING');
                
                if ($fix) {
                    log_message("Attempting to start queue processor", 'WARNING');
                    
                    // Start queue processor
                    $processorPath = $config->get('queue.processor_path', '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php');
                    if (file_exists($processorPath)) {
                        $command = "nohup php $processorPath " .
                                  ">> /boot/config/plugins/par2protect/queue_processor.log 2>&1 &";
                        exec($command);
                        log_message("Started queue processor", 'INFO');
                    } else {
                        log_message("Queue processor script not found at: $processorPath", 'ERROR');
                    }
                }
            } else {
                log_message("Queue processor not running but no pending operations", 'INFO');
            }
        } catch (Exception $e) {
            $queueIssues[] = "Error checking queue: " . $e->getMessage();
            log_message("Error checking queue: " . $e->getMessage(), 'ERROR');
        }
    } else {
        log_message("Queue processor is running", 'INFO');
        
        // Check if the process is actually running
        $pid = file_get_contents($queueLockFile);
        $pid = trim($pid);
        
        if (!empty($pid) && is_numeric($pid)) {
            // Check if process exists
            $processExists = file_exists("/proc/$pid");
            
            if (!$processExists) {
                $queueIssues[] = "Queue processor lock file exists but process $pid is not running";
                log_message("Queue processor lock file exists but process $pid is not running", 'WARNING');
                
                if ($fix) {
                    log_message("Removing stale lock file", 'WARNING');
                    @unlink($queueLockFile);
                    
                    // Start queue processor
                    $processorPath = $config->get('queue.processor_path', '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php');
                    if (file_exists($processorPath)) {
                        $command = "nohup php $processorPath " .
                                  ">> /boot/config/plugins/par2protect/queue_processor.log 2>&1 &";
                        exec($command);
                        log_message("Started queue processor", 'INFO');
                    } else {
                        log_message("Queue processor script not found at: $processorPath", 'ERROR');
                    }
                }
            } else {
                log_message("Queue processor is running with PID $pid", 'INFO');
            }
        } else {
            $queueIssues[] = "Queue processor lock file exists but contains invalid PID: $pid";
            log_message("Queue processor lock file exists but contains invalid PID: $pid", 'WARNING');
            
            if ($fix) {
                log_message("Removing invalid lock file", 'WARNING');
                @unlink($queueLockFile);
            }
        }
    }
}

// Check par2 command
log_message("Checking par2 command", $verbose ? 'DEBUG' : 'INFO');
$par2Issues = [];

// Check if par2 command is available
$output = [];
$returnCode = 0;
exec("which par2 2>&1", $output, $returnCode);

if ($returnCode !== 0) {
    $par2Issues[] = "par2 command not found";
    log_message("par2 command not found", 'ERROR');
} else {
    $par2Path = trim($output[0]);
    log_message("par2 command found at: $par2Path", 'INFO');
    
    // Check par2 version
    $output = [];
    $returnCode = 0;
    exec("par2 -V 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        $par2Issues[] = "Failed to get par2 version";
        log_message("Failed to get par2 version", 'ERROR');
    } else {
        $par2Version = trim($output[0]);
        log_message("par2 version: $par2Version", 'INFO');
        
        // Check if it's par2cmdline-turbo
        if (strpos($par2Version, 'turbo') === false) {
            $par2Issues[] = "par2 is not par2cmdline-turbo";
            log_message("par2 is not par2cmdline-turbo, some features may not work correctly", 'WARNING');
        }
    }
}

// Check log file
log_message("Checking log file", $verbose ? 'DEBUG' : 'INFO');
$logIssues = [];

// Check if log file exists and is writable
$logPath = $config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
if (!file_exists($logPath)) {
    log_message("Log file does not exist: $logPath", 'WARNING');
    
    // Try to create log file
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            $logIssues[] = "Failed to create log directory: $logDir";
            log_message("Failed to create log directory: $logDir", 'ERROR');
        }
    }
    
    if (!@touch($logPath)) {
        $logIssues[] = "Failed to create log file: $logPath";
        log_message("Failed to create log file: $logPath", 'ERROR');
    } else {
        log_message("Created log file: $logPath", 'INFO');
    }
} else if (!is_writable($logPath)) {
    $logIssues[] = "Log file is not writable: $logPath";
    log_message("Log file is not writable: $logPath", 'ERROR');
    
    if ($fix) {
        log_message("Attempting to fix log file permissions", 'WARNING');
        if (@chmod($logPath, 0644)) {
            log_message("Fixed log file permissions", 'INFO');
        } else {
            log_message("Failed to fix log file permissions", 'ERROR');
        }
    }
} else {
    log_message("Log file is writable: $logPath", 'INFO');
    
    // Check log file size
    $logSize = filesize($logPath);
    $maxSize = $config->get('logging.max_size', 10485760); // 10MB
    
    if ($logSize > $maxSize) {
        $logIssues[] = "Log file is too large: " . round($logSize / 1024 / 1024, 2) . " MB";
        log_message("Log file is too large: " . round($logSize / 1024 / 1024, 2) . " MB", 'WARNING');
        
        if ($fix) {
            log_message("Attempting to rotate log file", 'WARNING');
            
            // Rotate log file
            $maxFiles = $config->get('logging.max_files', 5);
            
            for ($i = $maxFiles - 1; $i > 0; $i--) {
                $oldFile = $logPath . '.' . $i;
                $newFile = $logPath . '.' . ($i + 1);
                
                if (file_exists($oldFile)) {
                    @rename($oldFile, $newFile);
                }
            }
            
            // Rename current log to .1
            @rename($logPath, $logPath . '.1');
            
            // Create new log file
            @touch($logPath);
            
            log_message("Rotated log file", 'INFO');
        }
    }
}

// Summarize issues
$allIssues = array_merge($dbIssues, $queueIssues, $par2Issues, $logIssues);
$issueCount = count($allIssues);

if ($issueCount === 0) {
    log_message("No issues found", 'INFO');
} else {
    log_message("Found $issueCount issues:", 'WARNING');
    foreach ($allIssues as $i => $issue) {
        log_message(($i + 1) . ". $issue", 'WARNING');
    }
}

// Send email if requested
if ($email && $issueCount > 0) {
    log_message("Sending email to: $email", 'INFO');
    
    $subject = "PAR2Protect Monitor: $issueCount issues found";
    $message = "PAR2Protect Monitor Report\n\n";
    $message .= "Found $issueCount issues:\n\n";
    
    foreach ($allIssues as $i => $issue) {
        $message .= ($i + 1) . ". $issue\n";
    }
    
    $message .= "\n\nThis email was sent by the PAR2Protect monitor script.";
    
    if (mail($email, $subject, $message)) {
        log_message("Email sent successfully", 'INFO');
    } else {
        log_message("Failed to send email", 'ERROR');
    }
}

log_message("Monitor completed", 'INFO');