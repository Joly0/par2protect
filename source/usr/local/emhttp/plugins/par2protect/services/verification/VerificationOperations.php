<?php
namespace Par2Protect\Services\Verification;

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

/**
 * Handles verification and repair operations using par2 commands
 */
class VerificationOperations {
    private $logger;
    private $config;
    
    /**
     * VerificationOperations constructor
     *
     * @param Logger $logger Logger instance
     * @param Config $config Config instance
     */
    public function __construct($logger, $config) {
        $this->logger = $logger;
        $this->config = $config;
    }
    
    /**
     * Verify par2 files
     *
     * @param string $path Path to verify
     * @param string $par2Path Path to par2 file
     * @param string $mode Verification mode (file or directory)
     * @return array
     */
    public function verifyPar2Files($path, $par2Path, $mode) {
        // Build par2 command with base path
        // For directories, use the directory path as the base path
        // For files, use the parent directory as the base path
        $isIndividualFiles = strpos($mode, 'Individual Files') === 0;
        $basePath = ($mode === 'directory' || $isIndividualFiles) ? $path : dirname($path);
        
        // Standard verification for regular files and directories
        if (!$isIndividualFiles || !is_dir($par2Path)) {
            // Add diagnostic logging for par2 command construction
            $this->logger->debug("DIAGNOSTIC: Par2 command construction for standard mode", [
                'path' => $path,
                'mode' => $mode,
                'is_individual_files' => $isIndividualFiles ? 'true' : 'false',
                'base_path' => $basePath,
                'par2_path' => $par2Path
            ]);
            
            // Build par2 command with resource limit parameters
            $command = "par2 verify -q -B\"$basePath\" \"$par2Path\"";
            
            // Add resource limit parameters
            $command = $this->addResourceLimitParameters($command);
            
            // Execute par2 command
            $this->logger->debug("Executing par2 command", [
                'command' => $command
            ]);
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            // Parse output
            $outputStr = implode("\n", $output);
            $status = 'UNKNOWN';
            $details = $outputStr;
            
            if ($returnCode === 0) {
                $status = 'VERIFIED';
            } else if (strpos($outputStr, 'damaged') !== false) {
                $status = 'DAMAGED';
            } else if (strpos($outputStr, 'missing') !== false) {
                $status = 'MISSING';
            } else {
                $status = 'ERROR';
            }
            
            return [
                'status' => $status,
                'details' => $details
            ];
        }
        
        // For Individual Files mode, we need to verify all .par2 files in the directory
        if ($isIndividualFiles && is_dir($par2Path)) {
            $this->logger->debug("DIAGNOSTIC: Individual Files mode detected, par2_path is a directory", [
                'path' => $path,
                'par2_path' => $par2Path,
                'mode' => $mode
            ]);
            
            // Find all main .par2 files in the directory (not the volume files)
            $par2Files = glob($par2Path . '/*.par2');
            $mainPar2Files = [];
            
            // Filter out volume files (they have .volXXX+XX.par2 pattern)
            foreach ($par2Files as $file) {
                if (!preg_match('/\.vol\d+\+\d+\.par2$/', $file)) {
                    $mainPar2Files[] = $file;
                }
            }
            
            $this->logger->debug("DIAGNOSTIC: Found main par2 files in directory", [
                'par2_path' => $par2Path,
                'par2_files_count' => count($mainPar2Files),
                'par2_files' => $mainPar2Files
            ]);
            
            if (count($mainPar2Files) === 0) {
                throw new \Exception("No main .par2 files found in directory: $par2Path");
            }
            
            // Verify each .par2 file and collect results
            $overallStatus = 'VERIFIED';
            $allDetails = [];
            $verifiedCount = 0;
            $damagedCount = 0;
            $missingCount = 0;
            $errorCount = 0;
            
            foreach ($mainPar2Files as $par2File) {
                // Build par2 command with resource limit parameters
                $command = "par2 verify -q -B\"$basePath\" \"$par2File\"";
                
                // Add resource limit parameters
                $command = $this->addResourceLimitParameters($command);
                
                $this->logger->debug("DIAGNOSTIC: Verifying individual file", [
                    'par2_file' => $par2File,
                    'command' => $command
                ]);
                
                exec($command . ' 2>&1', $output, $returnCode);
                $outputStr = implode("\n", $output);
                $fileStatus = 'UNKNOWN';
                
                if ($returnCode === 0) {
                    $fileStatus = 'VERIFIED';
                    $verifiedCount++;
                } else if (strpos($outputStr, 'damaged') !== false) {
                    $fileStatus = 'DAMAGED';
                    $damagedCount++;
                    // If any file is damaged, the overall status is DAMAGED
                    $overallStatus = 'DAMAGED';
                } else if (strpos($outputStr, 'missing') !== false) {
                    $fileStatus = 'MISSING';
                    $missingCount++;
                    // If any file is missing, the overall status is MISSING
                    if ($overallStatus !== 'DAMAGED') {
                        $overallStatus = 'MISSING';
                    }
                } else {
                    $fileStatus = 'ERROR';
                    $errorCount++;
                    // If any file has an error, the overall status is ERROR
                    if ($overallStatus !== 'DAMAGED' && $overallStatus !== 'MISSING') {
                        $overallStatus = 'ERROR';
                    }
                }
                
                // Extract the original filename by removing the .par2 extension
                $originalFilename = basename($par2File);
                if (substr($originalFilename, -5) === '.par2') {
                    $originalFilename = substr($originalFilename, 0, -5);
                }
                
                // Add diagnostic logging for filename transformation
                $this->logger->debug("DIAGNOSTIC: Filename transformation", [
                    'par2_file' => basename($par2File),
                    'original_filename' => $originalFilename
                ]);
                
                $allDetails[] = $originalFilename . ": " . $fileStatus;
                
                $this->logger->debug("DIAGNOSTIC: Individual file verification result", [
                    'par2_file' => $par2File,
                    'status' => $fileStatus
                ]);
            }
            
            // Create a summary of the verification results
            $detailsSummary = "Verified: $verifiedCount, Damaged: $damagedCount, Missing: $missingCount, Error: $errorCount\n";
            $detailsSummary .= implode("\n", $allDetails);
            
            $this->logger->debug("DIAGNOSTIC: Overall verification result for Individual Files", [
                'path' => $path,
                'verified_count' => $verifiedCount,
                'damaged_count' => $damagedCount,
                'missing_count' => $missingCount,
                'error_count' => $errorCount,
                'overall_status' => $overallStatus
            ]);
            
            return [
                'status' => $overallStatus,
                'details' => $detailsSummary
            ];
        }
    }
    
