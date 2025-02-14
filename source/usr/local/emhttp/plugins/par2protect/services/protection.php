<?php
namespace Par2Protect\Services;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;

class Protection {
    private $db;
    private $logger;
    private $config;
    private $cache;
    
    /**
     * Protection service constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->cache = Cache::getInstance();
        $this->config = Config::getInstance();
        
        // Ensure database tables exist
        $this->initializeTables();
    }
    
    /**
     * Initialize database tables
     *
     * @return void
     */
    private function initializeTables() {
        // Create protected_items table if it doesn't exist
        if (!$this->db->tableExists('protected_items')) {
            $this->logger->debug("Creating protected_items table with composite key");
            
            $this->db->query("
                CREATE TABLE protected_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT NOT NULL,
                    mode TEXT NOT NULL,                    -- 'file' or 'directory'
                    redundancy INTEGER NOT NULL,           -- redundancy percentage
                    protected_date DATETIME NOT NULL,
                    last_verified DATETIME,
                    last_status TEXT,                      -- PROTECTED, UNPROTECTED, DAMAGED, etc.
                    size INTEGER,                          -- in bytes
                    par2_size INTEGER,                     -- size of protection files in bytes
                    data_size INTEGER,                     -- size of protected data in bytes
                    par2_path TEXT,                        -- path to .par2 files
                    file_types TEXT,                       -- JSON array of file types (for directory mode)
                    parent_dir TEXT,                       -- parent directory for individual files
                    protected_files TEXT,                  -- JSON array of protected files (for individual files mode)
                    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                    UNIQUE(path, file_types)               -- Composite unique key for path + file_types
                )
            ");
            
            // Create indexes
            $this->db->query("CREATE INDEX idx_path ON protected_items(path)");
            $this->db->query("CREATE INDEX idx_last_verified ON protected_items(last_verified)");
            $this->db->query("CREATE INDEX idx_status ON protected_items(last_status)");
            $this->db->query("CREATE INDEX idx_parent_dir ON protected_items(parent_dir)");
        }
        
        // Create verification_history table if it doesn't exist
        if (!$this->db->tableExists('verification_history')) {
            $this->logger->debug("Creating verification_history table");
            
            $this->db->query("
                CREATE TABLE verification_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    protected_item_id INTEGER,
                    verification_date DATETIME NOT NULL,
                    status TEXT NOT NULL,
                    details TEXT,
                    FOREIGN KEY (protected_item_id) REFERENCES protected_items(id)
                )
            ");
        }
        
        // Create file_metadata table if it doesn't exist
        if (!$this->db->tableExists('file_metadata')) {
            $this->logger->debug("Creating file_metadata table");
            
            $this->db->query("
                CREATE TABLE file_metadata (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    protected_item_id INTEGER NOT NULL,
                    file_path TEXT NOT NULL,
                    owner TEXT NOT NULL,
                    group_name TEXT NOT NULL,
                    permissions TEXT NOT NULL,
                    extended_attributes TEXT,
                    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                    FOREIGN KEY (protected_item_id) REFERENCES protected_items(id) ON DELETE CASCADE,
                    UNIQUE(protected_item_id, file_path)
                )
            ");
            
            // Create indexes
            $this->db->query("CREATE INDEX idx_file_metadata_protected_item_id ON file_metadata(protected_item_id)");
            $this->db->query("CREATE INDEX idx_file_metadata_file_path ON file_metadata(file_path)");
        }
    }
    
    /**
     * Get all protected items
     *
     * @return array
     */
    public function getAllProtectedItems() {
        try {
            // Check cache first
            $cacheKey = 'protected_items_all';
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached protected items list");
                return $this->cache->get($cacheKey);
            }
            
            // Get all items, but exclude directory entries that have individual files protected
            $result = $this->db->query("SELECT * FROM protected_items ORDER BY protected_date DESC");
            $items = $this->db->fetchAll($result);
            
            // Format items for API response
            $formattedItems = [];
            foreach ($items as $item) {
                // Skip directory entries that have individual files protected
                // (except for "Individual Files" mode entries which we want to keep)
                if ($item['mode'] === 'directory' && $item['mode'] !== 'Individual Files') {
                    // Check if this directory has individual files
                    $childResult = $this->db->query(
                        "SELECT COUNT(*) as count FROM protected_items WHERE parent_dir = :path",
                        [':path' => $item['path']]
                    );
                    $childCount = $this->db->fetchOne($childResult)['count'];
                    
                    // Skip this directory if it has individual files
                    if ($childCount > 0) {
                        continue;
                    }
                }
                
                // Add the item to the formatted list
                $formattedItems[] = $this->formatProtectedItem($item);
            }
            
            // Cache the result for 5 minutes
            $this->logger->debug("Caching protected items list");
            $this->cache->set($cacheKey, $formattedItems, 300);
            
            return $formattedItems;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protected items", [
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get protected items: " . $e->getMessage());
        }
    }
    
    /**
     * Get individual files for a parent directory
     *
     * @param string $parentDir Parent directory path
     * @return array
     */
    public function getIndividualFiles($parentDir) {
        try {
            // Check cache first
            $cacheKey = 'individual_files_' . md5($parentDir);
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached individual files list for parent directory");
                return $this->cache->get($cacheKey);
            }
            
            $this->logger->debug("DIAGNOSTIC: getIndividualFiles called", [
                'parent_dir' => $parentDir
            ]);
            
            // Log the SQL query for debugging
            $this->logger->debug("DIAGNOSTIC: SQL query for individual files", [
                'query' => "SELECT * FROM protected_items WHERE parent_dir = :parent_dir ORDER BY path",
                'params' => [':parent_dir' => $parentDir]
            ]);
            
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE parent_dir = :parent_dir ORDER BY path",
                [':parent_dir' => $parentDir]
            );
            $items = $this->db->fetchAll($result);
            
            $this->logger->debug("DIAGNOSTIC: Raw database results", [
                'items_count' => count($items),
                'first_few_items' => array_slice(array_map(function($item) {
                    return [
                        'id' => $item['id'],
                        'path' => $item['path'],
                        'mode' => $item['mode'],
                        'parent_dir' => $item['parent_dir'] ?? null,
                        'file_types' => $item['file_types']
                    ];
                }, $items), 0, 5)
            ]);
            
