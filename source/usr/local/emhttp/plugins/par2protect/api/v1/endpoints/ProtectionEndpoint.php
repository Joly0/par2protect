<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Protection;
use Par2Protect\Services\Queue;
use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;

class ProtectionEndpoint {
    private $protectionService;
    private $queueService;
    private $config;
    private $logger;
    
    /**
     * ProtectionEndpoint constructor
     */
    public function __construct() {
        $this->protectionService = new Protection();
        $this->queueService = new Queue();
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Get redundancy levels for multiple paths
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getRedundancyLevels($params) {
        try {
            // Get paths from POST data
            $data = $_POST;
            
            if (!isset($data['paths'])) {
                throw ApiException::badRequest("Paths parameter is required");
            }
            
            // If paths is a JSON string, decode it
            if (is_string($data['paths'])) {
                $paths = json_decode($data['paths'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw ApiException::badRequest("Invalid JSON in paths parameter: " . json_last_error_msg());
                }
            } else {
                $paths = $data['paths'];
            }
            
            if (!is_array($paths)) {
                throw ApiException::badRequest("Paths parameter must be an array");
            }
            
            $result = $this->protectionService->getRedundancyLevels($paths);
            Response::success($result);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get redundancy levels: " . $e->getMessage());
        }
    }
    
    /**
     * Get redundancy level for a single path
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getRedundancyLevel($params) {
        try {
            if (!isset($params['path'])) {
                throw ApiException::badRequest("Path parameter is required");
            }
            
            $path = urldecode($params['path']);
            $redundancy = $this->protectionService->getRedundancyLevel($path);
            
            if ($redundancy === null) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            Response::success(['redundancy' => $redundancy]);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get redundancy level: " . $e->getMessage());
        }
    }
    
    /**
     * Get all protected items
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getAll($params) {
        try {
            $items = $this->protectionService->getAllProtectedItems();
            Response::success($items);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get protected items: " . $e->getMessage());
        }
    }
    
    /**
     * Get status of protected item
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getStatus($params) {
        try {
            if (!isset($params['path'])) {
                throw ApiException::badRequest("Path parameter is required");
            }
            
            $path = urldecode($params['path']);
            $status = $this->protectionService->getStatus($path);
            Response::success($status);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get protection status: " . $e->getMessage());
        }
    }
    
    /**
     * Protect a file or directory
     *
     * @param array $params Request parameters
     * @return void
     */
    public function protect($params) {
        try {
            // Get request data
            $data = $_POST;
            
            // Add diagnostic logging for API request
            /* $this->logger->debug("DIAGNOSTIC: Protection API endpoint called", [
                'params' => $params,
                'post_data' => $data
            ]); */
            
            // Add detailed logging for all request parameters
            $this->logger->debug("DIAGNOSTIC: Full protection request parameters", [
                'all_params' => $data,
                'has_block_count' => isset($data['block_count']) ? 'true' : 'false',
                'has_block_size' => isset($data['block_size']) ? 'true' : 'false',
                'has_target_size' => isset($data['target_size']) ? 'true' : 'false',
                'has_recovery_files' => isset($data['recovery_files']) ? 'true' : 'false'
            ]);
            
            // Validate request
            if (!isset($data['path']) || empty($data['path'])) {
                throw ApiException::badRequest("Path parameter is required");
            }
            
            $path = $data['path'];
            $redundancy = isset($data['redundancy']) ? intval($data['redundancy']) : null;
            $fileTypes = isset($data['file_types']) ? $data['file_types'] : null;
            $fileCategories = isset($data['file_categories']) ? $data['file_categories'] : null;
            
            // Extract advanced settings if provided
            $advancedSettings = null;
            if (isset($data['block_count']) || isset($data['block_size']) || 
                isset($data['target_size']) || isset($data['recovery_files']) ||
                isset($data['advanced_settings'])) {
                
                // Check if advanced settings are provided as a nested object
                if (isset($data['advanced_settings'])) {
                    $advancedSettings = $data['advanced_settings'];
                    $this->logger->debug("DIAGNOSTIC: Advanced settings provided as nested object", [
                        'path' => $path,
                        'advanced_settings' => json_encode($advancedSettings)
                    ]);
                } 
                // Otherwise, extract them from the top-level parameters
                else {
                    $advancedSettings = [
                        'block_count' => isset($data['block_count']) ? $data['block_count'] : null,
                        'block_size' => isset($data['block_size']) ? $data['block_size'] : null,
                        'target_size' => isset($data['target_size']) ? $data['target_size'] : null,
                        'recovery_files' => isset($data['recovery_files']) ? $data['recovery_files'] : null
                    ];
                    
                    $this->logger->debug("DIAGNOSTIC: Advanced settings extracted from top-level parameters", [
                        'path' => $path,
                        'advanced_settings' => json_encode($advancedSettings)
                    ]);
                }
            }
            
            // If any of the advanced settings are directly in the parameters, add them to the queue parameters directly
            $queueParams = [
                'path' => $path,
                'redundancy' => $redundancy,
                'file_types' => $fileTypes,
                'file_categories' => $fileCategories
            ];
            
            if (isset($data['block_count'])) {
                $queueParams['block_count'] = $data['block_count'];
            }
            if (isset($data['block_size'])) {
                $queueParams['block_size'] = $data['block_size'];
            }
            if (isset($data['target_size'])) {
                $queueParams['target_size'] = $data['target_size'];
            }
            if (isset($data['recovery_files'])) {
                $queueParams['recovery_files'] = $data['recovery_files'];
            }
            
            // Also add the advanced settings as a nested object for our internal use
            if ($advancedSettings) {
                $queueParams['advanced_settings'] = $advancedSettings;
            }
            
            $this->logger->debug("DIAGNOSTIC: Final queue parameters", [
                'path' => $path,
                'queue_params' => json_encode($queueParams)
            ]);
            
            // Add diagnostic logging for file types
            if ($fileTypes) {
                $this->logger->debug("DIAGNOSTIC: File types received by API", [
                    'path' => $path,
                    'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes,
                    'file_types_type' => gettype($fileTypes),
                    'file_categories' => is_array($fileCategories) ? json_encode($fileCategories) : $fileCategories,
                    'file_categories_type' => gettype($fileCategories)
                ]);
            }
            
            $useQueue = isset($data['use_queue']) ? (bool)$data['use_queue'] : true;
            
            if ($useQueue) {
                // Add to queue
                $this->logger->debug("DIAGNOSTIC: Adding protection to queue", [
                    'path' => $path,
                    'redundancy' => $redundancy,
                    'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes
                ]);
                
                $result = $this->queueService->addOperation('protect', $queueParams);
                
                Response::success($result, 'Protection operation added to queue');
            } else {
                // Direct execution
                $this->logger->debug("DIAGNOSTIC: Direct protection execution", [
                    'path' => $path,
                    'redundancy' => $redundancy,
                    'file_types' => is_array($fileTypes) ? json_encode($fileTypes) : $fileTypes
                ]);
                
                $result = $this->protectionService->protect($path, $redundancy, $fileTypes, $fileCategories, $advancedSettings);
                
                // Add diagnostic logging for direct protection execution result
                $this->logger->debug("DIAGNOSTIC: Direct protection execution result", [
                    'path' => $path,
                    'success' => isset($result['success']) ? ($result['success'] ? 'true' : 'false') : 'unknown',
                    'message' => $result['message'] ?? 'no message'
                ]);
                
                // Add refresh_list flag to indicate the protected files list should be refreshed
                $result['refresh_list'] = true;
                $result['operation_type'] = 'protect';
                
                Response::success($result, 'Protection operation completed successfully');
            }
        } catch (ApiException $e) {
            $this->logger->error("DIAGNOSTIC: API Exception in protection endpoint", [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("DIAGNOSTIC: Exception in protection endpoint", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw new ApiException("Failed to protect item: " . $e->getMessage());
        }
    }
    
    /**
     * Remove protection
     *
     * @param array $params Request parameters
     * @return void
     */
    public function remove($params) {
        try {
            // Validate request - either path or id must be provided
            if (!isset($params['path']) && !isset($params['id'])) {
                throw ApiException::badRequest("Either path or id parameter is required");
            }
            
            // Get path and id parameters
            $path = isset($params['path']) ? urldecode($params['path']) : null;
            $id = isset($params['id']) ? intval($params['id']) : null;
            $useQueue = isset($_GET['use_queue']) ? (bool)$_GET['use_queue'] : true;
            
            if ($useQueue) {
                // Add to queue
                $params = [];
                
                // Add either path or id or both to the parameters
                if ($path) {
                    $params['path'] = $path;
                }
                if ($id) {
                    $params['id'] = $id;
                }
                
                $result = $this->queueService->addOperation('remove', $params);
                
                Response::success($result, 'Remove protection operation added to queue');
            } else {
                // Direct execution
                if ($id) {
                    // If ID is provided, use it for removal
                    $result = $this->protectionService->removeById($id);
                } else {
                    // Otherwise fall back to path-based removal
                    $result = $this->protectionService->remove($path);
                }
                
                // Add refresh_list flag to indicate the protected files list should be refreshed
                $result['refresh_list'] = true;
                $result['operation_type'] = 'remove';
                
                Response::success($result, 'Protection removed successfully');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to remove protection: " . $e->getMessage());
        }
    }
    
    /**
     * Re-protect files or directories
     * 
     * This endpoint removes existing protection and adds new protection in a single operation
     *
     * @param array $params Request parameters
     * @return void
     */
    public function reprotect($params) {
        try {
            // Get request data
            $data = $_POST;
            
            $this->logger->debug("DIAGNOSTIC: Re-protect API endpoint called", [
                'params' => $params,
                'post_data' => $data
            ]);
            
            // Validate request
            if (!isset($data['paths']) || empty($data['paths'])) {
                throw ApiException::badRequest("Paths parameter is required");
            }
            
            // If paths is a JSON string, decode it
            if (is_string($data['paths'])) {
                $paths = json_decode($data['paths'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw ApiException::badRequest("Invalid JSON in paths parameter: " . json_last_error_msg());
                }
            } else {
                $paths = $data['paths'];
            }
            
            if (!is_array($paths)) {
                throw ApiException::badRequest("Paths parameter must be an array");
            }
            
            // Process IDs if provided
            $ids = [];
            if (isset($data['ids']) && !empty($data['ids'])) {
                if (is_string($data['ids'])) {
                    $ids = json_decode($data['ids'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw ApiException::badRequest("Invalid JSON in ids parameter: " . json_last_error_msg());
                    }
                } else {
                    $ids = $data['ids'];
                }
                
                if (!is_array($ids)) {
                    throw ApiException::badRequest("IDs parameter must be an array");
                }
                
                // Ensure IDs array has the same length as paths array
                if (count($ids) !== count($paths)) {
                    $this->logger->warning("IDs and paths arrays have different lengths", [
                        'ids_count' => count($ids),
                        'paths_count' => count($paths)
                    ]);
                }
            }
            
            // Get redundancy option
            $redundancyOption = isset($data['redundancy_option']) ? $data['redundancy_option'] : 'default';
            $customRedundancy = isset($data['custom_redundancy']) ? intval($data['custom_redundancy']) : null;
            
            // Get default redundancy from settings
            $defaultRedundancy = $this->config->get('protection.default_redundancy', 10);
            
            // Track operations
            $operations = [];
            
            // Process each path
            foreach ($paths as $index => $path) {
                // Get corresponding ID if available
                $itemId = (isset($ids[$index]) && !empty($ids[$index])) ? $ids[$index] : null;
                
                // Add diagnostic logging for path/id confusion
                $this->logger->debug("DIAGNOSTIC: Re-protect processing item", [
                    'path' => $path,
                    'id' => $itemId,
                    'path_type' => gettype($path),
                    'is_numeric' => is_numeric($path) ? 'true' : 'false',
                    'operation_type' => 'reprotect'
                ]);
                
                // Check if path is numeric, which might indicate it's actually an ID
                $isNumericPath = is_numeric($path) && !$itemId;
                if ($isNumericPath && !$itemId) {
                    $itemId = intval($path);
                }
                
                // If path is numeric, try to get the actual path from the database
                $actualPath = $path;
                if ($isNumericPath) {
                    try {
                        $result = $this->db->query(
                            "SELECT path FROM protected_items WHERE id = :id",
                            [':id' => $itemId]
                        );
                        $item = $this->db->fetchOne($result);
                        
                        if ($item) {
                            $actualPath = $item['path'];
                            $this->logger->debug("DIAGNOSTIC: Found actual path for numeric ID", [
                                'id' => $itemId,
                                'actual_path' => $actualPath
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to get actual path for ID", [
                            'id' => $itemId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Check if this is a directory with individual files protected
                $hasIndividualFiles = false;
                $fileTypes = null;
                $isIndividualFilesMode = false;
                
                try {
                    // Get the item from the database
                    // Use ID-based lookup if we have an ID, otherwise use path-based lookup
                    if ($isNumericPath && $itemId) {
                        $item = $this->protectionService->getStatusById($itemId);
                    } else {
                        $item = $this->protectionService->getStatus($path);
                    }
                    
                    // If this is a directory with file types or Individual Files mode, it might have individual files
                    if (($item['mode'] === 'directory' || $item['mode'] === 'Individual Files') && !empty($item['file_types'])) {
                        $fileTypes = $item['file_types'];
                        $isIndividualFilesMode = $item['mode'] === 'Individual Files';
                        
                        // Check if there are individual files with this directory as parent
                        $individualFiles = $this->protectionService->getIndividualFiles($path);
                        $hasIndividualFiles = !empty($individualFiles);
                        
                        $this->logger->debug("DIAGNOSTIC: Re-protect checking for individual files", [
                            'path' => $path,
                            'has_individual_files' => $hasIndividualFiles,
                            'is_individual_files_mode' => $isIndividualFilesMode,
                            'file_types' => json_encode($fileTypes),
                            'individual_files_count' => count($individualFiles)
                        ]);
                    }
                } catch (\Exception $e) {
                    // If we can't get the item, just continue with standard re-protection
                    $this->logger->warning("Failed to get item details for re-protection", [
                        'path' => $path,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Determine redundancy level to use
                $redundancy = $defaultRedundancy;
                
                if ($redundancyOption === 'custom' && $customRedundancy !== null) {
                    // Use custom redundancy
                    $redundancy = $customRedundancy;
                } else if ($redundancyOption === 'previous') {
                    // Get previous redundancy level
                    $previousRedundancy = $this->protectionService->getRedundancyLevel($path);
                    if ($previousRedundancy !== null) {
                        $redundancy = $previousRedundancy;
                    }
                }
                
                // Step 1: Add remove operation to queue
                // Include both path and ID parameters
                $removeParams = ['path' => $actualPath];
                if ($isNumericPath && $itemId) {
                    $removeParams['id'] = $itemId;
                } else if ($itemId) {
                    $removeParams['id'] = $itemId;
                }
                
                $removeResult = $this->queueService->addOperation('remove', $removeParams);
                
                $this->logger->debug("DIAGNOSTIC: Re-protect remove operation added", [
                    'path' => $path,
                    'actual_path' => $actualPath,
                    'id' => $itemId,
                    'operation_id' => $removeResult['operation_id'],
                    'operation_type' => 'remove'
                ]);
                
                $operations[] = [
                    'path' => $path,
                    'operation' => 'remove',
                    'operation_id' => $removeResult['operation_id']
                ];
                
                // Step 2: Add protect operation to queue
                $protectParams = [
                    'path' => $actualPath,
                    'redundancy' => $redundancy,
                    'mode' => $isIndividualFilesMode ? 'Individual Files' : 'directory'
                ];
                
                // If this is a directory with individual files, include file types
                if (($hasIndividualFiles || $isIndividualFilesMode) && $fileTypes) {
                    $protectParams['file_types'] = $fileTypes;
                    
                    $this->logger->debug("DIAGNOSTIC: Re-protect with individual files", [
                        'path' => $path,
                        'mode' => $protectParams['mode'],
                        'file_types' => json_encode($fileTypes)
                    ]);
                }
                
                $protectResult = $this->queueService->addOperation('protect', $protectParams);

                $this->logger->debug("DIAGNOSTIC: Re-protect protect operation added", [
                    'path' => $path,
                    'actual_path' => $actualPath,
                    'id' => $itemId,
                    'operation_id' => $protectResult['operation_id'],
                    'operation_type' => 'protect',
                    'params' => json_encode($protectParams)
                ]);
                
                $operations[] = [
                    'path' => $path,
                    'operation' => 'protect',
                    'operation_id' => $protectResult['operation_id'],
                    'redundancy' => $redundancy,
                    'file_types' => $fileTypes
                ];
            }
            
            $this->logger->info("Re-protection operations added to queue", [
                'paths_count' => count($paths),
                'operations_count' => count($operations),
                'action' => 'Reprotect',
                'operation_type' => 'reprotect',
                'status' => 'Success'
            ]);
            
            Response::success([
                'operations' => $operations,
                'paths_count' => count($paths),
                'operations_count' => count($operations)
            ], 'Re-protection operations added to queue');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to add re-protection operations to queue", [
                'error' => $e->getMessage(),
                'action' => 'Reprotect',
                'operation_type' => 'reprotect',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Failed to add re-protection operations to queue: " . $e->getMessage());
        }
    }
    
    /**
     * Get individual files for a parent directory
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getFiles($params) {
        try {
            // Get request data
            $data = $_POST;

            // Add diagnostic logging for API request
            $this->logger->debug("DIAGNOSTIC: getFiles API endpoint called", [
                'params' => $params,
                'post_data' => $data
            ]);
            
            // Validate request
            if (!isset($data['path']) || empty($data['path'])) {
                throw ApiException::badRequest("Path parameter is required");
            }
            
            $path = $data['path'];
            
            // Get file types from POST data if provided
            $fileTypes = isset($data['file_types']) && !empty($data['file_types']) 
                ? explode(',', $data['file_types']) 
                : null;

            // Add diagnostic logging before getting individual files
            $this->logger->debug("DIAGNOSTIC: Getting individual files for path", [
                'path' => $path,
                'file_types' => $fileTypes
            ]);
            
            // First, check if this is a directory with "Individual Files" mode
            try {
                // If file types are provided, use them to get the specific directory item
                if ($fileTypes) {
                    $this->logger->debug("DIAGNOSTIC: Using getStatusWithFileTypes", [
                        'path' => $path,
                        'file_types' => $fileTypes
                    ]);
                    
                    $directoryItem = $this->protectionService->getStatusWithFileTypes($path, $fileTypes);
                } else {
                    $this->logger->debug("DIAGNOSTIC: Using getStatus without file types", [
                        'path' => $path
                    ]);
                    
                    $directoryItem = $this->protectionService->getStatus($path);
                }
                
                $this->logger->debug("DIAGNOSTIC: Checking directory item", [
                    'path' => $path,
                    'mode' => $directoryItem['mode'] ?? 'unknown',
                    'has_protected_files' => isset($directoryItem['protected_files']) && !empty($directoryItem['protected_files']),
                    'protected_files_count' => isset($directoryItem['protected_files']) ? count($directoryItem['protected_files']) : 0
                ]);
                
                // If this is an "Individual Files" directory with protected_files, return it directly
                if (isset($directoryItem['mode']) && 
                    strpos($directoryItem['mode'], 'Individual Files') === 0 && 
                    isset($directoryItem['protected_files']) && 
                    !empty($directoryItem['protected_files'])) {
                    
                    $this->logger->debug("DIAGNOSTIC: Returning directory item with protected_files", [
                        'path' => $path,
                        'protected_files_count' => count($directoryItem['protected_files'])
                    ]);
                    
                    // Return the directory item as an array with one item
                    Response::success([$directoryItem], 'Directory with protected files retrieved successfully');
                    return;
                }
            } catch (\Exception $e) {
                // If we can't get the directory item, just continue with standard approach
                $this->logger->debug("DIAGNOSTIC: Failed to get directory item, continuing with standard approach", [
                    'path' => $path,
                    'error' => $e->getMessage()
                ]);
            }
            
            // Standard approach: Get individual files for this directory
            $files = $this->protectionService->getIndividualFiles($path);
            
            $this->logger->debug("DIAGNOSTIC: Retrieved individual files for directory", [
                'path' => $path,
                'files_count' => count($files),
                'files' => array_slice(array_map(function($file) {
                    return [
                        'path' => $file['path'],
                        'mode' => $file['mode'],
                        'parent_dir' => $file['parent_dir'] ?? null
                    ];
                }, $files), 0, 5)
            ]);
            
            Response::success($files, 'Individual files retrieved successfully');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get individual files", [
                'error' => $e->getMessage(),
                'action' => 'GetFiles',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Failed to get individual files: " . $e->getMessage());
        }
    }
}