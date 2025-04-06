<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Protection;
use Par2Protect\Services\Queue;
use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Database;

class ProtectionEndpoint {
    private $protectionService;
    private $queueService;
    private $config;
    private $logger;
    private $db;

    public function __construct(
        Protection $protectionService,
        Queue $queueService,
        Config $config,
        Logger $logger,
        Database $db
    ) {
        $this->protectionService = $protectionService;
        $this->queueService = $queueService;
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db;
    }

    /** Get redundancy levels for multiple item IDs */
    public function getRedundancyLevels($params) {
        try {
            $data = $_POST;
            if (!isset($data['ids'])) { throw ApiException::badRequest("IDs parameter is required"); }
            $ids = null;
            if (is_string($data['ids'])) {
                $ids = json_decode($data['ids'], true);
                if (json_last_error() !== JSON_ERROR_NONE) { throw ApiException::badRequest("Invalid JSON in ids parameter: " . json_last_error_msg()); }
            } else { $ids = $data['ids']; }
            if (!is_array($ids)) { throw ApiException::badRequest("IDs parameter must be an array"); }
            $sanitizedIds = array_map('intval', $ids);
            $sanitizedIds = array_filter($sanitizedIds, function($id) { return $id > 0; });
            if (empty($sanitizedIds)) { throw ApiException::badRequest("No valid IDs provided"); }
            $result = $this->protectionService->getRedundancyLevelsByIds($sanitizedIds);
            Response::success($result);
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to get redundancy levels: " . $e->getMessage()); }
    }

    /** Get redundancy level for a single path */
    public function getRedundancyLevel($params) {
        try {
            if (!isset($params['path'])) { throw ApiException::badRequest("Path parameter is required"); }
            $path = urldecode($params['path']);
            $redundancy = $this->protectionService->getRedundancyLevel($path);
            if ($redundancy === null) { throw ApiException::notFound("Item not found: $path"); }
            Response::success(['redundancy' => $redundancy]);
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to get redundancy level: " . $e->getMessage()); }
    }

    /** Get all protected items */
    public function getAll($params) {
        try {
            $forceRefresh = isset($params['refresh']) && $params['refresh'] === 'true';
            $items = $this->protectionService->getAllProtectedItems($forceRefresh);
            header('Cache-Control: no-cache, no-store, must-revalidate'); header('Pragma: no-cache'); header('Expires: 0');
            Response::success($items);
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to get protected items: " . $e->getMessage()); }
    }

    /** Get status of protected item */
    public function getStatus($params) {
        try {
            if (!isset($params['path'])) { throw ApiException::badRequest("Path parameter is required"); }
            $path = urldecode($params['path']);
            $status = $this->protectionService->getStatus($path);
            Response::success($status);
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to get protection status: " . $e->getMessage()); }
    }

    /** Protect a file or directory */
    public function protect($params) {
        try {
            $data = $_POST;
            if (!isset($data['path']) || empty($data['path'])) { throw ApiException::badRequest("Path parameter is required"); }
            $path = $data['path'];
            $redundancy = isset($data['redundancy']) ? intval($data['redundancy']) : null;
            $fileTypes = isset($data['file_types']) ? $data['file_types'] : null;
            $fileCategories = isset($data['file_categories']) ? $data['file_categories'] : null;
            $advancedSettings = null;
            if (isset($data['advanced_settings'])) { $advancedSettings = $data['advanced_settings']; }
            else if (isset($data['block_count']) || isset($data['block_size']) || isset($data['target_size']) || isset($data['recovery_files'])) {
                $advancedSettings = [
                    'block_count' => isset($data['block_count']) ? $data['block_count'] : null,
                    'block_size' => isset($data['block_size']) ? $data['block_size'] : null,
                    'target_size' => isset($data['target_size']) ? $data['target_size'] : null,
                    'recovery_files' => isset($data['recovery_files']) ? $data['recovery_files'] : null
                ];
            }
            $queueParams = ['path' => $path, 'redundancy' => $redundancy, 'file_types' => $fileTypes, 'file_categories' => $fileCategories];
            if ($advancedSettings) { $queueParams['advanced_settings'] = $advancedSettings; }
            $useQueue = isset($data['use_queue']) ? (bool)$data['use_queue'] : true;

            if ($useQueue) {
                $result = $this->queueService->addOperation('protect', $queueParams);
                $result['refresh_list'] = true; $result['operation_type'] = 'protect';
                Response::success($result, 'Protection operation added to queue');
            } else {
                $result = $this->protectionService->protect($path, $redundancy, $fileTypes, $fileCategories, $advancedSettings);
                $result['refresh_list'] = true; $result['operation_type'] = 'protect';
                Response::success($result, 'Protection operation completed successfully');
            }
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to protect item: " . $e->getMessage()); }
    }

    /** Remove protection */
    public function remove($params) {
        try {
            if (!isset($params['path']) && !isset($params['id'])) { throw ApiException::badRequest("Either path or id parameter is required"); }
            $path = isset($params['path']) ? urldecode($params['path']) : null;
            $id = isset($params['id']) ? intval($params['id']) : null;
            $useQueue = isset($_GET['use_queue']) ? (bool)$_GET['use_queue'] : true;

            if ($useQueue) {
                $queueParams = [];
                if ($path) { $queueParams['path'] = $path; }
                if ($id) { $queueParams['id'] = $id; }
                $result = $this->queueService->addOperation('remove', $queueParams);
                Response::success($result, 'Remove protection operation added to queue');
            } else {
                if ($id) { $result = $this->protectionService->removeById($id); }
                else { $result = $this->protectionService->remove($path); }
                $result['refresh_list'] = true; $result['operation_type'] = 'remove';
                Response::success($result, 'Protection removed successfully');
            }
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) { throw new ApiException("Failed to remove protection: " . $e->getMessage()); }
    }

    /** Re-protect files or directories */
    public function reprotect($params) {
        try {
            $data = $_POST;
            $this->logger->debug("Re-protect API endpoint called", ['post_data' => $data]);

            // Primarily expect 'ids'
            $ids = null;
            if (isset($data['ids']) && !empty($data['ids'])) {
                if (is_string($data['ids'])) {
                    $ids = json_decode($data['ids'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) { throw ApiException::badRequest("Invalid JSON in ids parameter: " . json_last_error_msg()); }
                } else { $ids = $data['ids']; }
                if (!is_array($ids)) { throw ApiException::badRequest("IDs parameter must be an array"); }
                $ids = array_map('intval', $ids);
                $ids = array_filter($ids, function($id) { return $id > 0; });
            }

            // Validate: We need IDs for re-protect
            if (empty($ids)) {
                 throw ApiException::badRequest("'ids' parameter is required and must contain valid IDs for re-protect");
            }

            // Get redundancy level (can be null if 'previous' was selected)
            $redundancy = isset($data['redundancy']) ? ($data['redundancy'] === 'null' ? null : intval($data['redundancy'])) : null;

            $queuedRemoveCount = 0;
            $queuedProtectCount = 0;
            $failedItems = [];

            foreach ($ids as $itemId) {
                try {
                    // 1. Get item details (needed for path, fileTypes etc. for protect step)
                    $item = $this->protectionService->getStatusById($itemId); // Use existing method
                    if (!$item) {
                        $this->logger->warning("Reprotect: Item not found, skipping.", ['id' => $itemId]);
                        $failedItems[] = $itemId . " (Not Found)";
                        continue;
                    }

                    // 2. Queue 'remove' operation
                    $this->queueService->addOperation('remove', ['id' => $itemId]);
                    $queuedRemoveCount++;

                    // 3. Queue 'protect' operation with an overwrite flag
                    $protectParams = [
                        'path' => $item['path'],
                        'redundancy' => $redundancy, // Pass selected/default redundancy
                        'file_types' => $item['file_types'], // Use original file types
                        'file_categories' => $item['file_categories'], // Use original categories
                        'overwrite' => true // Add flag to indicate existing files should be removed first
                        // Advanced settings are not typically re-applied on simple re-protect,
                        // but could be added here if needed based on UI options.
                    ];
                    $this->queueService->addOperation('protect', $protectParams);
                    $queuedProtectCount++;

                } catch (\Exception $e) {
                    $this->logger->error("Failed to queue re-protect steps for item", ['id' => $itemId, 'error' => $e->getMessage()]);
                    $failedItems[] = $itemId . " (" . $e->getMessage() . ")";
                }
            }

            $totalRequested = count($ids);
            $message = "$queuedProtectCount/$totalRequested item(s) queued for re-protection.";
            if (!empty($failedItems)) {
                 $message .= " Failed items: " . implode(', ', $failedItems);
            }

            Response::success([
                'queued_remove_count' => $queuedRemoveCount,
                'queued_protect_count' => $queuedProtectCount,
                'total_requested' => $totalRequested,
                'failed_items' => $failedItems,
                'refresh_list' => true
            ], $message);

        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Re-protect endpoint failed", ['error' => $e->getMessage()]);
            throw new ApiException("Failed to queue re-protect operation(s): " . $e->getMessage());
        }
    }

    /** Get files within a directory (used by protection dialog) */
    public function getFiles($params) {
        try {
            if (!isset($params['path'])) { throw ApiException::badRequest("Path parameter is required"); }
            $path = urldecode($params['path']);
            if (!is_dir($path)) { throw ApiException::notFound("Directory not found: $path"); }
            $filesData = $this->protectionService->getFilesInDirectory($path);
            Response::success($filesData);
        } catch (ApiException $e) { throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get files in directory", ['path' => $params['path'] ?? null, 'error' => $e->getMessage()]);
            throw new ApiException("Failed to get files: " . $e->getMessage());
        }
    }

} // End of class ProtectionEndpoint