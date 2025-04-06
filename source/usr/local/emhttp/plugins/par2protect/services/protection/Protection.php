<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Protection\Helpers\FormatHelper;
use Par2Protect\Core\MetadataManager; // Use the Core manager

/**
 * Main Protection service class
 */
class Protection {
    private ProtectionRepository $repository;
    private ProtectionOperations $operations;
    private MetadataManager $metadataManager; // Use the Core manager
    private FormatHelper $formatHelper;
    private Logger $logger;
    private Config $config;
    private Cache $cache;
    private Database $db;

    /**
     * Protection service constructor
     */
    public function __construct(
        Logger $logger,
        Config $config,
        Cache $cache,
        Database $db,
        ProtectionRepository $repository,
        ProtectionOperations $operations,
        MetadataManager $metadataManager,
        FormatHelper $formatHelper
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->cache = $cache;
        $this->db = $db;
        $this->repository = $repository;
        $this->operations = $operations;
        $this->metadataManager = $metadataManager;
        $this->formatHelper = $formatHelper;
    }

    /**
     * Get all protected items
     *
     * @param bool $forceRefresh Force refresh from database instead of cache
     * @return array
     */
    public function getAllProtectedItems($forceRefresh = false) {
        try {
            $cacheKey = 'protected_items_all';
            if (!$forceRefresh && $this->cache->has($cacheKey)) {
                $this->logger->debug("Cache hit for protected items", ['key' => $cacheKey, 'force_refresh' => $forceRefresh]);
                return $this->cache->get($cacheKey);
            }
            $this->logger->debug("Cache miss or force refresh for protected items", ['key' => $cacheKey, 'force_refresh' => $forceRefresh, 'has_cache' => $this->cache->has($cacheKey) ? 'true' : 'false']);

            $items = $this->repository->findAllProtectedItems();
            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = $this->formatHelper->formatProtectedItem($item);
            }
            $this->cache->set($cacheKey, $formattedItems, 300); // Cache for 5 minutes
            return $formattedItems;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get protected items", ['error' => $e->getMessage()]);
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
            $cacheKey = 'individual_files_' . md5($parentDir);
            if ($this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }
            $items = $this->repository->findIndividualFiles($parentDir);
            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = $this->formatHelper->formatProtectedItem($item);
            }
            $this->cache->set($cacheKey, $formattedItems, 300);
            return $formattedItems;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get individual files", ['parent_dir' => $parentDir, 'error' => $e->getMessage()]);
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
            $cacheKey = 'protected_item_status_' . md5($path);
            if ($this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }
            $item = $this->repository->findByPath($path);
            if (!$item) { throw ApiException::notFound("Item not found: $path"); }
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            $this->cache->set($cacheKey, $formattedItem, 60);
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get status", ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to get status: " . $e->getMessage());
        }
    }

    /**
     * Get status of protected item by ID
     *
     * @param int $id Protected item ID
     * @return array
     */
    public function getStatusById($id) {
        try {
            $cacheKey = 'protected_item_status_id_' . $id;
            if ($this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }
            $item = $this->repository->findById($id);
            if (!$item) { throw ApiException::notFound("Item not found with ID: $id"); }
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            $this->cache->set($cacheKey, $formattedItem, 60);
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get status by ID", ['id' => $id, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to get status: " . $e->getMessage());
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
            $cacheKey = 'protected_item_status_types_' . md5($path . json_encode($fileTypes));
            if ($this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }
            $item = $this->repository->findWithFileTypes($path, $fileTypes);
            if (!$item) { throw ApiException::notFound("Item not found with specified file types: $path"); }
            $formattedItem = $this->formatHelper->formatProtectedItem($item);
            $this->cache->set($cacheKey, $formattedItem, 60);
            return $formattedItem;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get status with file types", ['path' => $path, 'file_types' => $fileTypes, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to get status with file types: " . $e->getMessage());
        }
    }

    /**
     * Protect a file or directory
     *
     * @param string $path Path to protect
     * @param int|null $redundancy Redundancy percentage (null uses default)
     * @param array|null $fileTypes File types to protect (for directory mode)
     * @param array|null $fileCategories File categories (for individual files mode)
     * @param array|null $advancedSettings Advanced par2 settings
     * @return array
     */
    public function protect($path, $redundancy = null, $fileTypes = null, $fileCategories = null, $advancedSettings = null) {
        $operationId = uniqid('protect_');
        $this->logger->debug("Starting protection operation", [
            'operation_id' => $operationId, 'path' => $path, 'redundancy' => $redundancy,
            'file_types' => $fileTypes, 'file_categories' => $fileCategories,
            'advanced_settings' => $advancedSettings, 'action' => 'Protect', 'operation_type' => 'protect'
        ]);

        try {
            if (!file_exists($path)) { throw ApiException::badRequest("Path does not exist: $path"); }

            $mode = is_dir($path) ? 'directory' : 'file';
            $isIndividualFilesMode = ($mode === 'directory' && !empty($fileCategories));

            if ($redundancy === null) { $redundancy = $this->config->get('protection.default_redundancy', 10); }

            // Check if item already exists with the same mode/types
            $existingItem = null;
            if ($isIndividualFilesMode) {
                 $existingItem = $this->repository->findWithFileTypes($path, $fileCategories);
            } else {
                 $existingItem = $this->repository->findByPath($path);
                 // Additional check: If found item is 'Individual Files' mode, treat as conflict
                 if ($existingItem && strpos($existingItem['mode'], 'Individual Files') === 0) {
                     $this->logger->warning("Conflict: Trying to protect directory already containing individual file protections", ['path' => $path]);
                     throw new ApiException("Directory contains individually protected files. Remove them first or use 'Individual Files' mode.", 409, 'conflict_individual');
                 }
            }

            if ($existingItem && $existingItem['mode'] === $mode) { // Check mode match too
                $this->logger->warning("Item already protected", ['path' => $path, 'mode' => $existingItem['mode']]);
                // Throw the specific exception so the queue processor can mark it as skipped
                throw new \Par2Protect\Core\Exceptions\Par2FilesExistException("Item is already protected in database: $path");
            }

            // Execute protection operations
            $par2Path = null;
            $protectedFiles = null;

            if ($isIndividualFilesMode) {
                $result = $this->operations->protectIndividualFiles($path, $redundancy, $fileTypes, $fileCategories, $advancedSettings);
                if (!$result['success']) { throw new ApiException("Failed to protect individual files: " . ($result['error'] ?? 'Unknown error')); }
                $protectedFiles = $result['protected_files'];
                $fileCategoryName = implode('-', $fileCategories);
                $parityDirBase = $this->config->get('protection.parity_dir', '.parity');
                $par2Path = rtrim($path, '/') . '/' . $parityDirBase . ($fileCategoryName ? '-' . $fileCategoryName : '');
                $mode = 'Individual Files - ' . $this->formatHelper->getFileCategoryName($fileCategories);
            } else {
                $par2Path = $this->operations->createPar2Files($path, $redundancy, $mode, $fileTypes, null, null, $advancedSettings);
            }

            // Add item to database
            $protectedItemId = $this->repository->addProtectedItem(
                $path, $mode, $redundancy, $par2Path,
                $isIndividualFilesMode ? null : $fileTypes,
                $isIndividualFilesMode ? $path : null,
                $isIndividualFilesMode ? $protectedFiles : null,
                $isIndividualFilesMode ? $fileCategories : null
            );

            // Collect and store metadata
            $this->metadataManager->storeMetadata($path, $mode, $protectedItemId);

            // Update size information
            $this->updateSizeInformation($protectedItemId, $path, $fileTypes, $protectedFiles, $par2Path);

            // Clear cache
            $this->repository->clearProtectedItemsCache();

            $this->logger->info("Protection operation completed", [
                'path' => $path, 'mode' => $mode, 'status' => 'Success',
                'action' => 'Protect', 'operation_type' => 'protect'
            ]);

            return [
                'success' => true, 'message' => 'Protection successful',
                'item_id' => $protectedItemId, 'refresh_list' => true
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Par2Protect\Core\Exceptions\Par2FilesExistException $e) {
            // Re-throw the specific exception so it can be caught by the queue processor
            throw $e;
        } catch (\Exception $e) { // Catch other general exceptions
            $this->logger->error("Protection operation failed", [
                'path' => $path, 'error' => $e->getMessage(), 'action' => 'Protect',
                'operation_type' => 'protect', 'status' => 'Failed'
            ]);
            throw new ApiException("Protection operation failed: " . $e->getMessage());
        }
    }

    /**
     * Update size information for a protected item
     */
    private function updateSizeInformation($protectedItemId, $path, $fileTypes = null, $protectedFiles = null, $par2Path = null) {
        try {
            $dataSize = $this->metadataManager->getDataSize($path, $fileTypes, $protectedFiles);
            $par2Size = $par2Path ? $this->metadataManager->getPar2Size($par2Path) : 0;
            $this->repository->updateSizeInfo($protectedItemId, $dataSize, $par2Size);
            $this->logger->debug("Updated size information", [
                'id' => $protectedItemId, 'data_size' => $dataSize, 'par2_size' => $par2Size, 'total_size' => $dataSize + $par2Size
            ]);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to update size information", ['id' => $protectedItemId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove protection for a file or directory by path
     */
    public function remove($path) {
        try {
            $item = $this->repository->findByPath($path);
            if (!$item) { throw ApiException::notFound("Item not found: $path"); }
            return $this->removeById($item['id']); // Delegate to removeById
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protection by path", ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to remove protection: " . $e->getMessage());
        }
    }

    /**
     * Remove protection for an item by ID
     */
    public function removeById($id) {
        try {
            $item = $this->repository->findById($id);
            if (!$item) { throw ApiException::notFound("Item not found with ID: $id"); }

            $par2Path = $item['par2_path'];
            $mode = $item['mode'];
            $path = $item['path']; // Get original path for logging/events

            // Remove PAR2 files using operations service
            $this->operations->removePar2Files($par2Path, $mode);

            // Remove item from database
            $this->repository->removeItem($id);

            // Clear cache
            $this->repository->clearProtectedItemsCache();

            $this->logger->info("Protection removed", [
                'id' => $id, 'path' => $path, 'mode' => $mode, 'status' => 'Success',
                'action' => 'Remove', 'operation_type' => 'remove'
            ]);

            return ['success' => true, 'message' => 'Protection removed successfully', 'refresh_list' => true];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protection by ID", ['id' => $id, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to remove protection: " . $e->getMessage());
        }
    }

    /**
     * Get redundancy level for a single path
     */
    public function getRedundancyLevel($path) {
        try {
            $item = $this->repository->findByPath($path);
            return $item ? $item['redundancy'] : null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get redundancy level", ['path' => $path, 'error' => $e->getMessage()]);
            return null; // Return null on error
        }
    }

    /**
     * Get redundancy levels for multiple item IDs
     *
     * @param array $itemIds Array of protected item IDs
     * @return array Associative array [id => redundancy]
     */
    public function getRedundancyLevelsByIds(array $itemIds): array {
        if (empty($itemIds)) {
            return [];
        }
        // Call the repository method to fetch by IDs
        return $this->repository->findRedundancyByIds($itemIds);
    }

} // End of class Protection