            // Format items for API response
            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = $this->formatProtectedItem($item);
            }
            
            $this->logger->debug("DIAGNOSTIC: Formatted items for response", [
                'formatted_items_count' => count($formattedItems)
            ]);
            
            // Cache the result for 5 minutes
            $this->logger->debug("Caching individual files list for parent directory");
            $this->cache->set($cacheKey, $formattedItems, 300);
            
            return $formattedItems;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get individual files", [
                'parent_dir' => $parentDir,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get individual files: " . $e->getMessage());
        }
    }
    
    /**
     * Get status of protected item
     *
     * @param string $path Path to check
     * @return array
     */
    public function getStatus($path) {
        try {
            // Check cache first
            $cacheKey = 'protected_item_status_' . md5($path);
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached protected item status for path");
                return $this->cache->get($cacheKey);
            }
            
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            $formattedItem = $this->formatProtectedItem($item);
            
            // Cache the result for 5 minutes
            $this->cache->set($cacheKey, $formattedItem, 300);
            
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protection status", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get protection status: " . $e->getMessage());
        }
    }
    
    /**
     * Get status of protected item by ID
     *
     * @param int $id Item ID to check
     * @return array
     */
    public function getStatusById($id) {
        try {
            // Check cache first
            $cacheKey = 'protected_item_status_id_' . $id;
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached protected item status for ID");
                return $this->cache->get($cacheKey);
            }
            
            $this->logger->debug("DIAGNOSTIC: Getting status by ID", [
                'id' => $id
            ]);
            
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            $this->logger->debug("DIAGNOSTIC: Found item by ID", [
                'id' => $id,
                'path' => $item['path'],
                'mode' => $item['mode']
            ]);
            
            $formattedItem = $this->formatProtectedItem($item);
            
            // Cache the result for 5 minutes
            $this->cache->set($cacheKey, $formattedItem, 300);
            
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protection status by ID", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get protection status by ID: " . $e->getMessage());
        }
    }
    
    /**
     * Get status of protected item with specific file types
     *
     * @param string $path Path to check
     * @param array $fileTypes File types to filter by
     * @return array
     */
    public function getStatusWithFileTypes($path, $fileTypes) {
        try {
            // Convert file types array to JSON for cache key
            $fileTypesJson = json_encode($fileTypes);
            $cacheKey = 'protected_item_status_filetypes_' . md5($path . $fileTypesJson);
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached protected item status with file types");
                return $this->cache->get($cacheKey);
            }
            
            $this->logger->debug("DIAGNOSTIC: Getting status with file types", [
                'path' => $path,
                'file_types' => $fileTypes
            ]);
            
            // Convert file types array to JSON for comparison
            $fileTypesJson = json_encode($fileTypes);
            
            $this->logger->debug("DIAGNOSTIC: SQL query for status with file types", [
                'query' => "SELECT * FROM protected_items WHERE path = :path AND file_types = :file_types",
                'params' => [
                    ':path' => $path,
                    ':file_types' => $fileTypesJson
                ]
            ]);
            
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path AND file_types = :file_types",
                [
                    ':path' => $path,
                    ':file_types' => $fileTypesJson
                ]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                $this->logger->debug("DIAGNOSTIC: Item not found with file types", [
                    'path' => $path,
                    'file_types' => $fileTypes
                ]);
                
                throw ApiException::notFound("Item not found: $path with file types: " . implode(', ', $fileTypes));
            }
            
            $this->logger->debug("DIAGNOSTIC: Found item with file types", [
                'path' => $path,
                'file_types' => $fileTypes,
                'item_id' => $item['id'],
                'item_mode' => $item['mode'],
                'has_protected_files' => isset($item['protected_files']) && !empty($item['protected_files'])
            ]);
            
            $formattedItem = $this->formatProtectedItem($item);
            
            // Cache the result for 5 minutes
            $this->cache->set($cacheKey, $formattedItem, 300);
            
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protection status with file types", [
                'path' => $path,
                'file_types' => $fileTypes,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get protection status with file types: " . $e->getMessage());
        }
    }
    
    /**
     * Format protected item for API response
     *
     * @param array $item Protected item from database
     * @return array
     */
    private function formatProtectedItem($item) {
        return [
            'id' => $item['id'],
            'path' => $item['path'],
            'mode' => $item['mode'],
            'redundancy' => $item['redundancy'],
            'protected_date' => $item['protected_date'],
            'last_verified' => $item['last_verified'],
            'last_status' => $item['last_status'],
            'last_details' => $item['last_details'] ?? null,
            'size' => $item['size'], // Keep original size for backward compatibility
            'size_formatted' => $this->formatSizeDual($item['par2_size'] ?? 0, $item['data_size'] ?? $item['size']),
            'par2_size' => $item['par2_size'] ?? 0,
            'data_size' => $item['data_size'] ?? $item['size'],
            'par2_path' => $item['par2_path'],
            'file_types' => $item['file_types'] ? json_decode($item['file_types'], true) : null,
            'parent_dir' => $item['parent_dir'] ?? null,
            'protected_files' => $item['protected_files'] ? json_decode($item['protected_files'], true) : null
        ];
    }
    
    /**
     * Format sizes in dual human-readable format (protection files / data)
     *
     * @param int $par2Size Size of protection files in bytes
     * @param int $dataSize Size of protected data in bytes
     * @return string
     */
    private function formatSizeDual($par2Size, $dataSize) {
        return $this->formatSize($par2Size) . ' / ' . $this->formatSize($dataSize);
    }
    
    /**
     * Format size in human-readable format
     *
     * @param int $bytes Size in bytes
     * @return string
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Protect a file or directory
     *
     * @param string $path Path to protect
     * @param int $redundancy Redundancy percentage
     * @param array $fileTypes File types to protect (for directory mode)
     * @param array $fileCategories File categories selected by the user
     * @param array $advancedSettings Advanced settings for par2 command
     * @return array
     */
    public function protect($path, $redundancy = null, $fileTypes = null, $fileCategories = null, $advancedSettings = null) {
        $this->logger->debug("Starting protection operation", [
            'operation_id' => uniqid('protect_'),
            'path' => $path,
            'redundancy' => $redundancy,
            'file_types' => $fileTypes ? json_encode($fileTypes) : null,
            'file_categories' => $fileCategories ? json_encode($fileCategories) : null,
            'advanced_settings' => $advancedSettings ? json_encode($advancedSettings) : null,
            'action' => 'Protect',
            'operation_type' => 'protect'
        ]);
        
        try {
            // Use default redundancy if not specified
            if ($redundancy === null) {
                $redundancy = $this->config->get('protection.default_redundancy', 10);
            }
            
            // Check if path exists
            if (!file_exists($path)) {
                throw ApiException::badRequest("Path does not exist: $path");
            }
            
            // Determine if this is a file or directory
            $mode = is_dir($path) ? 'directory' : 'file';
            
            // Handle protection based on mode
            if ($mode === 'directory' && $fileTypes && !empty($fileTypes)) {
                // For directory with file types, protect individual files
                $result = $this->protectIndividualFiles($path, $redundancy, $fileTypes, $fileCategories, $advancedSettings);
                
                if (!$result['success'] && !isset($result['skipped'])) {
                    throw new ApiException($result['error']);
                }
                
                if (isset($result['skipped']) && $result['skipped']) {
                    $this->logger->info("Individual files protection skipped", [
                        'path' => $path,
                        'action' => 'Protect',
                        'operation_type' => 'protect',
                        'status' => 'Skipped',
                        'reason' => $result['message']
                    ]);
                    
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => $result['message']
                    ];
                } else {
                    $this->logger->info("Individual files protection completed", [
                        'path' => $path,
                        'files_count' => $result['files_count'],
                        'action' => 'Protect',
                        'operation_type' => 'protect',
                        'status' => 'Success'
                    ]);
                }
                
                return [
                    'success' => true,
                    'message' => 'Protection operation completed successfully',
                    'files_count' => $result['files_count'],
                    'protected_files' => $result['protected_files']
                ];
            } else {
                // For single file or entire directory, use standard protection
                $result = $this->createPar2Files($path, $redundancy, $mode, $fileTypes, $fileCategories, null, $advancedSettings);
                
                if (!$result['success'] && !isset($result['skipped'])) {
                    throw new ApiException($result['error']);
                }
                
                if (isset($result['skipped']) && $result['skipped']) {
                    $this->logger->info("Protection operation skipped", [
                        'path' => $path,
                        'action' => 'Protect',
                        'operation_type' => 'protect',
                        'status' => 'Skipped',
                        'reason' => $result['message']
                    ]);
                    
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => $result['message']
                    ];
                }
                
                // Add to protected items database
                $this->addProtectedItem($path, $mode, $redundancy, $result['par2_path'], $fileTypes, null, null, $fileCategories);
                
                // Collect and store metadata for the file or directory
                $this->collectAndStoreMetadata($path, $mode, $this->db->lastInsertId());

                // Clear cache for protected items
                $this->clearProtectedItemsCache();
                
                $this->logger->info("Protect operation completed", [
                    'path' => $path,
                    'par2_path' => $result['par2_path'],
                    'action' => 'Protect',
                    'operation_type' => 'protect',
                    'status' => 'Success'
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Protection operation completed successfully',
                    'par2_path' => $result['par2_path']
                ];
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Protect operation failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'action' => 'Protect',
                'operation_type' => 'protect',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Protection operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Create par2 files
     *
     * @param string $path Path to protect
     * @param int $redundancy Redundancy percentage
     * @param string $mode Protection mode (file or directory)
     * @param array $fileTypes File types to protect (for directory mode)
     * @param array $fileCategories File categories selected by the user
     * @param string $customParityDir Optional custom parity directory path
     * @param array $advancedSettings Advanced settings for par2 command
     * @return array
     */
    private function createPar2Files($path, $redundancy, $mode, $fileTypes = null, $fileCategories = null, $customParityDir = null, $advancedSettings = null) {
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
                $fileCategory = $this->getFileCategoryName($fileTypes);
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
        } else if ($customParityDir === null) {
            // For directories or regular files, use standard parity directory
            if ($mode === 'directory') {
                $parityDir = rtrim($path, '/') . '/' . $parityDirBase;
            } else {
                $parityDir = dirname($path) . '/' . $parityDirBase;
            }
        }
        
        // Determine par2 file path
        $par2Path = $parityDir . '/' . basename($path) . '.par2';
        
        // Check if par2 files already exist to prevent duplicate execution
        $par2Base = pathinfo($par2Path, PATHINFO_FILENAME);
        $existingPar2Files = glob($parityDir . '/' . $par2Base . '*.par2');
        if (!empty($existingPar2Files)) {
            $this->logger->warning("DUPLICATE EXECUTION DETECTED", ['operation_id' => $operationId, 'path' => $path]);
            $this->logger->debug("Par2 files already exist, skipping creation", [
                'path' => $path,
                'par2_path' => $par2Path,
                'existing_files_count' => count($existingPar2Files)
            ]);
            return [
                'success' => true,
                'par2_path' => $par2Path
            ];
        }
        
        // Get configuration settings
        $config = \Par2Protect\Core\Config::getInstance();
        
        // Build par2 command base with correct path
        $baseCommand = "/usr/local/bin/par2 create -q -r$redundancy";
        
        // Add -t parameter for CPU threads if set
        $maxCpuThreads = $config->get('resource_limits.max_cpu_usage');
        if ($maxCpuThreads) {
            $baseCommand .= " -t$maxCpuThreads";
        }
        
        // Add -m parameter for memory usage if set
        $maxMemory = $config->get('resource_limits.max_memory_usage');
        if ($maxMemory) {
            $baseCommand .= " -m$maxMemory";
        }
        
        // Add -T parameter for parallel file hashing if set
        $parallelFileHashing = $config->get('resource_limits.parallel_file_hashing');
        if ($parallelFileHashing) {
            $baseCommand .= " -T$parallelFileHashing";
        }
        
        // Apply I/O priority if set
        $ioPriority = $config->get('resource_limits.io_priority');
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
        
        // Ensure advanced settings are properly decoded if they're a JSON string
        if ($advancedSettings && is_string($advancedSettings)) {
            $this->logger->debug("DIAGNOSTIC: Advanced settings received as string, decoding JSON", [
                'advanced_settings_string' => $advancedSettings
            ]);
            $advancedSettings = json_decode($advancedSettings, true);
            
            $this->logger->debug("DIAGNOSTIC: Advanced settings after JSON decode", [
                'advanced_settings' => $advancedSettings ? json_encode($advancedSettings) : 'null',
                'decode_success' => $advancedSettings ? 'true' : 'false',
                'json_error' => json_last_error_msg()
            ]);
        }
        
        // Count files to be protected
        $fileCount = 0;
        $findCommand = '';
        
        if ($mode === 'directory') {
            $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
            
            // Build the find command
            if ($fileTypes && !empty($fileTypes)) {
                // For directories with file type filtering
                $extensionFilters = [];
                foreach ($fileTypes as $ext) {
                    $extensionFilters[] = "-name '*.{$ext}'";
                }
                $extensionPattern = "\\( " . implode(' -o ', $extensionFilters) . " \\)";
                
                // Build find command with file extensions
                $findCommand = "find " . escapeshellarg($path) . " " . $extensionPattern . " -type f -not -path \"*/" . $parityDirBase . "/*\" -not -path \"*/" . $parityDirBase . "-*/*\"";
            } else {
                // For directories without file type filtering
                $findCommand = "find " . escapeshellarg($path) . " -type f -not -path \"*/" . $parityDirBase . "/*\" -not -path \"*/" . $parityDirBase . "-*/*\"";
            }
            
            // Log the find command
            $this->logger->debug("Find command for counting files", [
                'command' => $findCommand
            ]);
            
            // Execute the find command to count files
            $countCommand = $findCommand . " | wc -l";
            exec($countCommand, $countOutput, $countReturnCode);
            
            if ($countReturnCode !== 0) {
                $this->logger->error("Failed to count files using find command", [
                    'command' => $countCommand,
                    'return_code' => $countReturnCode
                ]);
                return [
                    'success' => false,
                    'error' => "Failed to count files in directory: $path"
                ];
            }
            
            $fileCount = (int)trim($countOutput[0]);
        } else {
            // For single file
            $fileCount = 1;
        }
        
        $this->logger->debug("File count for protection", [
            'path' => $path,
            'file_count' => $fileCount,
            'mode' => $mode
        ]);
        
        // Add advanced settings if provided
        $useBlockCount = false;
        $useBlockSize = false;
        
        // If file count is greater than 2000, ensure we have enough blocks
        // $minBlockCount = ($fileCount > 2000) ? ceil($fileCount * 1.1) : 2000;
        $minBlockCount = ($fileCount > 2000) ? $fileCount : 2000;
        
        if ($advancedSettings) {
            // Block-count and block-size cannot be used together
            if (isset($advancedSettings['block_count']) && !empty($advancedSettings['block_count'])) {
                $blockCount = intval($advancedSettings['block_count']);
                // Ensure block count is at least the number of files plus 10% if file count > 2000
                if ($fileCount > 2000) {
                    $blockCount = max($blockCount, $minBlockCount);
                }
                
                $baseCommand .= " -b$blockCount";
                $useBlockCount = true;
                
                $this->logger->debug("Using block-count parameter", [
                    'block_count' => $blockCount,
                    'original_value' => $advancedSettings['block_count'],
                    'file_count' => $fileCount,
                    'min_required' => $minBlockCount
                ]);
            } 
            // Only use block-size if block-count is not set
            else if (isset($advancedSettings['block_size']) && !empty($advancedSettings['block_size'])) {
                $blockSize = intval($advancedSettings['block_size']);
                $baseCommand .= " -s$blockSize";
                $useBlockSize = true;
                
                $this->logger->debug("Using block-size parameter", [
                    'block_size' => $blockSize
                ]);
            }
            
            // Add target size if provided (redundancy target size in MB)
            if (isset($advancedSettings['target_size']) && !empty($advancedSettings['target_size'])) {
                $targetSize = intval($advancedSettings['target_size']);
                // Use -rm parameter for target size in megabytes
                $baseCommand .= " -rm$targetSize";
                $this->logger->debug("Using target-size parameter", [
                    'target_size' => $targetSize
                ]);
            }
            
            // Add recovery files count if provided
            if (isset($advancedSettings['recovery_files']) && !empty($advancedSettings['recovery_files'])) {
                $recoveryFiles = intval($advancedSettings['recovery_files']);
                if ($recoveryFiles > 0 && !$useBlockCount) {
                    $baseCommand .= " -n$recoveryFiles";
                    $this->logger->debug("Using recovery-files parameter", [
                        'recovery_files' => $recoveryFiles,
                        'original_value' => $advancedSettings['recovery_files']
                    ]);
                } else {
                    $this->logger->debug("Recovery files value is not valid, skipping", [
                        'recovery_files' => $advancedSettings['recovery_files'],
                        'parsed_value' => $recoveryFiles
                    ]);
                }
            }
        }
        
        // If file count is greater than default block count (2000) and no block-count or block-size is set,
        // automatically add block-count parameter
        if ($fileCount > 2000 && !$useBlockCount && !$useBlockSize) {
            // Add a buffer to ensure we have enough blocks (file count + 10%)
            $blockCount = $minBlockCount;
            $baseCommand .= " -b$blockCount";
            
            $this->logger->debug("Automatically setting block-count parameter", [
                'block_count' => $blockCount,
                'file_count' => $fileCount,
                'reason' => 'File count exceeds default block count (2000)',
                'buffer_applied' => '10%'
            ]);
        }
        
        // For directories with file filtering, we need to get all files
        if ($mode === 'directory' && $fileTypes && !empty($fileTypes)) {
            // For directories, we need to get all files
            // Check if we have files to protect
            if ($fileCount <= 0) {
                $this->logger->warning("No files to protect in directory", [
                    'path' => $path
                ]);
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => "No files to protect in directory: $path"
                ];
            }
            
            // Create parity directory if it doesn't exist - only after we know we have files
            if (!is_dir($parityDir)) {
                if (!@mkdir($parityDir, 0755, true)) {
                    return [
                        'success' => false,
                        'error' => "Failed to create parity directory: $parityDir"
                    ];
                }
            }
            
            // Check if we need to split the command due to file count limit
            if ($fileCount > 32768) {
                $this->logger->debug("File count exceeds limit, splitting into multiple commands", [
                    'file_count' => $fileCount,
                    'limit' => 32768
                ]);
                
                return $this->executeMultiplePar2Commands($path, $baseCommand, $par2Path, $findCommand, $fileCount);
            }
            
            // Build command with find command output
            // Use the directory as the basepath
            $basePath = $path;
            $command = $baseCommand . " -B" . escapeshellarg($basePath) . " -a" . escapeshellarg($par2Path) . " -- \$(";
            $command .= $findCommand;
            $command .= ")";
            
            // Log the file count and command
            $this->logger->debug("Protecting directory", [
                'path' => $path,
                'file_count' => $fileCount,
                'operation_id' => $operationId
            ]);
            
            // Log the full command (truncated if too long)
            $this->logger->debug("Par2 command", [
                'command' => $command,
                'file_count' => $fileCount
            ]);
            
            // Execute the command using our helper function
            $result = $this->executePar2Command($command, []);
            return $result;
        } else {
            // Add diagnostic logging for directory handling
            $this->logger->debug("DIAGNOSTIC: Command formation for path", [
                'path' => $path,
                'mode' => $mode,
                'is_dir' => is_dir($path),
                'is_file' => is_file($path)
            ]);
            
            // For directories without file type filtering, we need to get all files
            if ($mode === 'directory' || is_dir($path)) {
                $this->logger->debug("DIAGNOSTIC: Directory detected without file type filtering", [
                    'path' => $path
                ]);
                
                // Check if we have files to protect
                if ($fileCount <= 0) {
                    $this->logger->warning("No files to protect in directory", [
                        'path' => $path
                    ]);
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => "No files to protect in directory: $path"
                    ];
                }
                
                // Create parity directory if it doesn't exist - only after we know we have files
                if (!is_dir($parityDir)) {
                    if (!@mkdir($parityDir, 0755, true)) {
                        return [
                            'success' => false,
                            'error' => "Failed to create parity directory: $parityDir"
                        ];
                    }
                }
                
                // Check if we need to split the command due to file count limit
                if ($fileCount > 32768) {
                    $this->logger->debug("File count exceeds limit, splitting into multiple commands", [
                        'file_count' => $fileCount,
                        'limit' => 32768
                    ]);
                    
                    return $this->executeMultiplePar2Commands($path, $baseCommand, $par2Path, $findCommand, $fileCount);
                }
                
                // Build command with find command output
                $basePath = $path;
                $command = $baseCommand . " -B" . escapeshellarg($basePath) . " -a" . escapeshellarg($par2Path) . " -- \$(";
                $command .= $findCommand;
                $command .= ")";
                
                $this->logger->debug("DIAGNOSTIC: Directory protection command", [
                    'path' => $path,
                    'file_count' => $fileCount,
                    'command' => $command
                ]);
                
                // Execute the command using our helper function
                $result = $this->executePar2Command($command, []);
                
                if (!$result['success']) {
                    return $result;
                }
            } else {
                // For files, just use the file path
                $basePath = dirname($path);
                $command = $baseCommand . " -B" . escapeshellarg($basePath) . " -a" . escapeshellarg($par2Path);
                
                $this->logger->debug("DIAGNOSTIC: File protection command", [
                    'path' => $path,
                    'command' => $command
                ]);
                
                // Execute the command using our helper function with a single file
                $result = $this->executePar2Command($command, [$path]);
                
                if (!$result['success']) {
                    return $result;
                }
            }
        }
        
        return [
            'success' => true,
            'par2_path' => $par2Path
        ];
    }
    
    /**
     * Execute multiple par2 commands for large file sets
     *
     * @param string $path Base path
     * @param string $baseCommand Base par2 command
     * @param string $par2Path Path to par2 file
     * @param string $findCommand Find command to use
     * @param int $fileCount Total file count
     * @return array Result with success/error information
     */
    private function executeMultiplePar2Commands($path, $baseCommand, $par2Path, $findCommand, $fileCount) {
        $this->logger->debug("Executing multiple par2 commands for large file set", [
            'path' => $path,
            'file_count' => $fileCount
        ]);
        
        // Calculate number of commands needed (32000 files per command to leave some margin)
        $batchSize = 32000;
        $numCommands = ceil($fileCount / $batchSize);
        
        $this->logger->debug("Splitting into multiple commands", [
            'total_files' => $fileCount,
            'batch_size' => $batchSize,
            'num_commands' => $numCommands
        ]);
        
        // Get all subdirectories
        $subdirs = [];
        exec("find " . escapeshellarg($path) . " -type d -not -path \"*/\\.*\"", $subdirs);
        
        // If we have enough subdirectories, process each separately
        if (count($subdirs) >= $numCommands) {
            $this->logger->debug("Using subdirectory-based splitting", [
                'subdirs_count' => count($subdirs)
            ]);
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($subdirs as $subdir) {
                // Skip the base directory itself
                if ($subdir === $path) {
                    continue;
                }
                
                // Count files in this subdirectory
                $subFindCommand = str_replace(escapeshellarg($path), escapeshellarg($subdir), $findCommand);
                $countCommand = $subFindCommand . " | wc -l";
                exec($countCommand, $countOutput, $countReturnCode);
                
                if ($countReturnCode !== 0) {
                    $this->logger->warning("Failed to count files in subdirectory", [
                        'subdir' => $subdir,
                        'command' => $countCommand
                    ]);
                    continue;
                }
                
                $subdirFileCount = (int)trim($countOutput[0]);
                
                // Skip empty subdirectories
                if ($subdirFileCount <= 0) {
                    continue;
                }
                
                // Create par2 command for this subdirectory
                $subPar2Path = dirname($par2Path) . '/' . basename($subdir) . '.par2';
                $subCommand = $baseCommand . " -B" . escapeshellarg($subdir) . " -a" . escapeshellarg($subPar2Path) . " -- \$(";
                $subCommand .= $subFindCommand;
                $subCommand .= ")";
                
                $this->logger->debug("Subdirectory par2 command", [
                    'subdir' => $subdir,
                    'file_count' => $subdirFileCount,
                    'command' => $subCommand
                ]);
                
                // Execute the command using our helper function
                $result = $this->executePar2Command($subCommand, []);
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    $this->logger->error("Failed to protect subdirectory", [
                        'subdir' => $subdir,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
            if ($successCount > 0) {
                return [
                    'success' => true,
                    'message' => "Protected $successCount subdirectories" . ($failCount > 0 ? " ($failCount failed)" : ""),
                    'par2_path' => dirname($par2Path)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Failed to protect any subdirectories"
                ];
            }
        } else {
            // Use file batching approach
            $this->logger->debug("Using file batching approach", [
                'batch_size' => $batchSize,
                'num_batches' => $numCommands
            ]);
            
            $successCount = 0;
            $failCount = 0;
            
            for ($i = 0; $i < $numCommands; $i++) {
                $start = $i * $batchSize;
                $batchFindCommand = $findCommand . " | sort | head -n " . ($start + $batchSize) . " | tail -n $batchSize";
                
                // Create par2 command for this batch
                $batchPar2Path = dirname($par2Path) . '/' . basename($path) . "_batch" . ($i + 1) . ".par2";
                $batchCommand = $baseCommand . " -B" . escapeshellarg($path) . " -a" . escapeshellarg($batchPar2Path) . " -- \$(";
                $batchCommand .= $batchFindCommand;
                $batchCommand .= ")";
                
                $this->logger->debug("Batch par2 command", [
                    'batch' => $i + 1,
                    'start' => $start,
                    'size' => $batchSize,
                    'command' => $batchCommand
                ]);
                
                // Execute the command using our helper function
                $result = $this->executePar2Command($batchCommand, []);
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    $this->logger->error("Failed to protect batch", [
                        'batch' => $i + 1,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
            if ($successCount > 0) {
                return [
                    'success' => true,
                    'message' => "Protected $successCount batches" . ($failCount > 0 ? " ($failCount failed)" : ""),
                    'par2_path' => dirname($par2Path)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Failed to protect any batches"
                ];
            }
        }
    }
    
    /**
     * Execute a par2 command using a shell script to avoid command line length limitations
     *
     * @param string $baseCommand The base par2 command without file arguments
     * @param array $files Array of files to include in the command
     * @return array Result with success/error information
     */
    private function executePar2Command($command, $files = []) {
        $executionId = uniqid('exec_');
        
        // If we have a command with embedded find, use it directly
        if (strpos($command, '$(') !== false) {
            $this->logger->debug("Executing par2 command with embedded find", [
                'command' => $command,
                'execution_id' => $executionId
            ]);
        } else if (!empty($files)) {
            // Otherwise, if we have files, append them to the command
            $this->logger->debug("Executing par2 command with file list", [
                'command' => $command,
                'file_count' => count($files),
                'execution_id' => $executionId
            ]);
            
            // Add each file to the command
            foreach ($files as $file) {
                $command .= " " . escapeshellarg($file);
            }
        }
        
        // Execute the script
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        // Check if the output contains only acceptable warnings
        $hasRealError = false;
        $warningCount = 0;
        $acceptableWarnings = [
            'Skipping 0 byte file:',
            'Could not create',
            'File already exists',
            'No data found'
        ];
        
        foreach ($output as $line) {
            $isAcceptableWarning = false;
            foreach ($acceptableWarnings as $warning) {
                if (strpos($line, $warning) !== false) {
                    $isAcceptableWarning = true;
                    $warningCount++;
                    break;
                }
            }
            if (!$isAcceptableWarning && trim($line) !== '') {
                $hasRealError = true;
            }
        }
        
        // If we have no real errors, consider it a success despite the return code
        if ($returnCode !== 0 && !$hasRealError) {
            return [
                'success' => true,
                'output' => $output,
                'warnings' => true
            ];
        }
        
        // Check if the command was successful
        if ($returnCode !== 0) {
            $this->logger->error("Par2 command failed", [
                'output' => implode("\n", $output),
                'return_code' => $returnCode, 
                'execution_id' => $executionId,
                'command' => $baseCommand . ' [' . count($files) . ' files]'
            ]);
            return [
                'success' => false,
                'error' => "Par2 command failed: " . implode("\n", $output)
            ];
        }
        
        return [
            'success' => true,
            'output' => $output
        ];
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
    private function protectIndividualFiles($dirPath, $redundancy, $fileTypes, $fileCategories = null, $advancedSettings = null) {
        $this->logger->debug("Protecting individual files in directory", [
            'path' => $dirPath, 
            'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes,
            'file_types_count' => is_array($fileTypes) ? count($fileTypes) : 0,
            'file_categories' => is_array($fileCategories) ? json_encode($fileCategories) : $fileCategories,
            'redundancy' => $redundancy,
            'advanced_settings' => $advancedSettings ? json_encode($advancedSettings) : null
        ]);
        
        // FIXED: Always use the provided categories if available, otherwise determine from file types
        $fileCategory = '';
        
        // Check if file_categories is available and not empty
        if (isset($fileCategories) && is_array($fileCategories) && !empty($fileCategories)) {
            // Use the provided categories directly
            $fileCategory = implode('-', $fileCategories);
            $this->logger->debug("DIAGNOSTIC: Using provided categories for folder name", [
                'file_categories' => json_encode($fileCategories),
                'category_name' => $fileCategory
            ]);
        } else {
            // Fall back to determining category from file types
            $fileCategory = $this->getFileCategoryName($fileTypes);
            $this->logger->debug("DIAGNOSTIC: Determined category from file types", [
                'file_types' => json_encode($fileTypes),
                'category_name' => $fileCategory
            ]);
        }
        
        // Add diagnostic logging for category determination
        $this->logger->debug("DIAGNOSTIC: Category determined for protection", [
            'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes,
            'file_categories' => is_array($fileCategories) ? json_encode($fileCategories) : $fileCategories,
            'determined_category' => $fileCategory
        ]);
        
        $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
        $categoryParityDir = rtrim($dirPath, '/') . '/' . $parityDirBase . '-' . $fileCategory;
        
        $this->logger->debug("Using category-specific parity directory for individual files", [
            'path' => $dirPath,
            'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes,
            'category' => $fileCategory,
            'parity_dir' => $categoryParityDir
        ]);
        
        // Create the category parity directory if it doesn't exist
        if (!is_dir($categoryParityDir)) {
            if (!@mkdir($categoryParityDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Failed to create category parity directory: $categoryParityDir"
                ];
            }
        }
        
        // Get all files in directory
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        $filesToProtect = [];
        $totalCount = 0;
        $filteredCount = 0;
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalCount++;
                $filePath = $file->getPathname();
                
                // Skip files in parity directory
                $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
                // Skip both standard parity directory and category-specific parity directories
                        if (strpos($filePath, "/$parityDirBase/") !== false || strpos($filePath, "/$parityDirBase-") !== false) {
                    continue;
                }
                
                // Filter by file type
                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                if (in_array($extension, $fileTypes)) {
                    $filteredCount++;
                    $filesToProtect[] = $filePath;
                }
            }
        }
        
        $this->logger->debug("File filtering results", [
            'path' => $dirPath,
            'total_files' => $totalCount,
            'filtered_files' => $filteredCount,
            'files_to_protect' => count($filesToProtect)
        ]);
        
        // Check if we have files to protect
        if (empty($filesToProtect)) {
            $this->logger->warning("No files to protect in directory", [
                'path' => $dirPath
            ]);
            return [
                'success' => true,
                'skipped' => true,
                'message' => "No files to protect in directory: $dirPath"
            ];
        }
        
        // Create the category parity directory if it doesn't exist - only after we know we have files
        if (!is_dir($categoryParityDir)) {
            if (!@mkdir($categoryParityDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Failed to create category parity directory: $categoryParityDir"
                ];
            }
        }
        
        // Protect each file individually
        $protectedFiles = [];
        $successCount = 0;
        
        foreach ($filesToProtect as $filePath) {
            try {
                // Create par2 files for this file
                // Use the same category parity directory for all files
                $result = $this->createPar2Files($filePath, $redundancy, 'file', $fileTypes, $fileCategories, $categoryParityDir, $advancedSettings);
                
                if ($result['success']) {
                    // Don't add individual files to the database anymore
                    // Just count them for reporting
                    $protectedFiles[] = $filePath;
                    $successCount++;
                } else {
                    $this->logger->error("Failed to protect file", [
                        'path' => $filePath,
                        'error' => $result['error']
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error("Exception while protecting file", [
                    'path' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Also add the directory entry with file types for re-protection
        // This is the only entry we add to the database
        // Store the protected files in the database for the info icon
        $this->addProtectedItem(
            $dirPath, 
            'Individual Files - ' . $fileCategory, 
            $redundancy, 
            $categoryParityDir, 
            $fileTypes,
            null,
            $protectedFiles
        );

        // Clear cache for protected items
        $this->clearProtectedItemsCache();
        
        // Get the ID of the newly added protected item
        $protectedItemId = $this->db->lastInsertId();
        
        // Collect and store metadata for each protected file
        if ($protectedFiles && is_array($protectedFiles)) {
            foreach ($protectedFiles as $filePath) {
                $this->collectAndStoreFileMetadata($filePath, $protectedItemId);
            }
        }
        
        // Log the final result
        $this->logger->debug("Individual files protection completed", [
            'path' => $dirPath,
            'files_count' => $successCount,
            'total_files_found' => count($filesToProtect)
        ]);
        
        return [
            'success' => true,
            'files_count' => $successCount,
            'protected_files' => $protectedFiles,
            'category' => $fileCategory
        ];
    }
    
    /**
     * Get file category name based on file types
     *
     * @param array $fileTypes File types array
     * @return string Category name
     */
    private function getFileCategoryName($fileTypes) {
        $this->logger->debug("DIAGNOSTIC: getFileCategoryName called", [
            'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes,
            'file_types_count' => is_array($fileTypes) ? count($fileTypes) : 0
        ]);
        
        // Define known categories and their extensions
        $categories = [
            'videos' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg'],
            'audios' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'],
            'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt'],
            'archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2']
        ];
        
        // Check if all file types belong to a single category
        foreach ($categories as $category => $extensions) {
            $matchCount = 0;
            $allMatch = true;
            foreach ($fileTypes as $type) {
                if (!in_array($type, $extensions)) {
                    $allMatch = false;
                    break;
                } else {
                    $matchCount++;
                }
            }
            
            $this->logger->debug("DIAGNOSTIC: Category match check", [
                'category' => $category,
                'all_match' => $allMatch ? 'true' : 'false',
                'match_count' => $matchCount,
                'total_types' => count($fileTypes)
            ]);
            
            if ($allMatch && count($fileTypes) > 0) {
                $this->logger->debug("DIAGNOSTIC: Single category match found", [
                    'category' => $category
                ]);
                return $category;
            }
        }
        
        // Count how many extensions match each category
        $categoryMatches = [];
        foreach ($categories as $category => $extensions) {
            $categoryMatches[$category] = 0;
            foreach ($fileTypes as $type) {
                if (in_array($type, $extensions)) {
                    $categoryMatches[$category]++;
                }
            }
        }
        
        $this->logger->debug("DIAGNOSTIC: Category matches count", [
            'category_matches' => $categoryMatches
        ]);
        
        // If no single category matches, use a generic name with the first few extensions
        if (count($fileTypes) <= 3) {
            $this->logger->debug("DIAGNOSTIC: Using file extensions as category name", [
                'name' => implode('-', $fileTypes)
            ]);
            return implode('-', $fileTypes);
        } else {
            $this->logger->debug("DIAGNOSTIC: Using truncated file extensions as category name", [
                'name' => implode('-', array_slice($fileTypes, 0, 3)) . '-etc'
            ]);
            return implode('-', array_slice($fileTypes, 0, 3)) . '-etc';
        }
    }
    
    // Method removed as it's no longer used
    
    /**
     * Add protected item to database
     *
     * @param string $path Path to protect
     * @param string $mode Protection mode (file or directory)
     * @param int $redundancy Redundancy percentage
     * @param string $par2Path Path to par2 file
     * @param array $fileTypes File types to protect (for directory mode)
     * @param string $parentDir Parent directory for individual files
     * @param array $protectedFiles List of protected files (for individual files mode)
     * @param array $fileCategories File categories selected by the user
     * @return bool
     */
    private function addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes = null, $parentDir = null, $protectedFiles = null, $fileCategories = null) {
        try {
            // Add diagnostic logging for database storage
            $this->logger->debug("DIAGNOSTIC: Starting addProtectedItem for path", [
                'path' => $path,
                'mode' => $mode,
                'file_types' => $fileTypes ? json_encode($fileTypes) : null,
                'file_categories' => $fileCategories ? json_encode($fileCategories) : null,
                'parent_dir' => $parentDir,
                'protected_files_count' => $protectedFiles ? count($protectedFiles) : 0,
                'par2_path' => $par2Path
            ]);
            
            // Calculate sizes
            $dataSize = $this->getDataSize($path, $fileTypes, $protectedFiles);
            $par2Size = $this->getPar2Size($par2Path);
            
            $this->db->beginTransaction();
            
            // Check if item already exists with same path AND file types
            $this->logger->debug("DIAGNOSTIC: Checking if item already exists with same path and file types", [
                'path' => $path,
                'file_types' => $fileTypes ? json_encode($fileTypes) : null,
                'query' => "SELECT id FROM protected_items WHERE path = :path AND file_types = :file_types"
            ]);
            
            $result = $this->db->query(
                "SELECT id FROM protected_items WHERE path = :path AND file_types = :file_types",
                [
                    ':path' => $path,
                    ':file_types' => $fileTypes ? json_encode($fileTypes) : null
                ]
            );
            $existingItem = $this->db->fetchOne($result);
            
            if ($existingItem) {
                // Log that we're updating an existing item
                $this->logger->debug("DIAGNOSTIC: FOUND EXISTING ITEM with same path and file types, updating", [
                    'path' => $path,
                    'existing_id' => $existingItem['id'],
                    'mode' => $mode,
                    'file_types' => $fileTypes ? json_encode($fileTypes) : null
                ]);
                
                // Get the existing item details for comparison
                $existingDetailsResult = $this->db->query(
                    "SELECT * FROM protected_items WHERE id = :id",
                    [':id' => $existingItem['id']]
                );
                $existingDetails = $this->db->fetchOne($existingDetailsResult);
                
                // Log the existing item details
                $this->logger->debug("DIAGNOSTIC: EXISTING ITEM DETAILS before update", [
                    'id' => $existingDetails['id'],
                    'path' => $existingDetails['path'],
                    'mode' => $existingDetails['mode'],
                    'file_types' => $existingDetails['file_types'],
                    'par2_path' => $existingDetails['par2_path']
                ]);
                
                // Update existing item
                $this->logger->debug("DIAGNOSTIC: Updating existing item with new values", [
                    'id' => $existingItem['id'],
                    'new_mode' => $mode,
                    'new_file_types' => $fileTypes ? json_encode($fileTypes) : null,
                    'new_par2_path' => $par2Path
                ]);
                
                $this->db->query(
                    "UPDATE protected_items SET
                    mode = :mode,
                    redundancy = :redundancy,
                    protected_date = :now,
                    par2_path = :par2_path,
                    file_types = :file_types,
                    size = :size,
                    par2_size = :par2_size,
                    data_size = :data_size,
                    parent_dir = :parent_dir,
                    protected_files = :protected_files,
                    last_status = 'PROTECTED'
                    WHERE id = :id",
                    [
                        ':id' => $existingItem['id'],
                        ':mode' => $mode,
                        ':redundancy' => $redundancy,
                        ':now' => date('Y-m-d H:i:s'),
                        ':par2_path' => $par2Path,
                        ':file_types' => $fileTypes ? json_encode($fileTypes) : null,
                        ':size' => $this->getPathSize($path),
                    ':par2_size' => $par2Size,
                    ':data_size' => $dataSize,
                        ':parent_dir' => $parentDir,
                        ':protected_files' => $protectedFiles ? json_encode($protectedFiles) : null
                    ]
                );
            } else {
                // Insert new item
                $this->logger->debug("DIAGNOSTIC: No existing item found, creating new item", [
                    'path' => $path,
                    'mode' => $mode,
                    'file_types' => $fileTypes ? json_encode($fileTypes) : null,
                    'par2_path' => $par2Path
                ]);
                
                $this->db->query(
                    "INSERT INTO protected_items
                    (path, mode, redundancy, protected_date, par2_path, file_types, size, par2_size, data_size, parent_dir, protected_files, last_status)
                    VALUES (:path, :mode, :redundancy, :now, :par2_path, :file_types, :size, :par2_size, :data_size, :parent_dir, :protected_files, 'PROTECTED')",
                    [
                        ':path' => $path,
                        ':mode' => $mode,
                        ':redundancy' => $redundancy,
                        ':now' => date('Y-m-d H:i:s'),
                        ':par2_path' => $par2Path,
                        ':file_types' => $fileTypes ? json_encode($fileTypes) : null,
                        ':size' => $this->getPathSize($path),
                        ':par2_size' => $par2Size,
                        ':data_size' => $dataSize,
                        ':parent_dir' => $parentDir,
                        ':protected_files' => $protectedFiles ? json_encode($protectedFiles) : null
                    ]
                );
            }
            
            $this->db->commit();

            // Clear cache for protected items
            $this->clearProtectedItemsCache();
            
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Remove protection
     *
     * @param string $path Path to unprotect
     * @return array
     */
    public function remove($path) {
        $this->logger->debug("Removing protection", [
            'path' => $path,
            'path_type' => gettype($path),
            'is_numeric' => is_numeric($path) ? 'true' : 'false',
            'action' => 'Remove',
            'operation_type' => 'remove'
        ]);
        
        try {
            // Add diagnostic logging for path/id confusion
            $this->logger->debug("DIAGNOSTIC: Remove operation path details", [
                'path' => $path,
                'path_type' => gettype($path),
                'is_numeric' => is_numeric($path) ? 'true' : 'false',
                'action' => 'Remove',
                'operation_type' => 'remove'
            ]);
            
            // If path is numeric, it might be an ID - check if it exists as an ID
            if (is_numeric($path)) {
                $this->logger->debug("DIAGNOSTIC: Path is numeric, checking if it exists as an ID", [
                    'path' => $path,
                    'numeric_value' => intval($path)
                ]);
                
                $idResult = $this->db->query(
                    "SELECT * FROM protected_items WHERE id = :id",
                    [':id' => intval($path)]
                );
                $idItem = $this->db->fetchOne($idResult);
                
                if ($idItem) {
                    $this->logger->debug("DIAGNOSTIC: Found item by ID", [
                        'id' => intval($path),
                        'actual_path' => $idItem['path']
                    ]);
                }
            }
            
            // Get protected item
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
            
            $this->logger->debug("DIAGNOSTIC: Result of path-based query", [
                'path' => $path,
                'found_item' => $item ? 'true' : 'false',
                'item_id' => $item ? $item['id'] : 'none'
            ]);
            
            if (!$item) {
                $this->logger->error("DIAGNOSTIC: Item not found with path", [
                    'path' => $path,
                    'path_type' => gettype($path),
                    'is_numeric' => is_numeric($path) ? 'true' : 'false'
                ]);
                throw ApiException::notFound("Item not found in protected items: $path");
            }
            
            // Store item ID for later verification
            $itemId = $item['id'];
            
            // Remove par2 files
            $par2Path = $item['par2_path'];
            $par2Dir = dirname($par2Path);

            // Special handling for "Individual Files" mode where par2_path is a directory
            if (strpos($item['mode'], 'Individual Files') === 0 && is_dir($par2Path)) {
                $this->logger->debug("Individual Files mode detected, par2_path is a directory", [
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'mode' => $item['mode']
                ]);
                
                // In this case, par2_dir should be the par2_path itself
                $par2Dir = $par2Path;
                
                // Find all .par2 files in the parity directory
                $par2Files = glob($par2Dir . '/*.par2');
                
                $this->logger->debug("DIAGNOSTIC: Files found for removal in parity directory", [
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'par2_dir' => $par2Dir,
                    'glob_pattern' => $par2Dir . '/*.par2',
                    'files_found' => $par2Files,
                    'files_count' => count($par2Files)
                ]);
                
                // Delete all .par2 files in the parity directory
                foreach ($par2Files as $file) {
                    $fileExt = pathinfo($file, PATHINFO_EXTENSION);
                    $this->logger->debug("DIAGNOSTIC: Processing file for removal", [
                        'file' => $file,
                        'extension' => $fileExt,
                        'is_par2' => ($fileExt === 'par2'),
                        'exists' => file_exists($file)
                    ]);
                    
                    // Double-check that we're only deleting .par2 files for safety
                    if (file_exists($file) && $fileExt === 'par2') {
                        @unlink($file);
                    } else if (file_exists($file) && $fileExt !== 'par2') {
                        $this->logger->warning("SAFETY: Prevented deletion of non-par2 file", [
                            'file' => $file,
                            'extension' => $fileExt
                        ]);
                    }
                }
                
                // Remove file lists if they exist
                $fileLists = glob($par2Dir . '/*.filelist');
                foreach ($fileLists as $fileList) {
                    if (file_exists($fileList)) {
                        @unlink($fileList);
                    }
                }
            } else {
                // Standard mode - par2_path is a file
                // Remove .par2 files
                $par2Base = pathinfo($par2Path, PATHINFO_FILENAME);
                // Only match files with .par2 extension to prevent accidental deletion of user data
                $par2Files = glob($par2Dir . '/' . $par2Base . '*.par2');
                
                // Log the glob pattern and files found for debugging
                $this->logger->debug("DIAGNOSTIC: Files found for removal", [
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'par2_dir' => $par2Dir,
                    'par2_base' => $par2Base,
                    'glob_pattern' => $par2Dir . '/' . $par2Base . '*.par2',
                    'files_found' => $par2Files,
                    'files_count' => count($par2Files)
                ]);
                
                foreach ($par2Files as $file) {
                    $fileExt = pathinfo($file, PATHINFO_EXTENSION);
                    $this->logger->debug("DIAGNOSTIC: Processing file for removal", [
                        'file' => $file,
                        'extension' => $fileExt,
                        'is_par2' => ($fileExt === 'par2'),
                        'exists' => file_exists($file)
                    ]);
                    
                    // Double-check that we're only deleting .par2 files for safety
                    if (file_exists($file) && $fileExt === 'par2') {
                        @unlink($file);
                    } else if (file_exists($file) && $fileExt !== 'par2') {
                        $this->logger->warning("SAFETY: Prevented deletion of non-par2 file", [
                            'file' => $file,
                            'extension' => $fileExt
                        ]);
                    }
                }
                
                // Remove file list if it exists
                $fileList = $par2Dir . '/' . basename($path) . '.filelist';
                if (file_exists($fileList)) {
                    @unlink($fileList);
                }
            }
            
            // Check if .parity directory is empty and remove it if it is
            if (is_dir($par2Dir)) {
                $parityDirContents = scandir($par2Dir);
                
                // Log the directory contents for debugging
                $this->logger->debug("Parity directory contents", [
                    'directory' => $par2Dir,
                    'contents' => $parityDirContents,
                    'count' => count($parityDirContents)
                ]);
                
                // If directory only contains . and .. entries, it's empty
                if (count($parityDirContents) <= 2) {
                    $this->logger->debug("Removing empty parity directory", [
                        'directory' => $par2Dir
                    ]);
                    $rmResult = @rmdir($par2Dir);
                    $this->logger->debug("Directory removal result", [
                        'directory' => $par2Dir,
                        'success' => $rmResult,
                        'error' => $rmResult ? null : error_get_last()
                    ]);
                } else {
                    $this->logger->debug("Parity directory not empty, skipping removal", [
                        'directory' => $par2Dir,
                        'remaining_files' => count($parityDirContents) - 2,
                        'file_list' => array_diff($parityDirContents, ['.', '..'])
                    ]);
                }
            }
            
            // Begin transaction for database operations
            $this->logger->debug("Starting transaction for database removal", [
                'item_id' => $itemId,
                'path' => $path
            ]);
            
            try {
                // Begin transaction
                $this->db->beginTransaction();
                
                // First check if the item still exists
                $checkResult = $this->db->query(
                    "SELECT COUNT(*) as count FROM protected_items WHERE id = :id",
                    [':id' => $itemId]
                );
                $initialCount = $this->db->fetchOne($checkResult)['count'];
                
                if ($initialCount == 0) {
                    // Item doesn't exist, rollback transaction and return success
                    $this->db->rollback();
                    
                    $this->logger->warning("Item already removed from database", [
                        'item_id' => $itemId,
                        'path' => $path,
                        'action' => 'Remove',
                        'operation_type' => 'remove'
                    ]);
                    
                    return [
                        'success' => true,
                        'message' => 'Protection already removed'
                    ];
                }
                
                // Remove file metadata first (due to foreign key constraints)
                $this->db->query(
                    "DELETE FROM file_metadata WHERE protected_item_id = :id",
                    [':id' => $itemId]
                );
                
                // Remove verification history
                $this->db->query(
                    "DELETE FROM verification_history WHERE protected_item_id = :id",
                    [':id' => $itemId]
                );
                
                // Remove protected item
                $this->db->query(
                    "DELETE FROM protected_items WHERE id = :id",
                    [':id' => $itemId]
                );
                
                // Verify the item was removed
                $result = $this->db->query(
                    "SELECT COUNT(*) as count FROM protected_items WHERE id = :id",
                    [':id' => $itemId]
                );
                $count = $this->db->fetchOne($result)['count'];
                
                if ($count == 0) {
                    // Commit transaction
                    $this->db->commit();
                    
                    // Clear cache for protected items
                    $this->clearProtectedItemsCache();
                    
                    $this->logger->debug("Protection removed successfully from database", [
                        'item_id' => $itemId,
                        'path' => $path
                    ]);
                } else {
                    // Rollback transaction if item wasn't removed
                    $this->db->rollback();
                    
                    $this->logger->error("CRITICAL: Failed to remove item from database", [
                        'item_id' => $itemId,
                        'path' => $path,
                        'action' => 'Remove',
                        'operation_type' => 'remove',
                        'status' => 'Failed'
                    ]);
                    
                    throw new \Exception("Failed to remove item from database");
                }
            } catch (\Exception $e) {
                // Rollback transaction on error
                if ($this->db->inTransaction) {
                    $this->db->rollback();
                }
                
                $this->logger->error("Transaction failed during remove operation", [
                    'item_id' => $itemId,
                    'path' => $path,
                    'error' => $e->getMessage()
                ]);
                
                throw $e;
            }
            
            $this->logger->info("Remove operation completed", [
                'path' => $path,
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Success'
            ]);
            
            return [
                'success' => true,
                'message' => 'Protection removed successfully'
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protection", [
                'path' => $path,
                'error' => $e->getMessage(),
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Failed to remove protection: " . $e->getMessage());
        }
    }
    
    /**
     * Remove protection by ID
     *
     * @param int $id Protected item ID
     * @return array
     */
    public function removeById($id) {
        $this->logger->debug("Removing protection by ID", [
            'id' => $id,
            'action' => 'Remove',
            'operation_type' => 'remove'
        ]);
        
        try {
            // Get protected item by ID
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Store path for logging and file operations
            $path = $item['path'];
            
            // Remove par2 files
            $par2Path = $item['par2_path'];
            $par2Dir = dirname($par2Path);

            // Special handling for "Individual Files" mode where par2_path is a directory
            if (strpos($item['mode'], 'Individual Files') === 0 && is_dir($par2Path)) {
                $this->logger->debug("Individual Files mode detected, par2_path is a directory", [
                    'id' => $id,
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'mode' => $item['mode']
                ]);
                
                // In this case, par2_dir should be the par2_path itself
                $par2Dir = $par2Path;
                
                // Find all .par2 files in the parity directory
                $par2Files = glob($par2Dir . '/*.par2');
                
                $this->logger->debug("DIAGNOSTIC: Files found for removal in parity directory", [
                    'id' => $id,
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'par2_dir' => $par2Dir,
                    'glob_pattern' => $par2Dir . '/*.par2',
                    'files_found' => $par2Files,
                    'files_count' => count($par2Files)
                ]);
                
                // Delete all .par2 files in the parity directory
                foreach ($par2Files as $file) {
                    $fileExt = pathinfo($file, PATHINFO_EXTENSION);
                    $this->logger->debug("DIAGNOSTIC: Processing file for removal", [
                        'file' => $file,
                        'extension' => $fileExt,
                        'is_par2' => ($fileExt === 'par2'),
                        'exists' => file_exists($file)
                    ]);
                    
                    // Double-check that we're only deleting .par2 files for safety
                    if (file_exists($file) && $fileExt === 'par2') {
                        @unlink($file);
                    } else if (file_exists($file) && $fileExt !== 'par2') {
                        $this->logger->warning("SAFETY: Prevented deletion of non-par2 file", [
                            'file' => $file,
                            'extension' => $fileExt
                        ]);
                    }
                }
                
                // Remove file lists if they exist
                $fileLists = glob($par2Dir . '/*.filelist');
                foreach ($fileLists as $fileList) {
                    if (file_exists($fileList)) {
                        @unlink($fileList);
                    }
                }
            } else {
                // Standard mode - par2_path is a file
                // Remove .par2 files
                $par2Base = pathinfo($par2Path, PATHINFO_FILENAME);
                // Only match files with .par2 extension to prevent accidental deletion of user data
                $par2Files = glob($par2Dir . '/' . $par2Base . '*.par2');
                
                // Log the glob pattern and files found for debugging
                $this->logger->debug("DIAGNOSTIC: Files found for removal", [
                    'id' => $id,
                    'path' => $path,
                    'par2_path' => $par2Path,
                    'par2_dir' => $par2Dir,
                    'par2_base' => $par2Base,
                    'glob_pattern' => $par2Dir . '/' . $par2Base . '*.par2',
                    'files_found' => $par2Files,
                    'files_count' => count($par2Files)
                ]);
                
                foreach ($par2Files as $file) {
                    $fileExt = pathinfo($file, PATHINFO_EXTENSION);
                    $this->logger->debug("DIAGNOSTIC: Processing file for removal", [
                        'file' => $file,
                        'extension' => $fileExt,
                        'is_par2' => ($fileExt === 'par2'),
                        'exists' => file_exists($file)
                    ]);
                    
                    // Double-check that we're only deleting .par2 files for safety
                    if (file_exists($file) && $fileExt === 'par2') {
                        @unlink($file);
                    } else if (file_exists($file) && $fileExt !== 'par2') {
                        $this->logger->warning("SAFETY: Prevented deletion of non-par2 file", [
                            'file' => $file,
                            'extension' => $fileExt
                        ]);
                    }
                }
                
                // Remove file list if it exists
                $fileList = $par2Dir . '/' . basename($path) . '.filelist';
                if (file_exists($fileList)) {
                    @unlink($fileList);
                }
            }
            
            // Check if .parity directory is empty and remove it if it is
            if (is_dir($par2Dir)) {
                $parityDirContents = scandir($par2Dir);
                
                // Log the directory contents for debugging
                $this->logger->debug("Parity directory contents", [
                    'directory' => $par2Dir,
                    'contents' => $parityDirContents,
                    'count' => count($parityDirContents)
                ]);
                
                // If directory only contains . and .. entries, it's empty
                if (count($parityDirContents) <= 2) {
                    $this->logger->debug("Removing empty parity directory", [
                        'directory' => $par2Dir
                    ]);
                    $rmResult = @rmdir($par2Dir);
                    $this->logger->debug("Directory removal result", [
                        'directory' => $par2Dir,
                        'success' => $rmResult,
                        'error' => $rmResult ? null : error_get_last()
                    ]);
                } else {
                    $this->logger->debug("Parity directory not empty, skipping removal", [
                        'directory' => $par2Dir,
                        'remaining_files' => count($parityDirContents) - 2,
                        'file_list' => array_diff($parityDirContents, ['.', '..'])
                    ]);
                }
            }
            
            // Begin transaction for database operations
            $this->logger->debug("Starting transaction for database removal by ID", [
                'item_id' => $id,
                'path' => $path
            ]);
            
            try {
                // Begin transaction
                $this->db->beginTransaction();
                
                // First check if the item still exists
                $checkResult = $this->db->query(
                    "SELECT COUNT(*) as count FROM protected_items WHERE id = :id",
                    [':id' => $id]
                );
                $initialCount = $this->db->fetchOne($checkResult)['count'];
                
                if ($initialCount == 0) {
                    // Item doesn't exist, rollback transaction and return success
                    $this->db->rollback();
                    
                    $this->logger->warning("Item already removed from database", [
                        'item_id' => $id,
                        'path' => $path,
                        'action' => 'Remove',
                        'operation_type' => 'remove'
                    ]);
                    
                    return [
                        'success' => true,
                        'message' => 'Protection already removed'
                    ];
                }
                
                // Remove file metadata first (due to foreign key constraints)
                $this->db->query(
                    "DELETE FROM file_metadata WHERE protected_item_id = :id",
                    [':id' => $id]
                );
                
                // Remove verification history
                $this->db->query(
                    "DELETE FROM verification_history WHERE protected_item_id = :id",
                    [':id' => $id]
                );
                
                // Remove protected item
                $this->db->query(
                    "DELETE FROM protected_items WHERE id = :id",
                    [':id' => $id]
                );
                
                // Verify the item was removed
                $result = $this->db->query(
                    "SELECT COUNT(*) as count FROM protected_items WHERE id = :id",
                    [':id' => $id]
                );
                $count = $this->db->fetchOne($result)['count'];
                
                if ($count == 0) {
                    // Commit transaction
                    $this->db->commit();
                    
                    // Clear cache for protected items
                    $this->clearProtectedItemsCache();
                    
                    $this->logger->debug("Protection removed successfully from database by ID", [
                        'item_id' => $id,
                        'path' => $path
                    ]);
                } else {
                    // Rollback transaction if item wasn't removed
                    $this->db->rollback();
                    
                    $this->logger->error("CRITICAL: Failed to remove item from database by ID", [
                        'item_id' => $id,
                        'path' => $path,
                        'action' => 'Remove',
                        'operation_type' => 'remove',
                        'status' => 'Failed'
                    ]);
                    
                    throw new \Exception("Failed to remove item from database");
                }
            } catch (\Exception $e) {
                // Rollback transaction on error
                if ($this->db->inTransaction) {
                    $this->db->rollback();
                }
                
                $this->logger->error("Transaction failed during removeById operation", [
                    'item_id' => $id,
                    'path' => $path,
                    'error' => $e->getMessage()
                ]);
                
                throw $e;
            }
            
            $this->logger->info("Remove operation completed by ID", [
                'id' => $id,
                'path' => $path,
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Success'
            ]);
            
            return [
                'success' => true,
                'message' => 'Protection removed successfully'
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protection by ID", [
                'id' => $id,
                'error' => $e->getMessage(),
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Failed to remove protection: " . $e->getMessage());
        }
    }
    
    /**
     * Get redundancy levels for multiple paths
     *
     * @param array $paths Array of paths to get redundancy levels for
     * @return array Associative array of path => redundancy
     */
    public function getRedundancyLevels($paths) {
        $result = [];
        
        // Check cache first
        $cacheKey = 'redundancy_levels_' . md5(json_encode($paths));
        if ($this->cache->has($cacheKey)) {
            $this->logger->debug("Using cached redundancy levels");
            return $this->cache->get($cacheKey);
        }
        
        foreach ($paths as $path) {
            $query = $this->db->query(
                "SELECT redundancy FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($query);
            
            if ($item) {
                $result[$path] = $item['redundancy'];
            } else {
                $result[$path] = null;
            }
        }
        
        // Cache the result for 10 minutes
        $this->cache->set($cacheKey, $result, 600);
        
        return $result;
    }
    
    /**
     * Get redundancy level for a single path
     *
     * @param string $path Path to get redundancy level for
     * @return int|null Redundancy level or null if not found
     */
    public function getRedundancyLevel($path) {
        // Check cache first
        $cacheKey = 'redundancy_level_' . md5($path);
        if ($this->cache->has($cacheKey)) {
            $this->logger->debug("Using cached redundancy level for path");
            return $this->cache->get($cacheKey);
        }
        
        try {
            $query = $this->db->query(
                "SELECT redundancy FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($query);
            $result = $item ? $item['redundancy'] : null;
            
            // Cache the result for 10 minutes
            $this->cache->set($cacheKey, $result, 600);
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get redundancy level", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Clear all protected items related cache
     */
    private function clearProtectedItemsCache() {
        $this->logger->debug("Clearing protected items cache");
        
        // Clear all cache keys related to protected items
        $this->cache->remove('protected_items_all');
        
        // We can't easily clear specific cache keys for individual items,
        // so we'll just clean all expired entries and let the TTL handle the rest
        $this->cache->cleanExpired();
    }
    
    /**
     * Get size of path (file or directory)
     *
     * @param string $path Path to get size for
     * @return int Size in bytes
     */
    private function getPathSize($path) {
        if (is_file($path)) {
            return filesize($path);
        } else if (is_dir($path)) {
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
            
            return $size;
        }
        
        return 0;
    }
    
    /**
     * Get size of protected data (considering file type filtering)
     *
     * @param string $path Path to get size for
     * @param array $fileTypes File types to filter by
     * @param array $protectedFiles List of protected files (for individual files mode)
     * @return int Size in bytes
     */
    private function getDataSize($path, $fileTypes = null, $protectedFiles = null) {
        // If we have a list of protected files, calculate their total size
        if ($protectedFiles && is_array($protectedFiles)) {
            $size = 0;
            foreach ($protectedFiles as $filePath) {
                if (file_exists($filePath) && is_file($filePath)) {
                    $size += filesize($filePath);
                }
            }
            return $size;
        }
        
        // If it's a file, just return its size
        if (is_file($path)) {
            return filesize($path);
        }
        
        // If it's a directory with file type filtering
        if (is_dir($path) && $fileTypes && !empty($fileTypes)) {
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    // Skip files in parity directory
                    $parityDir = $this->config->get('protection.parity_dir', '.parity');
                    if (strpos($file->getPathname(), "/$parityDir/") !== false) {
                        continue;
                    }
                    
                    // Filter by file type
                    $extension = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
                    if (in_array($extension, $fileTypes)) {
                        $size += $file->getSize();
                    }
                }
            }
            
            return $size;
        }
        
        // For a directory without filtering, return the total size
        return $this->getPathSize($path);
    }
    
    /**
     * Get size of PAR2 protection files
     *
     * @param string $par2Path Path to PAR2 file or directory
     * @return int Size in bytes
     */
    private function getPar2Size($par2Path) {
        $size = 0;
        
        // If it's a directory, sum up all .par2 files in it
        if (is_dir($par2Path)) {
            $par2Files = glob($par2Path . '/*.par2');
            foreach ($par2Files as $file) {
                if (file_exists($file) && is_file($file)) {
                    $size += filesize($file);
                }
            }
        } 
        // If it's a file, get the size of all related .par2 files
        else if (is_file($par2Path) || file_exists($par2Path)) {
            $par2Dir = dirname($par2Path);
            $par2Base = pathinfo($par2Path, PATHINFO_FILENAME);
            $par2Files = glob($par2Dir . '/' . $par2Base . '*.par2');
            
            foreach ($par2Files as $file) {
                if (file_exists($file) && is_file($file)) {
                    $size += filesize($file);
                }
            }
        }
        
        return $size;
    }
    
    /**
     * Collect and store metadata for a file or directory
     *
     * @param string $path Path to collect metadata for
     * @param string $mode Protection mode (file or directory)
     * @param int $protectedItemId Protected item ID
     * @return bool
     */
    private function collectAndStoreMetadata($path, $mode, $protectedItemId) {
        $this->logger->debug("Collecting metadata for path", [
            'path' => $path,
            'mode' => $mode,
            'protected_item_id' => $protectedItemId
        ]);
        
        try {
            if ($mode === 'directory' || strpos($mode, 'Individual Files') === 0) {
                // For directories, we need to collect metadata for all files
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $filePath = $file->getPathname();
                        
                        // Skip files in parity directory
                        $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
                        // Skip both standard parity directory and category-specific parity directories
                        if (strpos($filePath, "/$parityDirBase/") !== false || strpos($filePath, "/$parityDirBase-") !== false) {
                            continue;
                        }
                        
                        // Collect and store metadata for this file
                        $this->collectAndStoreFileMetadata($filePath, $protectedItemId);
                    }
                }
                
                return true;
            } else if ($mode === 'file') {
                // For individual files, just collect metadata for the file
                return $this->collectAndStoreFileMetadata($path, $protectedItemId);
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Failed to collect metadata", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Collect and store metadata for a single file
     *
     * @param string $filePath Path to the file
     * @param int $protectedItemId Protected item ID
     * @return bool
     */
    private function collectAndStoreFileMetadata($filePath, $protectedItemId) {
        try {
            // Get file metadata
            $metadata = $this->getFileMetadata($filePath);
            
            if (!$metadata) {
                $this->logger->warning("Failed to get metadata for file", [
                    'file_path' => $filePath
                ]);
                return false;
            }
            
            // Store metadata in database
            $this->db->query(
                "INSERT OR REPLACE INTO file_metadata
                (protected_item_id, file_path, owner, group_name, permissions, extended_attributes)
                VALUES (:protected_item_id, :file_path, :owner, :group_name, :permissions, :extended_attributes)",
                [
                    ':protected_item_id' => $protectedItemId,
                    ':file_path' => $filePath,
                    ':owner' => $metadata['owner'],
                    ':group_name' => $metadata['group'],
                    ':permissions' => $metadata['permissions'],
                    ':extended_attributes' => $metadata['extended_attributes'] ? json_encode($metadata['extended_attributes']) : null
                ]
            );
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to store file metadata", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get metadata for a file
     *
     * @param string $filePath Path to the file
     * @return array|false
     */
    private function getFileMetadata($filePath) {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return false;
        }
        
        try {
            // Use stat command to get owner, group, and permissions
            $statCommand = "stat -c '%U:%G:%a' " . escapeshellarg($filePath);
            exec($statCommand, $statOutput, $statReturnCode);
            
            if ($statReturnCode !== 0 || empty($statOutput)) {
                $this->logger->warning("Failed to get file stat information", [
                    'file_path' => $filePath,
                    'command' => $statCommand,
                    'return_code' => $statReturnCode
                ]);
                return false;
            }
            
            // Parse stat output (format: owner:group:permissions)
            $statParts = explode(':', $statOutput[0]);
            if (count($statParts) !== 3) {
                $this->logger->warning("Invalid stat output format", [
                    'file_path' => $filePath,
                    'output' => $statOutput[0]
                ]);
                return false;
            }
            
            $owner = $statParts[0];
            $group = $statParts[1];
            $permissions = $statParts[2];
            
            // Use getfattr to get extended attributes if available
            $extendedAttributes = null;
            $getfattrCommand = "getfattr -d --absolute-names " . escapeshellarg($filePath) . " 2>/dev/null";
            exec($getfattrCommand, $getfattrOutput, $getfattrReturnCode);
            
            // Parse getfattr output if successful
            if ($getfattrReturnCode === 0 && !empty($getfattrOutput)) {
                $attributes = [];
                foreach ($getfattrOutput as $line) {
                    // Skip the first line (filename) and empty lines
                    if (strpos($line, '# file:') === 0 || empty(trim($line))) {
                        continue;
                    }
                    
                    // Parse attribute name and value
                    if (preg_match('/^([^=]+)="(.*)"$/', $line, $matches)) {
                        $attrName = $matches[1];
                        $attrValue = $matches[2];
                        $attributes[$attrName] = $attrValue;
                    }
                }
                
                if (!empty($attributes)) {
                    $extendedAttributes = $attributes;
                }
            }
            
            return [
                'owner' => $owner,
                'group' => $group,
                'permissions' => $permissions,
                'extended_attributes' => $extendedAttributes
            ];
        } catch (\Exception $e) {
            $this->logger->error("Exception while getting file metadata", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}