    /**
     * Repair par2 files
     *
     * @param string $path Path to repair
     * @param string $par2Path Path to par2 file
     * @param string $mode Repair mode (file or directory)
     * @return array
     */
    public function repairPar2Files($path, $par2Path, $mode) {
        // Build par2 command with base path
        // For directories, use the directory path as the base path
        // For files, use the parent directory as the base path
        $isIndividualFiles = strpos($mode, 'Individual Files') === 0;
        $basePath = ($mode === 'directory' || $isIndividualFiles) ? $path : dirname($path);
        
        // Standard repair for regular files and directories
        if (!$isIndividualFiles || !is_dir($par2Path)) {
            // Add diagnostic logging for par2 command construction
            $this->logger->debug("DIAGNOSTIC: Par2 repair command construction for standard mode", [
                'path' => $path,
                'mode' => $mode,
                'is_individual_files' => $isIndividualFiles ? 'true' : 'false',
                'base_path' => $basePath,
                'par2_path' => $par2Path
            ]);
            
            // Build par2 command with resource limit parameters
            $command = "par2 repair -q -B\"$basePath\" \"$par2Path\"";
            
            // Add resource limit parameters
            $command = $this->addResourceLimitParameters($command);
            
            // Execute par2 command
            $this->logger->debug("Executing par2 command", [
                'command' => $command
            ]);
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            // Parse output
            $outputStr = implode("\n", $output);
            $status = 'UNKNOWN';
            $details = $outputStr;
            
            if ($returnCode === 0) {
                $status = 'REPAIRED';
            } else if (strpos($outputStr, 'repair complete') !== false) {
                $status = 'REPAIRED';
            } else if (strpos($outputStr, 'repair is not possible') !== false || strpos($outputStr, 'repair not possible') !== false) {
                // When repair is not possible due to too many missing files
                if (strpos($outputStr, 'too many') !== false || strpos($outputStr, 'not enough recovery blocks') !== false) {
                    $status = 'MISSING';
                } else {
                    $status = 'REPAIR_FAILED';
                }
            } else {
                $status = 'ERROR';
            }
            
            return [
                'status' => $status,
                'details' => $details
            ];
        }
        
        // For Individual Files mode, we need to repair all .par2 files in the directory
        if ($isIndividualFiles && is_dir($par2Path)) {
            $this->logger->debug("DIAGNOSTIC: Individual Files mode detected, par2_path is a directory", [
                'path' => $path,
                'par2_path' => $par2Path,
                'mode' => $mode
            ]);
            
            // Find all main .par2 files in the directory (not the volume files)
            $par2Files = glob($par2Path . '/*.par2');
            $mainPar2Files = [];
            
            // Filter out volume files (they have .volXXX+XX.par2 pattern)
            foreach ($par2Files as $file) {
                if (!preg_match('/\.vol\d+\+\d+\.par2$/', $file)) {
                    $mainPar2Files[] = $file;
                }
            }
            
            $this->logger->debug("DIAGNOSTIC: Found main par2 files in directory", [
                'par2_path' => $par2Path,
                'par2_files_count' => count($mainPar2Files),
                'par2_files' => $mainPar2Files
            ]);
            
            if (count($mainPar2Files) === 0) {
                throw new \Exception("No main .par2 files found in directory: $par2Path");
            }
            
            // Repair each .par2 file and collect results
            $overallStatus = 'REPAIRED';
            $allDetails = [];
            $repairedCount = 0;
            $missingCount = 0;
            $failedCount = 0;
            $errorCount = 0;
            
            foreach ($mainPar2Files as $par2File) {
                // Build par2 command with resource limit parameters
                $command = "par2 repair -q -B\"$basePath\" \"$par2File\"";
                
                // Add resource limit parameters
                $command = $this->addResourceLimitParameters($command);
                
                $this->logger->debug("DIAGNOSTIC: Repairing individual file", [
                    'par2_file' => $par2File,
                    'command' => $command
                ]);
                
                exec($command . ' 2>&1', $output, $returnCode);
                $outputStr = implode("\n", $output);
                $fileStatus = 'UNKNOWN';
                
                if ($returnCode === 0 || strpos($outputStr, 'repair complete') !== false) {
                    $fileStatus = 'REPAIRED';
                    $repairedCount++;
                } else if (strpos($outputStr, 'repair is not possible') !== false || strpos($outputStr, 'repair not possible') !== false) {
                    if (strpos($outputStr, 'too many') !== false || strpos($outputStr, 'not enough recovery blocks') !== false) {
                        $fileStatus = 'MISSING';
                        $missingCount++;
                        // If any file is missing, the overall status is MISSING
                        if ($overallStatus === 'REPAIRED') {
                            $overallStatus = 'MISSING';
                        }
                    } else {
                        $fileStatus = 'REPAIR_FAILED';
                        $failedCount++;
                        // If any file repair failed, the overall status is REPAIR_FAILED
                        if ($overallStatus === 'REPAIRED') {
                            $overallStatus = 'REPAIR_FAILED';
                        }
                    }
                } else {
                    $fileStatus = 'ERROR';
                    $errorCount++;
                    // If any file has an error, the overall status is ERROR
                    if ($overallStatus === 'REPAIRED') {
                        $overallStatus = 'ERROR';
                    }
                }
                
                // Extract the original filename by removing the .par2 extension
                $originalFilename = basename($par2File);
                if (substr($originalFilename, -5) === '.par2') {
                    $originalFilename = substr($originalFilename, 0, -5);
                }
                
                // Add diagnostic logging for filename transformation
                $this->logger->debug("DIAGNOSTIC: Filename transformation", [
                    'par2_file' => basename($par2File),
                    'original_filename' => $originalFilename
                ]);
                
                $allDetails[] = $originalFilename . ": " . $fileStatus;
                
                $this->logger->debug("DIAGNOSTIC: Individual file repair result", [
                    'par2_file' => $par2File,
                    'status' => $fileStatus
                ]);
            }
            
            // Create a summary of the repair results
            $detailsSummary = "Repaired: $repairedCount, Missing: $missingCount, Failed: $failedCount, Error: $errorCount\n";
            $detailsSummary .= implode("\n", $allDetails);
            
            $this->logger->debug("DIAGNOSTIC: Overall repair result for Individual Files", [
                'path' => $path,
                'repaired_count' => $repairedCount,
                'missing_count' => $missingCount,
                'failed_count' => $failedCount,
                'error_count' => $errorCount,
                'overall_status' => $overallStatus
            ]);
            
            return [
                'status' => $overallStatus,
                'details' => $detailsSummary
            ];
        }
    }
    
