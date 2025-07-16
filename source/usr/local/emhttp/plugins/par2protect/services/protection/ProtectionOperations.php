<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\EventSystem;
use Par2Protect\Core\Exceptions\Par2ExecutionException;
use Par2Protect\Services\Protection\Helpers\FormatHelper;
use Par2Protect\Core\Commands\Par2CreateCommandBuilder;
use Par2Protect\Core\Traits\ReadsFileSystemMetadata; // Added trait

/**
 * Handles protection operations using par2 commands
 */
class ProtectionOperations {
    use ReadsFileSystemMetadata; // Use the trait

    private $logger;
    private $config;
    private $formatHelper;
    private $eventSystem;
    private $createBuilder;

    /**
     * ProtectionOperations constructor
     */
    public function __construct(
        Logger $logger,
        Config $config,
        FormatHelper $formatHelper,
        EventSystem $eventSystem,
        Par2CreateCommandBuilder $createBuilder
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->formatHelper = $formatHelper;
        $this->eventSystem = $eventSystem;
        $this->createBuilder = $createBuilder;
    }

    /**
     * Create PAR2 files for a given path (file or directory).
     * Handles different modes and file type filtering.
     */
    public function createPar2Files(
        string $path,
        int $redundancy,
        string $mode,
        ?array $fileTypes = null,
        ?string $customParityDir = null,
        ?array $protectedFiles = null, // Not used here, handled by protectIndividualFiles
        ?array $advancedSettings = null
    ): string {
        $this->logger->debug("Starting createPar2Files", compact('path', 'mode', 'redundancy', 'fileTypes', 'customParityDir', 'advancedSettings'));

        $basePath = ($mode === 'directory') ? $path : dirname($path);
        $par2BaseName = ($mode === 'directory') ? basename($path) : basename($path, '.' . pathinfo($path, PATHINFO_EXTENSION));
        if (empty($par2BaseName)) $par2BaseName = 'protection';

        $parityDir = $this->determineParityDirectory($path, $mode, $customParityDir, $fileTypes);
        $par2Path = rtrim($parityDir, '/') . '/' . $par2BaseName . '.par2';

        $this->ensureDirectoryExists($parityDir);

        $this->createBuilder
            ->setBasePath($basePath)
            ->setParityPath($par2Path)
            ->setRedundancy($redundancy)
            ->setQuiet(true);

        if ($advancedSettings) { $this->applyAdvancedSettings($this->createBuilder, $advancedSettings); }

        if ($mode === 'file') {
            $this->createBuilder->addSourceFile($path);
            return $this->executePar2Command($this->createBuilder->buildCommandString(), [$path]);
        } elseif ($mode === 'directory') {
            $sourceFiles = $this->findSourceFiles($path, $fileTypes, $parityDir);
            if (empty($sourceFiles)) { throw new \Exception("No files found to protect in directory matching criteria: $path"); }
            return $this->executePar2CommandWithFileList($this->createBuilder, $sourceFiles, $par2Path);
        } else {
            throw new \InvalidArgumentException("Invalid protection mode specified: $mode");
        }
    }

