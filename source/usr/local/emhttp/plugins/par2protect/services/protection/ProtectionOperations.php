<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\EventSystem;
use Par2Protect\Core\Exceptions\Par2ExecutionException;
use Par2Protect\Services\Protection\Helpers\FormatHelper;
use Par2Protect\Core\Commands\Par2CreateCommandBuilder;
use Par2Protect\Core\Traits\ReadsFileSystemMetadata; // Added trait
use Par2Protect\Services\Protection\ProtectionRepository;

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
    private $protectionRepository;

    /**
     * ProtectionOperations constructor
     */
    public function __construct(
        Logger $logger,
        Config $config,
        FormatHelper $formatHelper,
        EventSystem $eventSystem,
        Par2CreateCommandBuilder $createBuilder,
        ProtectionRepository $protectionRepository
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->formatHelper = $formatHelper;
        $this->eventSystem = $eventSystem;
        $this->createBuilder = $createBuilder;
        $this->protectionRepository = $protectionRepository;
    }

    /**
     * Create PAR2 files for a given path (file or directory).
     * Handles different modes and file type filtering.
     * Returns operation_id for multi-chunk operations to enable verification lookup.
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

        // Generate operation ID for multi-chunk operations
        $operationId = 'par2_' . substr(md5($par2Path . '_' . time() . '_' . rand()), 0, 16);

        $this->createBuilder
            ->setBasePath($basePath)
            ->setParityPath($par2Path)
            ->setRedundancy($redundancy)
            ->setQuiet(true);

        if ($advancedSettings) { $this->applyAdvancedSettings($this->createBuilder, $advancedSettings); }

        if ($mode === 'file') {
            $this->createBuilder->addSourceFile($path);
            $result = $this->executePar2Command($this->createBuilder->buildCommandString(), [$path]);

            // For single file operations, we don't need chunk tracking, just return the path
            return $result;
        } elseif ($mode === 'directory') {
            $sourceFiles = $this->findSourceFiles($path, $fileTypes, $parityDir);
            if (empty($sourceFiles)) { throw new \Exception("No files found to protect in directory matching criteria: $path"); }

            // Execute with chunking orchestration
            return $this->executePar2CommandWithFileListAndChunking(
                $this->createBuilder,
                $sourceFiles,
                $par2Path,
                $operationId,
                $path
            );
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

    /** Execute PAR2 create command with file list, using multi-chunk processing when needed. */
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

        // Note: Do NOT set blockCount here - it will be set per-chunk if chunking is needed
        // Generate a unique ID for logging purposes
        $uniqueId = 'par2_' . substr(md5($fileListPath), 0, 8);

        try {
            // Calculate dynamic xargs parameters
            $xargsParams = $this->calculateXargsParameters($fileListPath, $uniqueId);

            if ($xargsParams['chunk_needed']) {
                $this->logger->info("Large file list detected, using multi-chunk processing", [
                    'id' => $uniqueId,
                    'max_args' => $xargsParams['max_args'],
                    'file_count' => count($sourceFiles),
                    'estimated_chunks' => ceil(count($sourceFiles) / $xargsParams['max_args'])
                ]);

                // Use buildArgv() for safe argument construction (without source files for chunked processing)
                $argv = $builder->buildArgv(false);

                // Escape the base arguments for shell execution
                $escapedBaseArgs = array_map('escapeshellarg', $argv);

                // Construct the final command using xargs for chunked processing
                $command = sprintf(
                    "/bin/cat %s | /usr/bin/xargs -n %d -s %d -0 %s",
                    escapeshellarg($fileListPath),
                    $xargsParams['max_args'],
                    $xargsParams['max_size'],
                    implode(' ', $escapedBaseArgs)
                );

                $this->logger->debug("Executing par2 command with multi-chunk xargs", [
                    'id' => $uniqueId,
                    'command' => $command,
                    'xargs_params' => $xargsParams,
                    'base_argv_count' => count($baseArgv)
                ]);

                // Execute the command
                $this->executePar2Command($command, $sourceFiles);
                return $par2Path;

            } else {
                // Single chunk processing - use traditional approach for compatibility
                $this->logger->debug("Using single-chunk processing", [
                    'id' => $uniqueId,
                    'file_count' => count($sourceFiles)
                ]);

                // Build the base command string without the source file arguments
                $baseCommandWithOptions = $builder->buildCommandString(false);

                // Construct the final command using xargs to convert piped file list into command line arguments
                $command = sprintf(
                    "/bin/cat %s | /usr/bin/xargs -n %d -s %d -0 %s --",
                    escapeshellarg($fileListPath),
                    $xargsParams['max_args'],
                    $xargsParams['max_size'],
                    $baseCommandWithOptions
                );

                $this->logger->debug("Executing par2 command with single-chunk xargs", [
                    'id' => $uniqueId,
                    'command' => $command,
                    'xargs_params' => $xargsParams
                ]);

                // Execute the command
                $this->executePar2Command($command, $sourceFiles);
                return $par2Path;
            }

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

    /**
     * Execute PAR2 command with chunking orchestration and database persistence.
     * Now accepts builder to properly set block count per chunk.
     */
    private function executePar2CommandWithChunking(
        Par2CreateCommandBuilder $builder,
        array $sourceFiles,
        string $par2Path,
        string $operationId,
        string $targetRoot
    ): string {
        $startTime = microtime(true);
        $totalFiles = count($sourceFiles);

        try {
            // Split files into chunks for processing with per-chunk block count
            $chunks = $this->splitFilesIntoChunks($sourceFiles, $builder, $par2Path, $operationId, $targetRoot);

            // Process each chunk
            $successfulChunks = 0;
            $failedChunks = [];

            foreach ($chunks as $chunkIndex => $chunkData) {
                $chunkStartTime = microtime(true);

                try {
                    $this->logger->info("Processing chunk", [
                        'operation_id' => $operationId,
                        'chunk_index' => $chunkIndex,
                        'chunk_file_count' => $chunkData['file_count'],
                        'total_chunks' => count($chunks)
                    ]);

                    // Execute the chunk command
                    $chunkCommand = $chunkData['command'];
                    $chunkFiles = $chunkData['files'];

                    $this->executePar2Command($chunkCommand, $chunkFiles);

                    $elapsedMs = round((microtime(true) - $chunkStartTime) * 1000);

                    // Record successful chunk in database with unique parity path
                    $this->protectionRepository->upsertChunk([
                        'operation_id' => $operationId,
                        'target_root' => $targetRoot,
                        'chunk_index' => $chunkIndex,
                        'file_count' => $chunkData['file_count'],
                        'filelist_hash' => $chunkData['filelist_hash'] ?? null,
                        'parity_path' => $chunkData['parity_path'],
                        'command' => $chunkCommand,
                        'status' => 'COMPLETED',
                        'return_code' => 0,
                        'elapsed_ms' => $elapsedMs
                    ]);

                    $successfulChunks++;
                    $this->logger->debug("Chunk completed successfully", [
                        'operation_id' => $operationId,
                        'chunk_index' => $chunkIndex,
                        'elapsed_ms' => $elapsedMs
                    ]);

                } catch (\Exception $e) {
                    $elapsedMs = round((microtime(true) - $chunkStartTime) * 1000);

                    // Record failed chunk in database with unique parity path
                    $this->protectionRepository->upsertChunk([
                        'operation_id' => $operationId,
                        'target_root' => $targetRoot,
                        'chunk_index' => $chunkIndex,
                        'file_count' => $chunkData['file_count'],
                        'filelist_hash' => $chunkData['filelist_hash'] ?? null,
                        'parity_path' => $chunkData['parity_path'],
                        'command' => $chunkData['command'],
                        'status' => 'FAILED',
                        'return_code' => $e->getCode() ?: 1,
                        'elapsed_ms' => $elapsedMs
                    ]);

                    $failedChunks[] = $chunkIndex;
                    $this->logger->error("Chunk failed", [
                        'operation_id' => $operationId,
                        'chunk_index' => $chunkIndex,
                        'error' => $e->getMessage(),
                        'elapsed_ms' => $elapsedMs
                    ]);
                }
            }

            $totalElapsedMs = round((microtime(true) - $startTime) * 1000);

            $this->logger->info("Chunking operation completed", [
                'operation_id' => $operationId,
                'total_files' => $totalFiles,
                'total_chunks' => count($chunks),
                'successful_chunks' => $successfulChunks,
                'failed_chunks' => count($failedChunks),
                'total_elapsed_ms' => $totalElapsedMs
            ]);

            if (!empty($failedChunks)) {
                throw new \RuntimeException(
                    "Operation completed with failures. Failed chunks: " . implode(', ', $failedChunks)
                );
            }

            // For chunked operations, return the parity directory so verification can find all chunks
            return dirname($par2Path);

        } catch (\Exception $e) {
            $totalElapsedMs = round((microtime(true) - $startTime) * 1000);

            $this->logger->error("Chunking operation failed", [
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'total_elapsed_ms' => $totalElapsedMs
            ]);

            throw $e;
        }
    }

    /**
     * Split files into chunks for processing with per-chunk command building.
     * This method now accepts a builder instead of a base command to properly
     * set block count per chunk.
     */
    private function splitFilesIntoChunks(
        array $sourceFiles,
        Par2CreateCommandBuilder $builder,
        string $par2Path,
        string $operationId,
        string $targetRoot
    ): array {
        $totalFiles = count($sourceFiles);
        
        // Calculate optimal chunk size based on par2 limits and system constraints
        $par2FileLimit = 32768;
        $conservativeLimit = 10000; // Conservative limit for stability
        $chunkSize = min($conservativeLimit, $par2FileLimit);
        
        $chunks = [];

        // Create temporary directory for chunk file lists
        $tempDir = '/tmp/par2protect/chunk_lists';
        $this->ensureDirectoryExists($tempDir);

        // Clean up old chunk lists
        $cleanupCommand = "/usr/bin/find " . escapeshellarg($tempDir) . " -name 'chunk_*.txt' -type f -mtime +1 -delete";
        exec($cleanupCommand, $cleanupOutput, $cleanupReturnCode);

        $this->logger->info("Splitting files into chunks", [
            'operation_id' => $operationId,
            'total_files' => $totalFiles,
            'chunk_size' => $chunkSize,
            'estimated_chunks' => ceil($totalFiles / $chunkSize)
        ]);

        for ($i = 0; $i < $totalFiles; $i += $chunkSize) {
            $chunkFiles = array_slice($sourceFiles, $i, $chunkSize);
            $chunkIndex = intval($i / $chunkSize);
            $chunkFileCount = count($chunkFiles);

            // Create chunk file list
            $timestamp = date('Ymd_His');
            $random = substr(md5(uniqid(rand(), true)), 0, 8);
            $chunkFileListPath = $tempDir . '/chunk_' . $timestamp . '_' . $random . '_index_' . $chunkIndex . '.txt';

            // Write the null-terminated list of files for this chunk
            $chunkFileListContent = implode("\0", $chunkFiles);
            if (file_put_contents($chunkFileListPath, $chunkFileListContent) === false) {
                throw new \RuntimeException("Failed to write chunk file list: $chunkFileListPath");
            }

            // Create unique par2 file name for this chunk
            // Example: par2_test_large.par2 -> par2_test_large_chunk_000.par2
            $par2PathInfo = pathinfo($par2Path);
            $chunkPar2Path = $par2PathInfo['dirname'] . '/' .
                             $par2PathInfo['filename'] . '_chunk_' .
                             str_pad($chunkIndex, 3, '0', STR_PAD_LEFT) . '.' .
                             $par2PathInfo['extension'];
            
            // CRITICAL FIX: Set block count based on THIS chunk's file count, not total
            $builder->setBlockCount($chunkFileCount);
            
            // Set the unique parity path for this chunk
            $builder->setParityPath($chunkPar2Path);
            
            // Build argv for this specific chunk with correct block count and unique path
            $argv = $builder->buildArgv(false);
            
            // Escape the arguments for shell execution
            $escapedArgs = array_map('escapeshellarg', $argv);
            
            // Calculate appropriate size limit for xargs based on average path length
            // Add safety buffer to account for command overhead
            $avgPathLength = strlen(implode('', $chunkFiles)) / $chunkFileCount;
            $safeMaxSize = min(1000000, intval($avgPathLength * $chunkFileCount * 1.5));
            
            // Generate chunk-specific command with per-chunk block count
            // CRITICAL: Use both -n and -s to ensure ONE invocation per chunk
            // Without -s, xargs uses conservative default (~128KB) and splits into multiple calls
            $chunkCommand = sprintf(
                "/bin/cat %s | /usr/bin/xargs -n %d -s %d -0 %s",
                escapeshellarg($chunkFileListPath),
                $chunkFileCount,  // Max arguments: all files in this chunk
                $safeMaxSize,      // Max size: calculated based on path lengths
                implode(' ', $escapedArgs)
            );

            // Calculate file list hash for verification
            $filelistHash = hash('sha256', $chunkFileListContent);

            $chunks[] = [
                'files' => $chunkFiles,
                'file_count' => $chunkFileCount,
                'command' => $chunkCommand,
                'filelist_path' => $chunkFileListPath,
                'filelist_hash' => $filelistHash,
                'parity_path' => $chunkPar2Path
            ];

            $this->logger->debug("Created chunk with correct block count and unique par2 path", [
                'operation_id' => $operationId,
                'chunk_index' => $chunkIndex,
                'file_count' => $chunkFileCount,
                'block_count' => $chunkFileCount,
                'parity_path' => $chunkPar2Path,
                'filelist_hash' => substr($filelistHash, 0, 16)
            ]);
        }

        return $chunks;
    }

    /**
     * Execute PAR2 create command with file list and chunking orchestration with database persistence.
     */
    private function executePar2CommandWithFileListAndChunking(
        Par2CreateCommandBuilder $builder,
        array $sourceFiles,
        string $par2Path,
        string $operationId,
        string $targetRoot
    ): string {
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
        $this->logger->debug("Created temporary file list for chunked par2 create", [
            'file' => $fileListPath,
            'file_count' => count($sourceFiles),
            'operation_id' => $operationId
        ]);

        // Note: Do NOT set blockCount here - it will be set per-chunk if chunking is needed
        // Generate a unique ID for logging purposes
        $uniqueId = 'par2_' . substr(md5($fileListPath), 0, 8);

        try {
            // Calculate dynamic xargs parameters
            $xargsParams = $this->calculateXargsParameters($fileListPath, $uniqueId);

            if ($xargsParams['chunk_needed']) {
                $this->logger->info("Large file list detected, using multi-chunk processing with database persistence", [
                    'id' => $uniqueId,
                    'max_args' => $xargsParams['max_args'],
                    'file_count' => count($sourceFiles),
                    'estimated_chunks' => ceil(count($sourceFiles) / $xargsParams['max_args']),
                    'operation_id' => $operationId
                ]);

                $this->logger->debug("Using chunked processing with per-chunk block count", [
                    'id' => $uniqueId,
                    'file_count' => count($sourceFiles),
                    'xargs_params' => $xargsParams,
                    'operation_id' => $operationId
                ]);

                // Execute with chunk orchestration - builder will set block count per chunk
                $result = $this->executePar2CommandWithChunking($builder, $sourceFiles, $par2Path, $operationId, $targetRoot);
                return $result;

            } else {
                // Single chunk processing - set block count for the full file set
                $fileCount = count($sourceFiles);
                $builder->setBlockCount($fileCount);
                
                $this->logger->debug("Using single-chunk processing", [
                    'id' => $uniqueId,
                    'file_count' => $fileCount,
                    'block_count' => $fileCount,
                    'operation_id' => $operationId
                ]);

                // Build the base command string without the source file arguments
                $baseCommandWithOptions = $builder->buildCommandString(false);

                // Construct the final command using xargs to convert piped file list into command line arguments
                $command = sprintf(
                    "/bin/cat %s | /usr/bin/xargs -n %d -s %d -0 %s --",
                    escapeshellarg($fileListPath),
                    $xargsParams['max_args'],
                    $xargsParams['max_size'],
                    $baseCommandWithOptions
                );

                $this->logger->debug("Executing par2 command with single-chunk xargs", [
                    'id' => $uniqueId,
                    'command' => $command,
                    'xargs_params' => $xargsParams,
                    'operation_id' => $operationId
                ]);

                // Execute the command (single chunk, no database persistence needed beyond normal operation)
                $this->executePar2Command($command, $sourceFiles);
                return $par2Path;
            }

        } catch (\Exception $e) {
            // In case of an error, log it and keep the temp file for debugging
            $this->logger->error("Par2 command with xargs failed, keeping temp file for debugging.", [
                'id' => $uniqueId,
                'file' => $fileListPath,
                'error' => $e->getMessage(),
                'operation_id' => $operationId
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
            } elseif ($mode === 'directory' && is_dir($par2Path)) {
                 // Mode is 'directory', par2Path is the parity directory itself (chunked files case)
                 $parityDirToRemove = $par2Path;
                 $this->logger->debug("Identified chunked PAR2 directory for removal", ['directory' => $parityDirToRemove]);
            } elseif ($mode === 'directory' && is_file($par2Path)) {
                 // Mode is 'directory', par2Path is a file inside the standard .parity directory (single file case)
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

    /**
     * Get operation metadata for verification
     */
    public function getOperationMetadataForVerification(string $targetRoot): ?array {
        try {
            $this->logger->debug("Retrieving operation metadata for verification", [
                'target_root' => $targetRoot
            ]);

            $latestOperation = $this->protectionRepository->getLatestOperationForTarget($targetRoot);

            if ($latestOperation) {
                $operationMetadata = [
                    'operation_id' => $latestOperation['operation_id'],
                    'target_root' => $targetRoot,
                    'created_at' => $latestOperation['latest_created_at'],
                    'chunks' => $latestOperation['chunks'] ?? []
                ];

                $this->logger->debug("Retrieved operation metadata for verification", [
                    'target_root' => $targetRoot,
                    'operation_id' => $operationMetadata['operation_id'],
                    'chunk_count' => count($operationMetadata['chunks'])
                ]);

                return $operationMetadata;
            }

            $this->logger->debug("No operation metadata found for target", [
                'target_root' => $targetRoot
            ]);

            return null;

        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve operation metadata for verification", [
                'target_root' => $targetRoot,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get chunks by operation ID for verification
     */
    public function getChunksByOperationId(string $operationId): array {
        try {
            $this->logger->debug("Retrieving chunks for verification", [
                'operation_id' => $operationId
            ]);

            $chunks = $this->protectionRepository->getChunksByOperationId($operationId);

            $this->logger->debug("Retrieved chunks for verification", [
                'operation_id' => $operationId,
                'chunk_count' => count($chunks)
            ]);

            return $chunks;

        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve chunks for verification", [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

} // End of class ProtectionOperations