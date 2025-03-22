<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Protection\Helpers\FormatHelper;

/**
 * Main Protection service class
 */
class Protection {
    private $repository;      // ProtectionRepository instance
    private $operations;      // ProtectionOperations instance
    private $metadataManager; // MetadataManager instance
    private $formatHelper;    // FormatHelper instance
    private $logger;          // Logger instance
    private $config;          // Config instance
    private $cache;           // Cache instance
    
    /**
     * Protection service constructor
     */
    public function __construct() {
        // Initialize core dependencies
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        $this->cache = Cache::getInstance();
        $db = Database::getInstance();
        
        // Initialize helper classes
        $this->formatHelper = new FormatHelper();
        $this->metadataManager = new MetadataManager($db, $this->logger);
        $this->repository = new ProtectionRepository($db, $this->logger, $this->cache);
        $this->operations = new ProtectionOperations($this->logger, $this->config, $this->formatHelper);
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
            
            // Get all items from repository
            $items = $this->repository->findAllProtectedItems();
            
            // Format items for API response
            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = $this->formatHelper->formatProtectedItem($item);
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
            
            // Get individual files from repository
            $items = $this->repository->findIndividualFiles($parentDir);
            
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
                $formattedItems[] = $this->formatHelper->formatProtectedItem($item);
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
            
            // Get item from repository
            $item = $this->repository->findByPath($path);
            
            if (!$item) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            
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
            
            // Get item from repository
            $item = $this->repository->findById($id);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            $this->logger->debug("DIAGNOSTIC: Found item by ID", [
                'id' => $id,
                'path' => $item['path'],
                'mode' => $item['mode']
            ]);
            
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            
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
            
            // Get item from repository
            $item = $this->repository->findWithFileTypes($path, $fileTypes);
            
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
            
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            
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
                $result = $this->operations->protectIndividualFiles($path, $redundancy, $fileTypes, $fileCategories, $advancedSettings);
                
                if (!$result['success'] && !isset($result['skipped'])) {
                    throw new ApiException($result['error']);
                }
                
                if (isset($result['skipped']) && $result['skipped']) {
                    $this->logger->info("Individual files protection skipped", [
                        'path' => $path,
                        'reason' => $result['error']
                    ]);
                    
                    return [
                        'success' => false,
                        'skipped' => true,
                        'message' => $result['error']
                    ];
                }
                
                // Add protected item to database
                $protectedItemId = $this->repository->addProtectedItem(
                    $path,
                    'Individual Files (' . implode(', ', $fileTypes) . ')',
                    $redundancy,
                    $result['par2_path'],
                    $fileTypes,
                    null,
                    $result['protected_files'],
                    $fileCategories
                );
                
                // Collect and store metadata
                $this->metadataManager->collectAndStoreMetadata($path, $mode, $protectedItemId);
                
                // Update size information
                $this->updateSizeInformation($protectedItemId, $path, $fileTypes, $result['protected_files'], $result['par2_path']);
                
                $this->logger->info("Individual files protection completed", [
                    'path' => $path,
                    'protected_files_count' => count($result['protected_files']),
                    'action' => 'Protect',
                    'operation_type' => 'protect',
                    'status' => 'Success'
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Individual files protected successfully',
                    'protected_files_count' => count($result['protected_files']),
                    'failed_files_count' => count($result['failed_files'] ?? [])
                ];
            } else {
                // For regular files or directories without file types, protect directly
                $result = $this->operations->createPar2Files($path, $redundancy, $mode, $fileTypes, $fileCategories, null, $advancedSettings);
                
                if (!$result['success']) {
                    throw new ApiException($result['error']);
                }
                
                // Add protected item to database
                $protectedItemId = $this->repository->addProtectedItem(
                    $path,
                    $mode,
                    $redundancy,
                    $result['par2_path'],
                    $fileTypes,
                    null,
                    null,
                    $fileCategories
                );
                
                // Collect and store metadata
                $this->metadataManager->collectAndStoreMetadata($path, $mode, $protectedItemId);
                
                // Update size information
                $this->updateSizeInformation($protectedItemId, $path, $fileTypes, null, $result['par2_path']);
                
                $this->logger->info("Protection completed", [
                    'path' => $path,
                    'mode' => $mode,
                    'action' => 'Protect',
                    'operation_type' => 'protect',
                    'status' => 'Success'
                ]);
                
                return [
                    'success' => true,
                    'message' => $mode === 'file' ? 'File protected successfully' : 'Directory protected successfully'
                ];
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Protection operation failed", [
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
     * Update size information for a protected item
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Path to the item
     * @param array $fileTypes File types to filter by
     * @param array $protectedFiles List of protected files
     * @param string $par2Path Path to PAR2 files
     * @return void
     */
    private function updateSizeInformation($protectedItemId, $path, $fileTypes = null, $protectedFiles = null, $par2Path = null) {
        try {
            // Get data size
            $dataSize = $this->metadataManager->getDataSize($path, $fileTypes, $protectedFiles);
            
            // Get PAR2 size
            $par2Size = $this->metadataManager->getPar2Size($par2Path);
            
            // Update database
            $this->db->query(
                "UPDATE protected_items SET 
                size = :size, 
                par2_size = :par2_size, 
                data_size = :data_size 
                WHERE id = :id",
                [
                    ':id' => $protectedItemId,
                    ':size' => $dataSize + $par2Size, // Total size
                    ':par2_size' => $par2Size,
                    ':data_size' => $dataSize
                ]
            );
            
            $this->logger->debug("Updated size information", [
                'id' => $protectedItemId,
                'data_size' => $dataSize,
                'par2_size' => $par2Size,
                'total_size' => $dataSize + $par2Size
            ]);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to update size information", [
                'id' => $protectedItemId,
                'error' => $e->getMessage()
            ]);
            // Don't throw exception, just log warning
        }
    }
    
