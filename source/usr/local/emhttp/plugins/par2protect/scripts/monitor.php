<?php
/**
 * Monitor Script
 * 
 * This script monitors the plugin's status and health.
 * It can be run periodically via cron to check for issues.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statements
// use Par2Protect\Core\Database;
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\Config;

// Get components from container
$container = get_container();
$logger = $container->get('logger');
$db = $container->get('database');
$config = $container->get('config');

// Enable console output for this script
$logger->enableStdoutLogging(true);


// Parse command line options
$options = getopt('', ['verbose::', 'fix::', 'email::']);
$verbose = isset($options['verbose']);
$fix = isset($options['fix']);
$email = isset($options['email']) ? $options['email'] : null;

if ($verbose) { $logger->debug("Starting PAR2Protect monitor"); } else { $logger->info("Starting PAR2Protect monitor"); }

// Check database
if ($verbose) { $logger->debug("Checking database"); } else { $logger->info("Checking database"); }
$dbPath = $config->get('database.path', '/boot/config/plugins/par2protect/par2protect.db');
$dbIssues = [];

// Check if database file exists
if (!file_exists($dbPath)) {
    $dbIssues[] = "Database file does not exist: $dbPath";
    $logger->error("Database file does not exist: $dbPath");
    
    if ($fix) {
        $logger->warning("Attempting to create database");
        
        // Run init_db.php
        $initScript = __DIR__ . '/init_db.php';
        if (file_exists($initScript)) {
            $output = [];
            $returnCode = 0;
            exec("php $initScript 2>&1", $output, $returnCode);
            
            if ($returnCode === 0) {
                $logger->info("Database created successfully");
            } else {
                $logger->error("Failed to create database");
                foreach ($output as $line) {
                    $logger->error("  $line");
                }
            }
        } else {
            $logger->error("init_db.php not found at: $initScript");
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
                $logger->error("Table '$table' does not exist");
                
                if ($fix) {
                    $logger->warning("Attempting to reinitialize database");
                    
                    // Run init_db.php
                    $initScript = __DIR__ . '/init_db.php';
                    if (file_exists($initScript)) {
                        $output = [];
                        $returnCode = 0;
                        exec("php $initScript 2>&1", $output, $returnCode);
                        
                        if ($returnCode === 0) {
                            $logger->info("Database reinitialized successfully");
                            break; // No need to check other tables
                        } else {
                            $logger->error("Failed to reinitialize database");
                            foreach ($output as $line) {
                                $logger->error("  $line");
                            }
                        }
                    } else {
                        $logger->error("init_db.php not found at: $initScript");
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
            $logger->warning("Found " . count($stuckOperations) . " stuck operations");
            
            if ($fix) {
                $logger->warning("Attempting to fix stuck operations");
                
                $db->beginTransaction();
                
                foreach ($stuckOperations as $op) {
                    $logger->warning("Marking operation " . $op['id'] . " as failed");
                    
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
                $logger->info("Fixed stuck operations");
            }
        }
    } catch (Exception $e) {
        $dbIssues[] = "Database error: " . $e->getMessage();
        $logger->error("Database error: " . $e->getMessage());
    }
}

// Check queue processor
if ($verbose) { $logger->debug("Checking queue processor"); } else { $logger->info("Checking queue processor"); }
$queueIssues = [];

// Check if queue is enabled
$queueEnabled = $config->get('queue.enabled', true);
if (!$queueEnabled) {
    $logger->info("Queue is disabled in configuration");
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
                $logger->warning("Queue processor not running but there are $pendingCount pending operations");
                
                if ($fix) {
                    $logger->warning("Attempting to start queue processor");
                    
                    // Start queue processor
                    $processorPath = $config->get('queue.processor_path', '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php');
                    if (file_exists($processorPath)) {
                        $command = "nohup php $processorPath " .
                                  ">> /boot/config/plugins/par2protect/queue_processor.log 2>&1 &";
                        exec($command);
                        $logger->info("Started queue processor");
                    } else {
                        $logger->error("Queue processor script not found at: $processorPath");
                    }
                }
            } else {
                $logger->info("Queue processor not running but no pending operations");
            }
        } catch (Exception $e) {
            $queueIssues[] = "Error checking queue: " . $e->getMessage();
            $logger->error("Error checking queue: " . $e->getMessage());
        }
    } else {
        $logger->info("Queue processor is running");
        
        // Check if the process is actually running
        $pid = file_get_contents($queueLockFile);
        $pid = trim($pid);
        
        if (!empty($pid) && is_numeric($pid)) {
            // Check if process exists
            $processExists = file_exists("/proc/$pid");
            
            if (!$processExists) {
                $queueIssues[] = "Queue processor lock file exists but process $pid is not running";
                $logger->warning("Queue processor lock file exists but process $pid is not running");
                
                if ($fix) {
                    $logger->warning("Removing stale lock file");
                    @unlink($queueLockFile);
                    
                    // Start queue processor
                    $processorPath = $config->get('queue.processor_path', '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php');
                    if (file_exists($processorPath)) {
                        $command = "nohup php $processorPath " .
                                  ">> /boot/config/plugins/par2protect/queue_processor.log 2>&1 &";
                        exec($command);
                        $logger->info("Started queue processor");
                    } else {
                        $logger->error("Queue processor script not found at: $processorPath");
                    }
                }
            } else {
                $logger->info("Queue processor is running with PID $pid");
            }
        } else {
            $queueIssues[] = "Queue processor lock file exists but contains invalid PID: $pid";
            $logger->warning("Queue processor lock file exists but contains invalid PID: $pid");
            
            if ($fix) {
                $logger->warning("Removing invalid lock file");
                @unlink($queueLockFile);
            }
        }
    }
}

// Check par2 command
if ($verbose) { $logger->debug("Checking par2 command"); } else { $logger->info("Checking par2 command"); }
$par2Issues = [];

// Check if par2 command is available
$output = [];
$returnCode = 0;
exec("which par2 2>&1", $output, $returnCode);

if ($returnCode !== 0) {
    $par2Issues[] = "par2 command not found";
    $logger->error("par2 command not found");
} else {
    $par2Path = trim($output[0]);
    $logger->info("par2 command found at: $par2Path");
    
    // Check par2 version
    $output = [];
    $returnCode = 0;
    exec("par2 -V 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        $par2Issues[] = "Failed to get par2 version";
        $logger->error("Failed to get par2 version");
    } else {
        $par2Version = trim($output[0]);
        $logger->info("par2 version: $par2Version");
        
        // Check if it's par2cmdline-turbo
        if (strpos($par2Version, 'turbo') === false) {
            $par2Issues[] = "par2 is not par2cmdline-turbo";
            $logger->warning("par2 is not par2cmdline-turbo, some features may not work correctly");
        }
    }
}

// Check log file
if ($verbose) { $logger->debug("Checking log file"); } else { $logger->info("Checking log file"); }
$logIssues = [];

// Check if log file exists and is writable
$logPath = $config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
if (!file_exists($logPath)) {
    $logger->warning("Log file does not exist: $logPath");
    
    // Try to create log file
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            $logIssues[] = "Failed to create log directory: $logDir";
            $logger->error("Failed to create log directory: $logDir");
        }
    }
    
    if (!@touch($logPath)) {
        $logIssues[] = "Failed to create log file: $logPath";
        $logger->error("Failed to create log file: $logPath");
    } else {
        $logger->info("Created log file: $logPath");
    }
} else if (!is_writable($logPath)) {
    $logIssues[] = "Log file is not writable: $logPath";
    $logger->error("Log file is not writable: $logPath");
    
    if ($fix) {
        $logger->warning("Attempting to fix log file permissions");
        if (@chmod($logPath, 0644)) {
            $logger->info("Fixed log file permissions");
        } else {
            $logger->error("Failed to fix log file permissions");
        }
    }
} else {
    $logger->info("Log file is writable: $logPath");
    
    // Check log file size
    $logSize = filesize($logPath);
    $maxSize = $config->get('logging.max_size', 10485760); // 10MB
    
    if ($logSize > $maxSize) {
        $logIssues[] = "Log file is too large: " . round($logSize / 1024 / 1024, 2) . " MB";
        $logger->warning("Log file is too large: " . round($logSize / 1024 / 1024, 2) . " MB");
        
        if ($fix) {
            $logger->warning("Attempting to rotate log file");
            
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
            
            $logger->info("Rotated log file");
        }
    }
}

// Summarize issues
$allIssues = array_merge($dbIssues, $queueIssues, $par2Issues, $logIssues);
$issueCount = count($allIssues);

if ($issueCount === 0) {
    $logger->info("No issues found");
} else {
    $logger->warning("Found $issueCount issues:");
    foreach ($allIssues as $i => $issue) {
        $logger->warning(($i + 1) . ". $issue");
    }
}

// Send email if requested
if ($email && $issueCount > 0) {
    $logger->info("Sending email to: $email");
    
    $subject = "PAR2Protect Monitor: $issueCount issues found";
    $message = "PAR2Protect Monitor Report\n\n";
    $message .= "Found $issueCount issues:\n\n";
    
    foreach ($allIssues as $i => $issue) {
        $message .= ($i + 1) . ". $issue\n";
    }
    
    $message .= "\n\nThis email was sent by the PAR2Protect monitor script.";
    
    if (mail($email, $subject, $message)) {
        $logger->info("Email sent successfully");
    } else {
        $logger->error("Failed to send email");
    }
}

$logger->info("Monitor completed");