    /**
     * Add resource limit parameters to a par2 command
     *
     * @param string $command The par2 command
     * @return string The command with resource limit parameters
     */
    private function addResourceLimitParameters($command) {
        // Add -t parameter for CPU threads if set
        $maxCpuThreads = $this->config->get('resource_limits.max_cpu_usage');
        if ($maxCpuThreads) {
            $command .= " -t$maxCpuThreads";
        }
        
        // Add -m parameter for memory usage if set
        $maxMemory = $this->config->get('resource_limits.max_memory_usage');
        if ($maxMemory) {
            $command .= " -m$maxMemory";
        }
        
        // Add -T parameter for parallel file hashing if set
        $parallelFileHashing = $this->config->get('resource_limits.parallel_file_hashing');
        if ($parallelFileHashing) {
            $command .= " -T$parallelFileHashing";
        }
        
        // Apply I/O priority if set
        $ioPriority = $this->config->get('resource_limits.io_priority');
        if ($ioPriority) {
            // Map priority levels to ionice classes
            $ioniceClass = 2; // Default to best-effort class
            $ioniceLevel = 4; // Default to normal priority (range 0-7)
            
            if ($ioPriority === 'high') {
                $ioniceLevel = 0; // Highest priority in best-effort class
            } elseif ($ioPriority === 'normal') {
                $ioniceLevel = 4; // Normal priority
            } elseif ($ioPriority === 'low') {
                $ioniceLevel = 7; // Lowest priority
            }
            
            // Prepend ionice command to set I/O priority
            $command = "ionice -c $ioniceClass -n $ioniceLevel " . $command;
        }
        
        return $command;
    }
}