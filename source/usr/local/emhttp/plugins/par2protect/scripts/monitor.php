<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/bootstrap.php");

// Initialize components
$logger = Logger::getInstance();
$par2 = Par2::getInstance();
$verificationManager = VerificationManager::getInstance();

$logger->info("Starting monitor script");

while (true) {
    try {
        // Get current status
        /*
        $status = $par2->getStatus();
        $logger->debug("Par2 status check", [
            'running' => $status['running'],
            'processes' => $status['processes'] ?? [],
            'pid' => getmypid(),
            'memory' => memory_get_usage(true),
            'time' => date('Y-m-d H:i:s')
        ]);
        */
        
        if ($status['running']) {
            $logger->debug("Active processes found", [
                'count' => count($status['processes'])
            ]);
            
            foreach ($status['processes'] as $process) {
                // Extract operation ID and path from command
                $operationId = null;
                $targetPath = null;
                $commandLines = explode("\n", $process['command']);
                foreach ($commandLines as $line) {
                    if (strpos($line, 'PAR2_OPERATION_ID=') !== false) {
                        preg_match('/PAR2_OPERATION_ID=\'([^\']+)\'/', $line, $matches);
                        if (isset($matches[1])) {
                            $operationId = $matches[1];
                        }
                    } else if (strpos($line, 'PAR2_PATH=') !== false) {
                        preg_match('/PAR2_PATH=\'([^\']+)\'/', $line, $matches);
                        if (isset($matches[1])) {
                            $targetPath = $matches[1];
                        }
                    }
                }
                
                if ($operationId && $targetPath) {
                    $logger->debug("Found operation details", [
                        'operation_id' => $operationId,
                        'path' => $targetPath,
                        'command' => $process['command']
                    ]);
                    
                    // Check if process has completed
                    $output = [];
                    exec("ps -p {$process['pid']} -o pid=", $output);
                    
                    $logger->debug("Process check", [
                        'pid' => $process['pid'],
                        'ps_output' => $output,
                        'operation_id' => $operationId
                    ]);
                    
                    if (empty($output)) {
                        // Process has completed
                        $logger->info("Process completed", [
                            'pid' => $process['pid'],
                            'operation_id' => $operationId
                        ]);
                        
                        // Get command output
                        $result = $par2->getCommandResult($process['pid']);
                        if ($result && isset($result['output'])) {
                            $commandOutput = $result['output'];
                            
                            // Determine status from output
                            $status = 'UNKNOWN';
                            $details = [];
                            
                            // Parse verification output
                            if (strpos($commandOutput, 'All files are correct') !== false) {
                                $status = 'PROTECTED';
                            } else {
                                // Check for various error conditions
                                if (preg_match('/Target: "([^"]+)" - missing\./', $commandOutput, $matches)) {
                                    $status = 'MISSING';
                                    $details['missing_files'][] = $matches[1];
                                }
                                if (preg_match('/Target: "([^"]+)" - damaged\./', $commandOutput, $matches)) {
                                    $status = 'DAMAGED';
                                    $details['damaged_files'][] = $matches[1];
                                }
                                if (strpos($commandOutput, 'Repair is required') !== false) {
                                    $status = 'DAMAGED';
                                    $details['needs_repair'] = true;
                                }
                                if (strpos($commandOutput, 'No PAR2 recovery blocks') !== false) {
                                    $status = 'ERROR';
                                    $details['error'] = 'No recovery blocks found';
                                }
                            }
                            
                            $logger->info("Verification completed", [
                                'path' => $targetPath,
                                'operation_id' => $operationId,
                                'status' => $status,
                                'details' => $details
                            ]);
                            
                            // Update verification status
                            $verificationManager->updateStatus($targetPath, $status, [
                                'operation_id' => $operationId,
                                'output' => $commandOutput,
                                'details' => $details
                            ]);
                        } else {
                            $logger->warning("No command output found", [
                                'operation_id' => $operationId,
                                'path' => $targetPath
                            ]);
                        }
                    }
                }
            }
        }
        
        // Sleep for a short period before next check
        sleep(5);
        
    } catch (\Exception $e) {
        $logger->error("Monitor error", ['exception' => $e]);
        sleep(10); // Sleep longer on error
    }
}
