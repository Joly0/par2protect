<?php
namespace Par2Protect\Services\Verification;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;

/**
 * Main verification service class
 */
class Verification {
    private $repository;      // VerificationRepository instance
    private $operations;      // VerificationOperations instance
    private $metadataManager; // MetadataManager instance
    private $logger;          // Logger instance
    private $config;          // Config instance
    private $cache;           // Cache instance
    
    /**
     * Verification service constructor
     */
    public function __construct() {
        // Initialize core dependencies
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        $this->cache = Cache::getInstance();
        $db = Database::getInstance();
        
        // Initialize specialized classes
        $this->repository = new VerificationRepository($db, $this->logger, $this->cache);
        $this->operations = new VerificationOperations($this->logger, $this->config);
        $this->metadataManager = new MetadataManager($db, $this->logger);
    }
    
    /**
     * Get verification status for a protected item
     *
     * @param string $path Path to check
     * @return array
     */
    public function getStatus($path) {
        try {
            // Check cache first
            $cacheKey = 'verification_status_' . md5($path);
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached verification status for path");
                return $this->cache->get($cacheKey);
            }
            
            // Get protected item
            $item = $this->repository->getProtectedItem($path);
            
            if (!$item) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            // Get verification history
            $history = $this->repository->getVerificationHistory($item['id']);
            
            $status = [
                'item' => [
                    'id' => $item['id'],
                    'path' => $item['path'],
                    'mode' => $item['mode'],
                    'last_verified' => $item['last_verified'],
                    'last_status' => $item['last_status']
                ],
                'history' => $history
            ];
            
            // Cache the result for 5 minutes
            $this->cache->set($cacheKey, $status, 300);
            
            return $status;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get verification status", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get verification status: " . $e->getMessage());
        }
    }
    
    /**
     * Get verification status for a protected item by ID
     *
     * @param int $id Protected item ID
     * @return array
     */
    public function getStatusById($id) {
        try {
            // Check cache first
            $cacheKey = 'verification_status_id_' . $id;
            if ($this->cache->has($cacheKey)) {
                $this->logger->debug("Using cached verification status for ID");
                return $this->cache->get($cacheKey);
            }
            
            // Get protected item
            $result = Database::getInstance()->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = Database::getInstance()->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Get verification history
            $history = $this->repository->getVerificationHistory($item['id']);
            
            $status = [
                'item' => [
                    'id' => $item['id'],
                    'path' => $item['path'],
                    'mode' => $item['mode'],
                    'last_verified' => $item['last_verified'],
                    'last_status' => $item['last_status']
                ],
                'history' => $history
            ];
            
            // Cache the result for 5 minutes
            $this->cache->set($cacheKey, $status, 300);
            
            return $status;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get verification status by ID", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new ApiException("Failed to get verification status: " . $e->getMessage());
        }
    }
    
    /**
     * Verify a protected item
     *
     * @param string $path Path to verify
     * @param bool $force Force verification even if recently verified
     * @param bool $verifyMetadata Whether to verify file metadata
     * @param bool $autoRestoreMetadata Whether to automatically restore metadata if discrepancies are found
     * @return array
     */
    public function verify($path, $force = false, $verifyMetadata = false, $autoRestoreMetadata = false) {
        $this->logger->debug("Starting verification operation", [
            'path' => $path,
            'force' => $force ? 'true' : 'false',
            'verify_metadata' => $verifyMetadata ? 'true' : 'false',
            'auto_restore_metadata' => $autoRestoreMetadata ? 'true' : 'false',
            'action' => 'Verify',
            'operation_type' => 'verify'
        ]);
        
        try {
            // Check if path exists
            if (!file_exists($path)) {
                throw ApiException::badRequest("Path does not exist: $path");
            }
            
            // Determine if we should prefer directory mode
            // If the path is a directory and force is true, prefer directory mode
            $preferDirectory = false;
            if (is_dir($path) && $force) {
                $preferDirectory = true;
                $this->logger->debug("DIAGNOSTIC: Force parameter is true for directory, preferring directory mode", [
                    'path' => $path
                ]);
            }
            
            // Get the correct protected item
            $item = $this->repository->getProtectedItem($path, $preferDirectory);
            
            if (!$item) {
                throw ApiException::notFound("Item not found in protected items: $path");
            }
            
            // Check if par2 file exists
            $par2Path = $item['par2_path'];
            if (!file_exists($par2Path)) {
                throw ApiException::badRequest("Par2 file not found: $par2Path");
            }
            
            
            // Verify files
            $this->logger->debug("DIAGNOSTIC: Executing verification for item", [
                'path' => $path,
                'mode' => $item['mode'],
                'par2_path' => $par2Path,
                'is_individual_files' => strpos($item['mode'], 'Individual Files') === 0 ? 'true' : 'false',
                'verify_metadata' => $verifyMetadata ? 'true' : 'false'
            ]);
            
            $verifyResult = $this->operations->verifyPar2Files($path, $par2Path, $item['mode']);
            
            // Verify metadata if requested
            $metadataResult = null;
            if ($verifyMetadata) {
                $metadataResult = $this->metadataManager->verifyMetadata($item['id'], $path, $item['mode'], $autoRestoreMetadata);
                
                // Append metadata verification results to the details
                $verifyResult['details'] .= "\n\n--- Metadata Verification ---\n" . $metadataResult['details'];
                
                // Update status if metadata verification failed
                if ($metadataResult['status'] !== 'VERIFIED' && $verifyResult['status'] === 'VERIFIED') {
                    $verifyResult['status'] = 'METADATA_ISSUES';
                }
            }
            
            // Update verification status
            $this->repository->updateVerificationStatus($item['id'], $verifyResult['status'], $verifyResult['details']);
            
            // Clear cache for this item
            $this->repository->clearVerificationCache($item['id'], $path);
            
            $this->logger->info("Verify operation completed", [
                'path' => $path,
                'status' => $verifyResult['status'],
                'action' => 'Verify',
                'operation_type' => 'verify'
            ]);
            
            return [
                'success' => true,
                'status' => $verifyResult['status'],
                'details' => $verifyResult['details']
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Verify operation failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'action' => 'Verify',
                'operation_type' => 'verify',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Verification operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Verify a protected item by ID
     *
     * @param int $id Protected item ID
     * @param bool $force Force verification even if recently verified
     * @param bool $verifyMetadata Whether to verify file metadata
     * @param bool $autoRestoreMetadata Whether to automatically restore metadata if discrepancies are found
     * @return array
     */
    public function verifyById($id, $force = false, $verifyMetadata = false, $autoRestoreMetadata = false) {
        $this->logger->debug("Starting verification operation by ID", [
            'id' => $id,
            'force' => $force ? 'true' : 'false',
            'verify_metadata' => $verifyMetadata ? 'true' : 'false',
            'auto_restore_metadata' => $autoRestoreMetadata ? 'true' : 'false',
            'action' => 'Verify',
            'operation_type' => 'verify'
        ]);
        
        try {
            // Get the protected item by ID
            $result = Database::getInstance()->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = Database::getInstance()->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Check if path exists
            $path = $item['path'];
            if (!file_exists($path)) {
                throw ApiException::badRequest("Path does not exist: $path");
            }
            
            // Check if par2 file exists
            $par2Path = $item['par2_path'];
            if (!file_exists($par2Path)) {
                throw ApiException::badRequest("Par2 file not found: $par2Path");
            }
            
            
            // Verify files
            $this->logger->debug("DIAGNOSTIC: Executing verification for item by ID", [
                'id' => $id,
                'path' => $path,
                'mode' => $item['mode'],
                'par2_path' => $par2Path,
                'is_individual_files' => strpos($item['mode'], 'Individual Files') === 0 ? 'true' : 'false',
                'verify_metadata' => $verifyMetadata ? 'true' : 'false'
            ]);
            
            $verifyResult = $this->operations->verifyPar2Files($path, $par2Path, $item['mode']);
            
            // Verify metadata if requested
            $metadataResult = null;
            if ($verifyMetadata) {
                $metadataResult = $this->metadataManager->verifyMetadata($item['id'], $path, $item['mode'], $autoRestoreMetadata);
                
                // Append metadata verification results to the details
                $verifyResult['details'] .= "\n\n--- Metadata Verification ---\n" . $metadataResult['details'];
                
                // Update status if metadata verification failed
                if ($metadataResult['status'] !== 'VERIFIED' && $verifyResult['status'] === 'VERIFIED') {
                    $verifyResult['status'] = 'METADATA_ISSUES';
                }
            }
            
            // Update verification status
            $this->repository->updateVerificationStatus($item['id'], $verifyResult['status'], $verifyResult['details']);
            
            // Clear cache for this item
            $this->repository->clearVerificationCache($item['id'], $path);
            
            $this->logger->info("Verify operation completed by ID", [
                'id' => $id,
                'path' => $path,
                'status' => $verifyResult['status'],
                'action' => 'Verify',
                'operation_type' => 'verify'
            ]);
            
            return [
                'success' => true,
                'status' => $verifyResult['status'],
                'details' => $verifyResult['details']
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Verify operation failed by ID", [
                'id' => $id,
                'error' => $e->getMessage(),
                'action' => 'Verify',
                'operation_type' => 'verify',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Verification operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Repair a protected item
     *
     * @param string $path Path to repair
     * @param bool $restoreMetadata Whether to restore metadata after repair
     * @return array
     */
    public function repair($path, $restoreMetadata = true) {
        $this->logger->debug("Starting repair operation", [
            'path' => $path,
            'restore_metadata' => $restoreMetadata ? 'true' : 'false',
            'action' => 'Repair',
            'operation_type' => 'repair'
        ]);
        
        try {
            // Check if path exists
            if (!file_exists($path)) {
                throw ApiException::badRequest("Path does not exist: $path");
            }
            
            // Determine if we should prefer directory mode
            // For repair operations, we'll always prefer directory mode if the path is a directory
            $preferDirectory = false;
            if (is_dir($path)) {
                $preferDirectory = true;
                $this->logger->debug("DIAGNOSTIC: Path is a directory, preferring directory mode for repair", [
                    'path' => $path
                ]);
            }
            
            // Get the correct protected item
            $item = $this->repository->getProtectedItem($path, $preferDirectory);
            
            if (!$item) {
                throw ApiException::notFound("Item not found in protected items: $path");
            }
            
            // Check if par2 file exists
            $par2Path = $item['par2_path'];
            if (!file_exists($par2Path)) {
                throw ApiException::badRequest("Par2 file not found: $par2Path");
            }
            
            // Repair files
            $repairResult = $this->operations->repairPar2Files($path, $par2Path, $item['mode']);
            
            // Restore metadata after repair if requested
            if ($restoreMetadata && ($repairResult['status'] === 'REPAIRED')) {
                $this->logger->debug("Restoring metadata after repair by ID", [
                    'id' => $item['id'],
                    'path' => $path
                ]);
                
                $metadataResult = $this->metadataManager->restoreMetadata($item['id'], $path, $item['mode']);
                
                // Append metadata restoration results to the details
                $repairResult['details'] .= "\n\n--- Metadata Restoration ---\n" . $metadataResult['details'];
            }
            
            // Update verification status
            $this->repository->updateVerificationStatus($item['id'], $repairResult['status'], $repairResult['details']);
            
            // Clear cache for this item
            $this->repository->clearVerificationCache($item['id'], $path);
            
            $this->logger->info("Repair operation completed", [
                'path' => $path,
                'status' => $repairResult['status'],
                'action' => 'Repair',
                'operation_type' => 'repair'
            ]);
            
            return [
                'success' => true,
                'status' => $repairResult['status'],
                'details' => $repairResult['details']
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Repair operation failed", [
                'path' => $path,
                'error' => $e->getMessage(),
                'action' => 'Repair',
                'operation_type' => 'repair',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Repair operation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Repair a protected item by ID
     *
     * @param int $id Protected item ID
     * @param bool $restoreMetadata Whether to restore metadata after repair
     * @return array
     */
    public function repairById($id, $restoreMetadata = true) {
        $this->logger->debug("Starting repair operation by ID", [
            'id' => $id,
            'restore_metadata' => $restoreMetadata ? 'true' : 'false',
            'action' => 'Repair',
            'operation_type' => 'repair'
        ]);
        
        try {
            // Get the protected item by ID
            $result = Database::getInstance()->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = Database::getInstance()->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Check if path exists
            $path = $item['path'];
            if (!file_exists($path)) {
                throw ApiException::badRequest("Path does not exist: $path");
            }
            
            // Check if par2 file exists
            $par2Path = $item['par2_path'];
            if (!file_exists($par2Path)) {
                throw ApiException::badRequest("Par2 file not found: $par2Path");
            }
            
            // Repair files
            $repairResult = $this->operations->repairPar2Files($path, $par2Path, $item['mode']);
            
            // Restore metadata after repair if requested
            if ($restoreMetadata && ($repairResult['status'] === 'REPAIRED')) {
                $this->logger->debug("Restoring metadata after repair", [
                    'path' => $path,
                    'item_id' => $item['id']
                ]);
                
                $metadataResult = $this->metadataManager->restoreMetadata($item['id'], $path, $item['mode']);
                
                // Append metadata restoration results to the details
                $repairResult['details'] .= "\n\n--- Metadata Restoration ---\n" . $metadataResult['details'];
            }
            
            // Update verification status
            $this->repository->updateVerificationStatus($item['id'], $repairResult['status'], $repairResult['details']);
            
            // Clear cache for this item
            $this->repository->clearVerificationCache($item['id'], $path);
            
            $this->logger->info("Repair operation completed by ID", [
                'id' => $id,
                'path' => $path,
                'status' => $repairResult['status'],
                'action' => 'Repair',
                'operation_type' => 'repair'
            ]);
            
            return [
                'success' => true,
                'status' => $repairResult['status'],
                'details' => $repairResult['details']
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Repair operation failed by ID", [
                'id' => $id,
                'error' => $e->getMessage(),
                'action' => 'Repair',
                'operation_type' => 'repair',
                'status' => 'Failed'
            ]);
            
            throw new ApiException("Repair operation failed: " . $e->getMessage());
        }
    }
}