     /** Protect individual files based on categories/types within a directory. */
     public function protectIndividualFiles(string $path, int $redundancy, ?array $fileTypes = null, ?array $fileCategories = null, ?array $advancedSettings = null): array
     {
         $this->logger->debug("Starting protectIndividualFiles", compact('path', 'redundancy', 'fileTypes', 'fileCategories', 'advancedSettings'));
         $allProtectedFiles = []; $errors = [];

        // Define base categories (copied from features/settings/settings.php)
        $baseCategories = [
            'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'svg'],
            'videos' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg'],
            'audio' => ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma'],
            'archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'iso'],
            'code' => ['php', 'js', 'css', 'html', 'py', 'java', 'c', 'cpp', 'cs', 'rb', 'go', 'swift', 'json', 'xml', 'yml', 'yaml', 'sh', 'bat']
        ];

        $resolvedExtensions = [];

        // Resolve extensions from categories
        if (!empty($fileCategories) && is_array($fileCategories)) {
            foreach ($fileCategories as $category) {
                // Ensure category name is a string before using it as an array key or in config lookup
                if (!is_string($category)) continue;
                
                if (isset($baseCategories[$category])) {
                    // Add base extensions for the category
                    $resolvedExtensions = array_merge($resolvedExtensions, $baseCategories[$category]);
                    // Add custom extensions for the category from config
                    $customExt = $this->config->get('file_types.custom_extensions.' . $category, []);
                    if (is_array($customExt)) {
                         $resolvedExtensions = array_merge($resolvedExtensions, $customExt);
                    }
                }
            }
        }

        // Add extensions directly specified in fileTypes
        if (!empty($fileTypes) && is_array($fileTypes)) {
            // Ensure file types are strings before merging
            $stringFileTypes = array_filter($fileTypes, 'is_string');
            $resolvedExtensions = array_merge($resolvedExtensions, $stringFileTypes);
        }

        // Clean up the list: remove duplicates, filter empty values, ensure lowercase
        $targetExtensions = array_map('strtolower', array_unique(array_filter($resolvedExtensions)));
         if (empty($targetExtensions)) { return ['success' => false, 'protected_files' => [], 'error' => 'No file types selected for protection.']; }

         $fileCategoryName = $this->formatHelper->getFileCategoryName($fileCategories ?: $fileTypes);
         $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
         $parityDir = rtrim($path, '/') . '/' . $parityDirBase . ($fileCategoryName ? '-' . $fileCategoryName : '');
         $this->ensureDirectoryExists($parityDir);

         $filesToProtect = $this->findSourceFiles($path, $targetExtensions, $parityDir);
         if (empty($filesToProtect)) { return ['success' => true, 'protected_files' => [], 'error' => null]; }

         foreach ($filesToProtect as $filePath) {
             try {
                 $par2BaseName = basename($filePath);
                 $par2Path = rtrim($parityDir, '/') . '/' . $par2BaseName . '.par2';
                 $this->createBuilder->reset();
                 $this->createBuilder
                     ->setBasePath(dirname($filePath))
                     ->setParityPath($par2Path)
                     ->setRedundancy($redundancy)
                     ->setQuiet(true)
                     ->addSourceFile($filePath);
                 if ($advancedSettings) { $this->applyAdvancedSettings($this->createBuilder, $advancedSettings); }
                 $this->executePar2Command($this->createBuilder->buildCommandString(), [$filePath]);
                 $allProtectedFiles[] = $filePath;
             } catch (\Exception $e) {
                 $this->logger->error("Failed to protect individual file", ['file' => $filePath, 'error' => $e->getMessage()]);
                 $errors[] = basename($filePath) . ": " . $e->getMessage();
             }
         }
         return ['success' => count($errors) === 0, 'protected_files' => $allProtectedFiles, 'error' => count($errors) > 0 ? implode("\n", $errors) : null];
     }

    /** Apply advanced settings to the command builder */
    private function applyAdvancedSettings(Par2CreateCommandBuilder $builder, array $settings) {
        if (isset($settings['block_count']) && !empty($settings['block_count'])) { $builder->setBlockCount(intval($settings['block_count'])); }
        if (isset($settings['block_size']) && !empty($settings['block_size'])) { $builder->setBlockSize(intval($settings['block_size'])); }
        if (isset($settings['target_size']) && !empty($settings['target_size'])) { $builder->setMemoryLimit(intval($settings['target_size'])); } // Assuming target_size is memory limit
        if (isset($settings['recovery_files']) && !empty($settings['recovery_files'])) { $builder->setRecoveryFileCount(intval($settings['recovery_files'])); }
    }