    /**
     * Remove protection for a path
     *
     * @param string $path Path to remove protection for
     * @return array
     */
    public function remove($path) {
        $this->logger->debug("Starting remove operation", [
            'operation_id' => uniqid('remove_'),
            'path' => $path,
            'action' => 'Remove',
            'operation_type' => 'remove'
        ]);
        
        try {
            // Get protected item
            $item = $this->repository->findByPath($path);
            
            if (!$item) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            // Check if this is an "Individual Files" mode entry
            $isIndividualFiles = strpos($item['mode'], 'Individual Files') === 0;
            
            // Get PAR2 path
            $par2Path = $item['par2_path'];
            
            // Remove PAR2 files
            if ($par2Path && file_exists($par2Path)) {
                if (is_dir($par2Path)) {
                    // For directory, remove all PAR2 files in it
                    $par2Files = glob("$par2Path/*.par2");
                    foreach ($par2Files as $file) {
                        unlink($file);
                    }
                    
                    // Try to remove directory if empty
                    @rmdir($par2Path);
                } else {
                    // For file, remove the PAR2 file and related files
                    $dir = dirname($par2Path);
                    $baseName = basename($par2Path, '.par2');
                    
                    $relatedFiles = glob("$dir/$baseName*.par2");
                    foreach ($relatedFiles as $file) {
                        unlink($file);
                    }
                }
            }
            
            // Remove from database
            $this->repository->removeItem($item['id']);
            
            // If this is an "Individual Files" mode entry, check if there are any other entries for this directory
            if ($isIndividualFiles) {
                $remainingItems = $this->repository->findIndividualFiles($path);
                
                if (count($remainingItems) === 0) {
                    $this->logger->debug("No more individual files entries for this directory", [
                        'path' => $path
                    ]);
                }
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
            $this->logger->error("Remove operation failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Remove operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Remove protection by ID
     *
     * @param int $id Protected item ID
     * @return array
     */
    public function removeById($id) {
        $this->logger->debug("Starting remove operation by ID", [
            'operation_id' => uniqid('remove_'),
            'id' => $id,
            'action' => 'Remove',
            'operation_type' => 'remove'
        ]);
        
        try {
            // Get protected item
            $item = $this->repository->findById($id);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Check if this is an "Individual Files" mode entry
            $isIndividualFiles = strpos($item['mode'], 'Individual Files') === 0;
            
            // Get PAR2 path
            $par2Path = $item['par2_path'];
            
            // Remove PAR2 files
            if ($par2Path && file_exists($par2Path)) {
                if (is_dir($par2Path)) {
                    // For directory, remove all PAR2 files in it
                    $par2Files = glob("$par2Path/*.par2");
                    foreach ($par2Files as $file) {
                        unlink($file);
                    }
                    
                    // Try to remove directory if empty
                    @rmdir($par2Path);
                } else {
                    // For file, remove the PAR2 file and related files
                    $dir = dirname($par2Path);
                    $baseName = basename($par2Path, '.par2');
                    
                    $relatedFiles = glob("$dir/$baseName*.par2");
                    foreach ($relatedFiles as $file) {
                        unlink($file);
                    }
                }
            }
            
            // Remove from database
            $this->repository->removeItem($id);
            
            // If this is an "Individual Files" mode entry, check if there are any other entries for this directory
            if ($isIndividualFiles) {
                $path = $item['path'];
                $remainingItems = $this->repository->findIndividualFiles($path);
                
                if (count($remainingItems) === 0) {
                    $this->logger->debug("No more individual files entries for this directory", [
                        'path' => $path
                    ]);
                }
            }
            
            $this->logger->info("Remove operation completed by ID", [
                'id' => $id,
                'path' => $item['path'],
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
            $this->logger->error("Remove operation failed by ID", [
                'id' => $id,
                'error' => $e->getMessage(),
                'action' => 'Remove',
                'operation_type' => 'remove',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Remove operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get redundancy levels for multiple paths
     *
     * @param array $paths Paths to check
     * @return array
     */
    public function getRedundancyLevels($paths) {
        $result = [];
        
        foreach ($paths as $path) {
            try {
                $redundancy = $this->getRedundancyLevel($path);
                $result[$path] = $redundancy;
            } catch (\Exception $e) {
                $result[$path] = null;
            }
        }
        
        return $result;
    }
    
    /**
     * Get redundancy level for a path
     *
     * @param string $path Path to check
     * @return int|null
     */
    public function getRedundancyLevel($path) {
        try {
            // Get protected item
            $item = $this->repository->findByPath($path);
            
            if (!$item) {
                return null;
            }
            
            return $item['redundancy'];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get redundancy level", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}