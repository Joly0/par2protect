<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Protection\Helpers\FormatHelper;

/**
 * Operations class for handling protection operations
 */
class ProtectionOperations {
    private $logger;
    private $config;
    private $formatHelper;
    
    /**
     * ProtectionOperations constructor
     *
     * @param Logger $logger Logger instance
     * @param Config $config Config instance
     * @param FormatHelper $formatHelper Format helper instance
     */
    public function __construct(Logger $logger, Config $config, FormatHelper $formatHelper) {
        $this->logger = $logger;
        $this->config = $config;
        $this->formatHelper = $formatHelper;
    }
    
    /**
     * Create PAR2 files for a path
     *
     * @param string $path Path to protect
     * @param int $redundancy Redundancy percentage
     * @param string $mode Protection mode (file or directory)
     * @param array $fileTypes File types to protect (for directory mode)
     * @param array $fileCategories File categories selected by the user
     * @param string $customParityDir Custom parity directory
     * @param array $advancedSettings Advanced settings for par2 command
     * @return array
     */
    public function createPar2Files($path, $redundancy, $mode, $fileTypes = null, $fileCategories = null, $customParityDir = null, $advancedSettings = null) {
        // Add diagnostic logging for file types
        $operationId = uniqid('par2_');
        if ($mode === 'directory' && $fileTypes && !empty($fileTypes)) {
            $this->logger->debug("Creating par2 files with file type filtering", [
                'path' => $path,
                'file_types' => json_encode($fileTypes),
                'file_categories' => is_array($fileCategories) ? json_encode($fileCategories) : $fileCategories,
                'advanced_settings' => $advancedSettings ? json_encode($advancedSettings) : null
            ]);
        }
        
        // If a custom parity directory is provided, use it
        if ($customParityDir !== null) {
            $parityDir = $customParityDir;
            $this->logger->debug("Using custom parity directory", ['parity_dir' => $parityDir]);
        }
        // Otherwise determine parity directory name based on mode and file types
        $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
        
        // For individual files with specific file types, use category-specific parity directory
        if ($mode === 'file' && $fileTypes && !empty($fileTypes)) {
            // Get the file category name
            $fileCategory = '';
            
            // Use provided categories if available
            if (isset($fileCategories) && is_array($fileCategories) && !empty($fileCategories)) {
                $fileCategory = implode('-', $fileCategories);
                $this->logger->debug("DIAGNOSTIC: Using provided categories for folder name in createPar2Files", [
                    'file_categories' => json_encode($fileCategories),
                    'category_name' => $fileCategory
                ]);
            } else {
                // Fall back to determining category from file types
                $fileCategory = $this->formatHelper->getFileCategoryName($fileTypes);
                $this->logger->debug("DIAGNOSTIC: Determined category from file types in createPar2Files", [
                    'file_types' => json_encode($fileTypes),
                    'category_name' => $fileCategory
                ]);
            }
            
            $parityDir = dirname($path) . '/' . $parityDirBase . '-' . $fileCategory;
            
            $this->logger->debug("Using category-specific parity directory for individual file", [
                'path' => $path,
                'file_types' => json_encode($fileTypes),
                'category' => $fileCategory,
                'parity_dir' => $parityDir
            ]);
        } else if ($mode === 'directory' && $fileTypes && !empty($fileTypes)) {
            // For directories with specific file types, use category-specific parity directory
            // Get the file category name
            $fileCategory = '';
            
            // Use provided categories if available
            if (isset($fileCategories) && is_array($fileCategories) && !empty($fileCategories)) {
                $fileCategory = implode('-', $fileCategories);
                $this->logger->debug("DIAGNOSTIC: Using provided categories for folder name in createPar2Files", [
                    'file_categories' => json_encode($fileCategories),
                    'category_name' => $fileCategory
                ]);
            } else {
                // Fall back to determining category from file types
                $fileCategory = $this->formatHelper->getFileCategoryName($fileTypes);
                $this->logger->debug("DIAGNOSTIC: Determined category from file types in createPar2Files", [
                    'file_types' => json_encode($fileTypes),
                    'category_name' => $fileCategory
                ]);
            }
            
            $parityDir = $path . '/' . $parityDirBase . '-' . $fileCategory;
            
            $this->logger->debug("Using category-specific parity directory for directory with file types", [
                'path' => $path,
                'file_types' => json_encode($fileTypes),
                'category' => $fileCategory,
                'parity_dir' => $parityDir
            ]);
        } else {
            // For directories without file types or files without file types, use default parity directory
            $parityDir = $mode === 'directory' ? $path . '/' . $parityDirBase : dirname($path) . '/' . $parityDirBase;
            
            $this->logger->debug("Using default parity directory", [
                'path' => $path,
                'mode' => $mode,
                'parity_dir' => $parityDir
            ]);
        }
        
        // Create parity directory if it doesn't exist
        if (!file_exists($parityDir)) {
            if (!mkdir($parityDir, 0755, true)) {
                throw new ApiException("Failed to create parity directory: $parityDir");
            }
        }
        
        // Determine par2 file name
        $par2BaseName = $mode === 'directory' ? basename($path) : basename($path);
        $par2Path = $parityDir . '/' . $par2BaseName . '.par2';
        
        // Build par2 command
        $baseCommand = "par2 create -q";
        
        // Add redundancy parameter
        $baseCommand .= " -r$redundancy";
        
        // Add resource limit parameters
        // Add -t parameter for CPU threads if set
        $maxCpuThreads = $this->config->get('resource_limits.max_cpu_usage');
        if ($maxCpuThreads) {
            $baseCommand .= " -t$maxCpuThreads";
        }
        
        // Add -m parameter for memory usage if set
        $maxMemory = $this->config->get('resource_limits.max_memory_usage');
        if ($maxMemory) {
            $baseCommand .= " -m$maxMemory";
        }
        
        // Add -T parameter for parallel file hashing if set
        $parallelFileHashing = $this->config->get('resource_limits.parallel_file_hashing');
        if ($parallelFileHashing) {
            $baseCommand .= " -T$parallelFileHashing";
        }
        
        // Add advanced settings if provided
        if ($advancedSettings && is_array($advancedSettings)) {
            // Add block count if provided
            if (isset($advancedSettings['block_count']) && $advancedSettings['block_count']) {
                $baseCommand .= " -c" . intval($advancedSettings['block_count']);
            }
            
            // Add block size if provided
            if (isset($advancedSettings['block_size']) && $advancedSettings['block_size']) {
                $baseCommand .= " -s" . intval($advancedSettings['block_size']);
            }
            
            // Add recovery file count if provided
            if (isset($advancedSettings['recovery_file_count']) && $advancedSettings['recovery_file_count']) {
                $baseCommand .= " -n" . intval($advancedSettings['recovery_file_count']);
            }
            
            // Add recovery file size if provided
            if (isset($advancedSettings['recovery_file_size']) && $advancedSettings['recovery_file_size']) {
                $baseCommand .= " -s" . intval($advancedSettings['recovery_file_size']) . "k";
            }
            
            // Add first recovery file number if provided
            if (isset($advancedSettings['first_recovery_number']) && $advancedSettings['first_recovery_number']) {
                $baseCommand .= " -f" . intval($advancedSettings['first_recovery_number']);
            }
            
            // Add uniform file size if provided
            if (isset($advancedSettings['uniform_file_size']) && $advancedSettings['uniform_file_size']) {
                $baseCommand .= " -u";
            }
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
            $baseCommand = "ionice -c $ioniceClass -n $ioniceLevel " . $baseCommand;
        }
        
        // Add output file parameter
        $baseCommand .= " \"$par2Path\"";
        
        // Execute par2 command based on mode
        if ($mode === 'directory') {
            // For directories, we need to handle file type filtering
            if ($fileTypes && !empty($fileTypes)) {
                // Build find command to get files of specified types
                $findCommand = "find \"$path\" -type f";
                
                // Add file type filters
                $typeFilters = [];
                foreach ($fileTypes as $type) {
                    $typeFilters[] = "-name \"*.$type\"";
                }
                
                $findCommand .= " \\( " . implode(" -o ", $typeFilters) . " \\)";
                
                // Execute find command to get file count
                $this->logger->debug("Executing find command to get file count", [
                    'command' => $findCommand
                ]);
                
                exec($findCommand . " | wc -l", $fileCountOutput);
                $fileCount = intval(trim($fileCountOutput[0]));
                
                $this->logger->debug("Found files to protect", [
                    'file_count' => $fileCount,
                    'file_types' => json_encode($fileTypes)
                ]);
                
                if ($fileCount === 0) {
                    $this->logger->warning("No files found matching the specified file types", [
                        'path' => $path,
                        'file_types' => json_encode($fileTypes)
                    ]);
                    
                    return [
                        'success' => false,
                        'skipped' => true,
                        'error' => "No files found matching the specified file types",
                        'par2_path' => null
                    ];
                }
                
                // Execute multiple par2 commands if needed
                return $this->executeMultiplePar2Commands($path, $baseCommand, $par2Path, $findCommand, $fileCount);
            } else {
                // For directories without file type filtering, protect all files
                $command = $baseCommand . " \"$path\"";
                
                $this->logger->debug("Executing par2 command for directory", [
                    'command' => $command
                ]);
                
                $result = $this->executePar2Command($command);
                
                if ($result['success']) {
                    return [
                        'success' => true,
                        'par2_path' => $par2Path
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => $result['error'],
                        'par2_path' => null
                    ];
                }
            }
        } else {
            // For individual files, protect the file directly
            $command = $baseCommand . " \"$path\"";
            
            $this->logger->debug("Executing par2 command for file", [
                'command' => $command
            ]);
            
            $result = $this->executePar2Command($command);
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'par2_path' => $par2Path
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'par2_path' => null
                ];
            }
        }
    }
    
    /**
     * Execute multiple PAR2 commands for large directories
     *
     * @param string $path Path to protect
     * @param string $baseCommand Base PAR2 command
     * @param string $par2Path Path to PAR2 file
     * @param string $findCommand Find command to get files
     * @param int $fileCount Number of files to protect
     * @return array
     */
    public function executeMultiplePar2Commands($path, $baseCommand, $par2Path, $findCommand, $fileCount) {
        // Determine batch size based on file count
        $batchSize = 1000; // Default batch size
        
        // If file count is small, protect all files at once
        if ($fileCount <= $batchSize) {
            // Execute find command to get files
            exec($findCommand, $files);
            
            // Build command with file list
            $command = $baseCommand;
            foreach ($files as $file) {
                $command .= " \"$file\"";
            }
            
            $this->logger->debug("Executing par2 command for directory with file type filtering", [
                'command' => $command,
                'file_count' => count($files)
            ]);
            
            $result = $this->executePar2Command($command, $files);
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'par2_path' => $par2Path
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'par2_path' => null
                ];
            }
        } else {
            // For large directories, split into batches
            $this->logger->debug("Splitting large directory into batches", [
                'file_count' => $fileCount,
                'batch_size' => $batchSize
            ]);
            
            // Create a temporary directory for batch files
            $tempDir = sys_get_temp_dir() . '/par2protect_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                throw new ApiException("Failed to create temporary directory: $tempDir");
            }
            
            // Execute find command to get all files
            exec($findCommand, $allFiles);
            
            // Split files into batches
            $batches = array_chunk($allFiles, $batchSize);
            
            $this->logger->debug("Split files into batches", [
                'batch_count' => count($batches)
            ]);
            
            // Process each batch
            $batchResults = [];
            $batchNumber = 1;
            
            foreach ($batches as $batch) {
                $batchPar2Path = dirname($par2Path) . '/' . basename($par2Path, '.par2') . "_batch$batchNumber.par2";
                
                // Build command with file list
                $command = str_replace($par2Path, $batchPar2Path, $baseCommand);
                foreach ($batch as $file) {
                    $command .= " \"$file\"";
                }
                
                $this->logger->debug("Executing par2 command for batch", [
                    'batch_number' => $batchNumber,
                    'file_count' => count($batch),
                    'par2_path' => $batchPar2Path
                ]);
                
                $result = $this->executePar2Command($command, $batch);
                
                $batchResults[] = [
                    'batch_number' => $batchNumber,
                    'success' => $result['success'],
                    'error' => $result['success'] ? null : $result['error'],
                    'par2_path' => $result['success'] ? $batchPar2Path : null
                ];
                
                $batchNumber++;
            }
            
            // Clean up temporary directory
            rmdir($tempDir);
            
            // Check if all batches succeeded
            $allSucceeded = true;
            $errors = [];
            
            foreach ($batchResults as $result) {
                if (!$result['success']) {
                    $allSucceeded = false;
                    $errors[] = "Batch {$result['batch_number']}: {$result['error']}";
                }
            }
            
            if ($allSucceeded) {
                return [
                    'success' => true,
                    'par2_path' => dirname($par2Path) // Return the directory containing all batch files
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Failed to protect some batches: " . implode("; ", $errors),
                    'par2_path' => null
                ];
            }
        }
    }
    
    /**
     * Execute a PAR2 command
     *
     * @param string $command PAR2 command to execute
     * @param array $files List of files being protected
     * @return array
     */
    public function executePar2Command($command, $files = []) {
        try {
            // Execute command
            exec($command . ' 2>&1', $output, $returnCode);
            
            // Check return code
            if ($returnCode !== 0) {
                $errorOutput = implode("\n", $output);
                
                $this->logger->error("Par2 command failed", [
                    'command' => $command,
                    'return_code' => $returnCode,
                    'output' => $errorOutput
                ]);
                
                return [
                    'success' => false,
                    'error' => "Par2 command failed: $errorOutput"
                ];
            }
            
            $this->logger->debug("Par2 command executed successfully", [
                'command' => $command,
                'file_count' => count($files)
            ]);
            
            return [
                'success' => true
            ];
        } catch (\Exception $e) {
            $this->logger->error("Exception executing par2 command", [
                'command' => $command,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => "Exception executing par2 command: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Protect individual files in a directory
     *
     * @param string $dirPath Directory path
     * @param int $redundancy Redundancy percentage
     * @param array $fileTypes File types to protect
     * @param array $fileCategories File categories selected by the user
     * @param array $advancedSettings Advanced settings for par2 command
     * @return array
     */
    public function protectIndividualFiles($dirPath, $redundancy, $fileTypes, $fileCategories = null, $advancedSettings = null) {
        $this->logger->debug("Starting individual files protection", [
            'dir_path' => $dirPath,
            'file_types' => json_encode($fileTypes),
            'file_categories' => $fileCategories ? json_encode($fileCategories) : null
        ]);
        
        try {
            // Build find command to get files of specified types
            $findCommand = "find \"$dirPath\" -type f";
            
            // Add file type filters
            $typeFilters = [];
            foreach ($fileTypes as $type) {
                $typeFilters[] = "-name \"*.$type\"";
            }
            
            $findCommand .= " \\( " . implode(" -o ", $typeFilters) . " \\)";
            
            // Execute find command to get files
            $this->logger->debug("Executing find command", [
                'command' => $findCommand
            ]);
            
            exec($findCommand, $files);
            
            $this->logger->debug("Found files to protect", [
                'file_count' => count($files),
                'file_types' => json_encode($fileTypes)
            ]);
            
            if (count($files) === 0) {
                $this->logger->warning("No files found matching the specified file types", [
                    'dir_path' => $dirPath,
                    'file_types' => json_encode($fileTypes)
                ]);
                
                return [
                    'success' => false,
                    'skipped' => true,
                    'error' => "No files found matching the specified file types",
                    'protected_files' => []
                ];
            }
            
            // Get file category name
            $fileCategory = '';
            
            // Use provided categories if available
            if (isset($fileCategories) && is_array($fileCategories) && !empty($fileCategories)) {
                $fileCategory = implode('-', $fileCategories);
                $this->logger->debug("DIAGNOSTIC: Using provided categories for folder name in protectIndividualFiles", [
                    'file_categories' => json_encode($fileCategories),
                    'category_name' => $fileCategory
                ]);
            } else {
                // Fall back to determining category from file types
                $fileCategory = $this->formatHelper->getFileCategoryName($fileTypes);
                $this->logger->debug("DIAGNOSTIC: Determined category from file types in protectIndividualFiles", [
                    'file_types' => json_encode($fileTypes),
                    'category_name' => $fileCategory
                ]);
            }
            
            // Create parity directory
            $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
            $parityDir = $dirPath . '/' . $parityDirBase . '-' . $fileCategory;
            
            if (!file_exists($parityDir)) {
                if (!mkdir($parityDir, 0755, true)) {
                    throw new ApiException("Failed to create parity directory: $parityDir");
                }
            }
            
            $this->logger->debug("Using category-specific parity directory for individual files", [
                'dir_path' => $dirPath,
                'file_types' => json_encode($fileTypes),
                'category' => $fileCategory,
                'parity_dir' => $parityDir
            ]);
            
            // Protect each file
            $protectedFiles = [];
            $failedFiles = [];
            
            foreach ($files as $file) {
                $result = $this->createPar2Files($file, $redundancy, 'file', $fileTypes, $fileCategories, $parityDir, $advancedSettings);
                
                if ($result['success']) {
                    $protectedFiles[] = $file;
                } else {
                    $failedFiles[] = [
                        'file' => $file,
                        'error' => $result['error']
                    ];
                }
            }
            
            $this->logger->debug("Individual files protection completed", [
                'protected_count' => count($protectedFiles),
                'failed_count' => count($failedFiles)
            ]);
            
            if (count($failedFiles) > 0) {
                $this->logger->warning("Some files failed to protect", [
                    'failed_files' => $failedFiles
                ]);
            }
            
            return [
                'success' => count($protectedFiles) > 0,
                'protected_files' => $protectedFiles,
                'failed_files' => $failedFiles,
                'par2_path' => $parityDir
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to protect individual files", [
                'dir_path' => $dirPath,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => "Failed to protect individual files: " . $e->getMessage(),
                'protected_files' => []
            ];
        }
    }
}