    /** Find source files in a directory, optionally filtering by extensions */
    private function findSourceFiles(string $dirPath, ?array $extensions, string $excludeDir): array {
        $dirPath = rtrim($dirPath, '/') . '/';
        $excludeDir = rtrim($excludeDir, '/');
        $files = [];
        $command = "/usr/bin/find " . escapeshellarg($dirPath) . " -type d -path " . escapeshellarg($excludeDir) . " -prune -o -type f";
        if (!empty($extensions)) {
            $nameFilters = [];
            foreach ($extensions as $ext) { $safeExt = escapeshellarg('.' . ltrim($ext, '.')); $nameFilters[] = "-iname '*$safeExt'"; }
            $command .= " \( " . implode(' -o ', $nameFilters) . " \)";
        }
        $command .= " -print0";
        $this->logger->debug("Executing find command to get file list", ['command' => $command]);
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) { $this->logger->error("Find command failed", ['command' => $command, 'return_code' => $returnCode]); throw new \RuntimeException("Failed to list files in directory: $dirPath"); }
        $outputStr = implode("", $output);
        if (!empty($outputStr)) { $files = explode("\0", rtrim($outputStr, "\0")); }
        $this->logger->debug("Found files to protect", ['file_count' => count($files), 'file_types' => $extensions]);
        return $files;
    }

    /**
     * Calculate optimal xargs parameters based on system limits and file list
     */
    private function calculateXargsParameters($tempFile, $id)
    {
        $this->logger->debug("Calculating xargs parameters", ['id' => $id]);
        
        try {
            // Detect system ARG_MAX limit
            $argMaxOutput = shell_exec('getconf ARG_MAX 2>/dev/null');
            $argMax = $argMaxOutput ? (int)trim($argMaxOutput) : 131072; // Default fallback
            
            // Validate ARG_MAX is reasonable (minimum 4KB, maximum 32MB)
            if ($argMax < 4096) {
                $this->logger->warning("ARG_MAX too low, using fallback", ['id' => $id, 'detected' => $argMax]);
                $argMax = 131072;
            } elseif ($argMax > 33554432) { // 32MB
                $this->logger->warning("ARG_MAX very high, capping", ['id' => $id, 'detected' => $argMax]);
                $argMax = 33554432;
            }
            
            $this->logger->debug("System ARG_MAX detected", ['id' => $id, 'arg_max' => $argMax]);
            
            // Safety margin (use 80% of available space)
            $safetyMargin = 0.8;
            $safeArgMax = floor($argMax * $safetyMargin);
            
            // Analyze actual file list
            if (!file_exists($tempFile)) {
                throw new \RuntimeException("Temporary file list not found: $tempFile");
            }
            
            $fileListContent = file_get_contents($tempFile);
            if ($fileListContent === false) {
                throw new \RuntimeException("Failed to read temporary file list: $tempFile");
            }
            
            if (empty($fileListContent)) {
                $this->logger->warning("Empty file list detected", ['id' => $id]);
                return [
                    'max_args' => 1000,
                    'max_size' => $safeArgMax,
                    'chunk_needed' => false
                ];
            }
            
            // Split by null terminator and filter empty entries
            $fileList = array_filter(explode("\0", trim($fileListContent, "\0")));
            $fileCount = count($fileList);
            
            if ($fileCount === 0) {
                $this->logger->warning("No valid files in file list", ['id' => $id]);
                return [
                    'max_args' => 1000,
                    'max_size' => $safeArgMax,
                    'chunk_needed' => false
                ];
            }
            
            // Calculate average path length and find maximum path length
            $totalLength = array_sum(array_map('strlen', $fileList));
            $avgPathLength = $totalLength / $fileCount;
            $maxPathLength = max(array_map('strlen', $fileList));
            
            // Check for extremely long paths that might cause issues
            if ($maxPathLength > 4096) {
                $this->logger->warning("Very long file path detected", [
                    'id' => $id,
                    'max_path_length' => $maxPathLength,
                    'avg_path_length' => round($avgPathLength, 2)
                ]);
            }
            
            // Calculate optimal parameters
            // Add 20 bytes buffer per argument for spacing, shell overhead, and safety
            $bufferPerArg = 20;
            $maxArgsBasedOnSize = floor($safeArgMax / ($avgPathLength + $bufferPerArg));
            
            // PAR2 has a limit of 32,768 files per operation
            $par2FileLimit = 32768;
            
            // Additional conservative limits for stability
            $conservativeLimit = 10000; // Conservative limit for very large file sets
            
            // Choose the most restrictive limit
            $maxArgs = min($par2FileLimit, $maxArgsBasedOnSize, $fileCount, $conservativeLimit);
            
            // Ensure we have at least 1 argument but not more than what makes sense
            $maxArgs = max(1, min($maxArgs, $fileCount));
            
            $chunkNeeded = $fileCount > $maxArgs;
            
            $this->logger->info("Calculated xargs parameters", [
                'id' => $id,
                'file_count' => $fileCount,
                'avg_path_length' => round($avgPathLength, 2),
                'max_path_length' => $maxPathLength,
                'max_args' => $maxArgs,
                'max_size' => $safeArgMax,
                'chunk_needed' => $chunkNeeded,
                'estimated_chunks' => $chunkNeeded ? ceil($fileCount / $maxArgs) : 1
            ]);
            
            return [
                'max_args' => $maxArgs,
                'max_size' => $safeArgMax,
                'chunk_needed' => $chunkNeeded
            ];
            
        } catch (\Exception $e) {
            $this->logger->error("Error calculating xargs parameters", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            // Return conservative fallback parameters
            return [
                'max_args' => 1000,
                'max_size' => 131072,
                'chunk_needed' => false
            ];
        }
    }

    /** Execute a single PAR2 create command by piping a file list to it. */
    private function executePar2CommandWithFileList(Par2CreateCommandBuilder $builder, array $sourceFiles, string $par2Path): string {
        $tempDir = '/tmp/par2protect/file_lists';
        $this->ensureDirectoryExists($tempDir);
        
        // Clean up old file lists to prevent disk space issues
        $cleanupCommand = "/usr/bin/find " . escapeshellarg($tempDir) . " -name 'par2_files_*.txt' -type f -mtime +1 -delete";
        exec($cleanupCommand, $cleanupOutput, $cleanupReturnCode);
        if ($cleanupReturnCode !== 0) {
            $this->logger->warning("Failed to clean up old file lists", ['command' => $cleanupCommand]);
        }
        
        // Create a unique temporary file to hold the list of source files
        $timestamp = date('Ymd_His');
        $random = substr(md5(uniqid(rand(), true)), 0, 10);
        $fileListPath = $tempDir . '/par2_files_' . $timestamp . '_' . $random . '.txt';
        
        // Write the null-terminated list of files
        $fileListContent = implode("\0", $sourceFiles);
        if (file_put_contents($fileListPath, $fileListContent) === false) {
            throw new \RuntimeException("Failed to write temporary file list: $fileListPath");
        }
        $this->logger->debug("Created temporary file list for par2 create", ['file' => $fileListPath, 'file_count' => count($sourceFiles)]);
        
        // Configure the builder to read the file list from stdin
        $builder->setBlockCount(count($sourceFiles));
        
        // Build the base command string without the source file arguments
        $baseCommandWithOptions = $builder->buildCommandString(false);
        
        // Generate a unique ID for logging purposes
        $uniqueId = 'par2_' . substr(md5($fileListPath), 0, 8);
        
        try {
            // Calculate dynamic xargs parameters
            $xargsParams = $this->calculateXargsParameters($fileListPath, $uniqueId);
            
            if ($xargsParams['chunk_needed']) {
                $this->logger->info("Large file list detected, will use chunked processing", [
                    'id' => $uniqueId,
                    'max_args' => $xargsParams['max_args'],
                    'file_count' => count($sourceFiles)
                ]);
            }
            
            // Validate xargs parameters
            if ($xargsParams['max_args'] < 1 || $xargsParams['max_size'] < 1024) {
                $this->logger->warning("Invalid xargs parameters, using fallback", [
                    'id' => $uniqueId,
                    'max_args' => $xargsParams['max_args'],
                    'max_size' => $xargsParams['max_size']
                ]);
                $xargsParams['max_args'] = 1000;
                $xargsParams['max_size'] = 131072;
            }
            
            // Construct the final command using xargs to convert piped file list into command line arguments
            $command = sprintf(
                "/bin/cat %s | /usr/bin/xargs -n %d -s %d -0 %s --",
                escapeshellarg($fileListPath),
                $xargsParams['max_args'],
                $xargsParams['max_size'],
                $baseCommandWithOptions
            );
            
            $this->logger->debug("Executing par2 command with xargs", [
                'id' => $uniqueId,
                'command' => $command,
                'xargs_params' => $xargsParams
            ]);
            
            // Execute the command
            $this->executePar2Command($command, $sourceFiles);
            return $par2Path;
            
        } catch (\Exception $e) {
            // In case of an error, log it and keep the temp file for debugging
            $this->logger->error("Par2 command with xargs failed, keeping temp file for debugging.", [
                'id' => $uniqueId,
                'file' => $fileListPath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            // Optionally, uncomment the line below to clean up the temp file immediately after execution
            // @unlink($fileListPath);
        }
    }

    /** Execute a single PAR2 command */
    private function executePar2Command(string $command, array $involvedFiles = []): string {
         $this->logger->debug("Executing final PAR2 command", ['command' => $command]);
         $output = []; $returnCode = 0;
         exec($command . ' 2>&1', $output, $returnCode);
         $outputStr = implode("\n", $output);

         if (strpos($outputStr, 'Repair is not required') !== false || strpos($outputStr, 'All files are correct') !== false) {
             $this->logger->info("PAR2 verification successful", ['command' => $command]);
             return $this->createBuilder->getParityPath() ?? '';
         }
         if (strpos($outputStr, 'par2 files already exist') !== false && strpos($command, 'create') !== false) {
              $this->logger->info("PAR2 files already exist, skipping creation.", ['command' => $command]);
              // Throw specific exception instead of returning normally
              throw new \Par2Protect\Core\Exceptions\Par2FilesExistException("PAR2 files already exist for command: " . $command);
         }
         if ($returnCode !== 0) {
             $this->logger->error("Par2 command failed", ['command' => $command, 'return_code' => $returnCode, 'output' => $outputStr]);
             $this->eventSystem->addEvent('par2.error', ['command' => $command, 'return_code' => $returnCode, 'output' => $outputStr, 'files' => $involvedFiles]);
             throw new Par2ExecutionException("Par2 command failed", $command, $returnCode, $outputStr);
         }
         $this->logger->debug("Par2 command executed successfully", ['command' => $command, 'file_count' => count($involvedFiles)]);
         $this->eventSystem->addEvent('par2.success', ['command' => $command, 'files' => $involvedFiles]);
         return $this->createBuilder->getParityPath() ?? '';
    }

    /**
     * Remove PAR2 files associated with a protected item.
     */
    public function removePar2Files(string $par2Path, string $mode): bool
    {
        $this->logger->debug("Attempting to remove PAR2 files", compact('par2Path', 'mode'));
        $success = true;
        try {
            $parityDirToRemove = null;

            // Determine the directory to remove based on mode and path
            if (strpos($mode, 'Individual Files') === 0 && is_dir($par2Path)) {
                // Mode is "Individual Files - ...", par2Path is the specific .parity-category directory
                $parityDirToRemove = $par2Path;
                $this->logger->debug("Identified individual PAR2 files directory for removal", ['directory' => $parityDirToRemove]);
            } elseif ($mode === 'directory' && is_file($par2Path)) {
                 // Mode is 'directory', par2Path is a file inside the standard .parity directory
                 $parityDirToRemove = dirname($par2Path);
                 $this->logger->debug("Identified standard PAR2 directory for removal", ['directory' => $parityDirToRemove]);
            } elseif ($mode === 'file' && is_file($par2Path)) {
                 // Mode is 'file', par2Path is the specific .par2 file. We remove its directory.
                 $parityDirToRemove = dirname($par2Path);
                 $this->logger->debug("Identified PAR2 directory for single file removal", ['directory' => $parityDirToRemove]);
            }

            // If we identified a directory to remove, proceed with find -delete
            if ($parityDirToRemove) {
                // Safety check: Ensure we are targeting a directory that looks like a parity directory
                $parityDirBaseName = $this->config->get('protection.parity_dir', '.parity');
                if (basename($parityDirToRemove) === $parityDirBaseName || strpos(basename($parityDirToRemove), $parityDirBaseName . '-') === 0) {
                    if (is_dir($parityDirToRemove)) {
                        // Construct the find command: find 'directory' -depth -delete
                        $command = "/usr/bin/find " . escapeshellarg($parityDirToRemove) . " -depth -delete";
                        $this->logger->debug("Executing find -delete command", ['command' => $command]);
                        exec($command, $output, $returnCode);
                        $this->logger->debug("Find -delete command finished", ['command' => $command, 'return_code' => $returnCode, 'output' => implode("\n", $output)]);

                        // Check if the directory still exists after the command
                        clearstatcache(true, $parityDirToRemove); // Clear stat cache before checking
                        if (is_dir($parityDirToRemove)) {
                             // If find reported success (0) but dir still exists, log error
                             if ($returnCode === 0) {
                                 $this->logger->error("Find -delete reported success but directory still exists", ['directory' => $parityDirToRemove]);
                                 $success = false;
                             } else {
                                 $this->logger->error("Failed to remove PAR2 directory using find -delete", ['directory' => $parityDirToRemove, 'return_code' => $returnCode, 'output' => implode("\n", $output)]);
                                 $success = false;
                             }
                        } else {
                             // Directory is gone, consider it success
                             $this->logger->info("Successfully removed PAR2 directory using find -delete", ['directory' => $parityDirToRemove]);
                             $success = true;
                        }
                    } else {
                         $this->logger->warning("Parity directory to remove does not exist, considering removal successful.", ['directory' => $parityDirToRemove]);
                         $success = true; // Directory doesn't exist, so removal is effectively successful
                    }
                } else {
                     $this->logger->error("Safety check failed: Attempted find -delete on directory not matching expected parity structure", ['directory' => $parityDirToRemove, 'par2Path' => $par2Path, 'mode' => $mode]);
                     $success = false;
                }
            } else {
                // If no directory was identified, check if PAR2 files are already gone
                // This handles cases where files were removed externally
                if (!file_exists($par2Path) && !is_dir($par2Path)) {
                    // PAR2 path doesn't exist - treat as successful removal
                    $this->logger->info("PAR2 files already removed externally", ['par2Path' => $par2Path, 'mode' => $mode]);
                    $success = true;
                } else {
                    // Path exists but we couldn't identify proper removal strategy
                    $this->logger->warning("PAR2 path found but invalid mode for removal", compact('par2Path', 'mode'));
                    $success = false;
                }
            }
        } catch (\Exception $e) { $this->logger->error("Error removing PAR2 files", ['path' => $par2Path, 'error' => $e->getMessage()]); $success = false; }
        return $success;
    }

    /** Ensure a directory exists */
    private function ensureDirectoryExists(string $dirPath): void {
        if (!is_dir($dirPath)) {
            $this->logger->debug("Directory does not exist, attempting creation", ['path' => $dirPath]);
            if (!@mkdir($dirPath, 0755, true)) {
                $error = error_get_last(); $errorMsg = $error['message'] ?? 'Unknown error';
                $this->logger->error("Failed to create directory", ['path' => $dirPath, 'error' => $errorMsg]);
                throw new \RuntimeException("Failed to create directory: $dirPath - $errorMsg");
            }
            $this->logger->debug("Created directory", ['path' => $dirPath]);
        }
    }

    /** Determine the correct parity directory path */
    private function determineParityDirectory(string $path, string $mode, ?string $customParityDir, ?array $fileTypes): string {
        if ($customParityDir) { return $customParityDir; }
        $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
        $parentDir = ($mode === 'directory') ? $path : dirname($path);
        $subDir = '';
        if ($mode === 'directory' && $fileTypes) {
            $subDir = $this->formatHelper->getFileCategoryName($fileTypes);
            if (!empty($subDir)) { $subDir = '-' . $subDir; }
        }
        $parityDir = rtrim($parentDir, '/') . '/' . $parityDirBase . $subDir;
        $this->logger->debug("Determined parity directory path", ['path' => $path, 'mode' => $mode, 'parity_dir' => $parityDir]);
        return $parityDir;
    }

} // End of class ProtectionOperations