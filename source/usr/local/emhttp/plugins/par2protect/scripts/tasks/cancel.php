<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/logging.php");

// Set content type to JSON
header('Content-Type: application/json');

try {
    $par2 = Par2::getInstance();
    $logger = Logger::getInstance();
    
    // Get operation ID from request
    $operationId = $_POST['operation_id'] ?? '';
    
    if (empty($operationId)) {
        throw new \Exception('No operation ID provided');
    }
    
    // Validate operation ID format
    if (!preg_match('/^op_[a-f0-9]+(\.[0-9]+)?$/', $operationId)) {
        throw new \Exception('Invalid operation ID format');
    }
    
    // Get current status to find the process
    $status = $par2->getStatus();
    
    if (!$status['running']) {
        throw new \Exception('No active operations found');
    }
    
    $logger->debug("Attempting to cancel operation", [
        'operation_id' => $operationId,
        'active_processes' => count($status['processes'])
    ]);
    
    $found = false;
    $killedPids = [];
    foreach ($status['processes'] as $process) {
        if (isset($process['operation_id']) && $process['operation_id'] === $operationId) {
            // Try to kill the process gracefully first
            $pid = $process['pid'];
            exec("kill -15 {$pid} 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                // If graceful kill fails, try force kill
                exec("kill -9 {$pid} 2>&1", $output, $returnCode);
            }
            
            // Verify process was killed
            if ($returnCode === 0) {
                $killedPids[] = $pid;
                $found = true;
                
                $logger->debug("Process killed", [
                    'pid' => $pid,
                    'command' => $process['command'],
                    'kill_output' => $output
                ]);
            } else {
                throw new \Exception("Failed to kill process {$pid}: " . implode("\n", $output));
            }
        }
    }
    
    if (!$found) {
        throw new \Exception('Operation not found in active processes');
    }
    
    // Clean up any temporary files
    $tempPattern = sys_get_temp_dir() . '/par2protect_*';
    foreach (glob($tempPattern) as $tempFile) {
        if (is_file($tempFile)) {
            $logger->debug("Cleaning up temporary file", ['file' => $tempFile]);
            unlink($tempFile);
        }
    }
    
    // Wait briefly and verify processes are gone
    usleep(100000); // 100ms
    foreach ($killedPids as $pid) {
        if (file_exists("/proc/{$pid}")) {
            $logger->warning("Process still exists after kill", ['pid' => $pid]);
        }
    }
    
    // Log cancellation
    $logger->info("Operation cancelled successfully", [
        'operation_id' => $operationId,
        'killed_processes' => $killedPids
    ]);
    
    echo json_encode([
        'success' => true,
        'killed_processes' => count($killedPids)
    ]);
    
} catch (\Exception $e) {
    $logger->error("Failed to cancel operation", [
        'error' => $e->getMessage(),
        'operation_id' => $operationId ?? null,
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}