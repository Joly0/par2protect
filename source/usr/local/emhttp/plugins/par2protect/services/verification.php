<?php
namespace Par2Protect\Services;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;

class Verification {
    private $db;
    private $logger;
    private $config;
    private $cache;
    
    /**
     * Verification service constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
        $this->cache = Cache::getInstance();
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
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found: $path");
            }
            
            // Get verification history
            $result = $this->db->query(
                "SELECT * FROM verification_history 
                WHERE protected_item_id = :id 
                ORDER BY verification_date DESC 
                LIMIT 10",
                [':id' => $item['id']]
            );
            $history = $this->db->fetchAll($result);
            
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
     * Verify a protected item
     *
     * @param string $path Path to verify
     * @param bool $force Force verification even if recently verified
     * @return array
     */
    private function getProtectedItem($path, $preferDirectory = false) {
        $item = null;
        
        if ($preferDirectory) {
            // If we prefer directory mode, first check for a directory entry
            $this->logger->debug("DIAGNOSTIC: Looking for directory entry first", [
                'path' => $path,
                'prefer_directory' => 'true'
            ]);
            
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path AND mode = 'directory'",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
        }
        
        if (!$item) {
            // If no directory entry found or we don't prefer directory, check for an Individual Files entry
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path AND mode LIKE 'Individual Files%'",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
        }
        
        // If no "Individual Files" entry found, look for any entry with this path
        if (!$item) {
            $this->logger->debug("DIAGNOSTIC: No Individual Files entry found, looking for any item with this path", [
                'path' => $path
            ]);

            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE path = :path",
                [':path' => $path]
            );
            $item = $this->db->fetchOne($result);
        }
        
        // Add diagnostic logging for item details
        $this->logger->debug("DIAGNOSTIC: Protected item details", [
            'path' => $path,
            'item_found' => $item ? 'true' : 'false',
            'item_id' => $item ? $item['id'] : 'N/A',
            'item_mode' => $item ? $item['mode'] : 'N/A',
            'item_last_verified' => $item ? $item['last_verified'] : 'N/A',
            'is_individual_files' => $item && strpos($item['mode'], 'Individual Files') === 0 ? 'true' : 'false'
        ]);
        
        return $item;
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
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = $this->db->fetchOne($result);
            
            if (!$item) {
                throw ApiException::notFound("Item not found with ID: $id");
            }
            
            // Get verification history
            $result = $this->db->query(
                "SELECT * FROM verification_history 
                WHERE protected_item_id = :id 
                ORDER BY verification_date DESC 
                LIMIT 10",
                [':id' => $item['id']]
            );
            $history = $this->db->fetchAll($result);
            
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
            $item = $this->getProtectedItem($path, $preferDirectory);
            
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
            
            $verifyResult = $this->verifyPar2Files($path, $par2Path, $item['mode']);
            
            // Verify metadata if requested
            $metadataResult = null;
            if ($verifyMetadata) {
                $metadataResult = $this->verifyMetadata($item['id'], $path, $item['mode'], $autoRestoreMetadata);
                
                // Append metadata verification results to the details
                $verifyResult['details'] .= "\n\n--- Metadata Verification ---\n" . $metadataResult['details'];
                
                // Update status if metadata verification failed
                if ($metadataResult['status'] !== 'VERIFIED' && $verifyResult['status'] === 'VERIFIED') {
                    $verifyResult['status'] = 'METADATA_ISSUES';
                }
            }
            
            // Update verification status
            $this->updateVerificationStatus($item['id'], $verifyResult['status'], $verifyResult['details']);
            
            // Clear cache for this item
            $this->clearVerificationCache($item['id'], $path);
            
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
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = $this->db->fetchOne($result);
            
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
            
            $verifyResult = $this->verifyPar2Files($path, $par2Path, $item['mode']);
            
            // Verify metadata if requested
            $metadataResult = null;
            if ($verifyMetadata) {
                $metadataResult = $this->verifyMetadata($item['id'], $path, $item['mode'], $autoRestoreMetadata);
                
                // Append metadata verification results to the details
                $verifyResult['details'] .= "\n\n--- Metadata Verification ---\n" . $metadataResult['details'];
                
                // Update status if metadata verification failed
                if ($metadataResult['status'] !== 'VERIFIED' && $verifyResult['status'] === 'VERIFIED') {
                    $verifyResult['status'] = 'METADATA_ISSUES';
                }
            }
            
            // Update verification status
            $this->updateVerificationStatus($item['id'], $verifyResult['status'], $verifyResult['details']);
            
            // Clear cache for this item
            $this->clearVerificationCache($item['id'], $path);
            
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
     * Verify par2 files
     *
     * @param string $path Path to verify
     * @param string $par2Path Path to par2 file
     * @param string $mode Verification mode (file or directory)
     * @param bool $verifyMetadata Whether to verify file metadata
     * @param bool $autoRestoreMetadata Whether to automatically restore metadata if discrepancies are found
     * @return array
     */
    private function verifyPar2Files($path, $par2Path, $mode) {
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
            
            // Get configuration settings
            $config = \Par2Protect\Core\Config::getInstance();
            
            // Build par2 command with resource limit parameters
            $command = "par2 verify -q -B\"$basePath\" \"$par2Path\"";
            
            // Add -t parameter for CPU threads if set
            $maxCpuThreads = $config->get('resource_limits.max_cpu_usage');
            if ($maxCpuThreads) {
                $command .= " -t$maxCpuThreads";
            }
            
            // Add -m parameter for memory usage if set
            $maxMemory = $config->get('resource_limits.max_memory_usage');
            if ($maxMemory) {
                $command .= " -m$maxMemory";
            }
            
            // Add -T parameter for parallel file hashing if set
            $parallelFileHashing = $config->get('resource_limits.parallel_file_hashing');
            if ($parallelFileHashing) {
                $command .= " -T$parallelFileHashing";
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
                $command = "ionice -c $ioniceClass -n $ioniceLevel " . $command;
            }
            
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
                // Get configuration settings
                $config = \Par2Protect\Core\Config::getInstance();
                
                // Build par2 command with resource limit parameters
                $command = "par2 verify -q -B\"$basePath\" \"$par2File\"";
                
                // Add -t parameter for CPU threads if set
                $maxCpuThreads = $config->get('resource_limits.max_cpu_usage');
                if ($maxCpuThreads) {
                    $command .= " -t$maxCpuThreads";
                }
                
                // Add -m parameter for memory usage if set
                $maxMemory = $config->get('resource_limits.max_memory_usage');
                if ($maxMemory) {
                    $command .= " -m$maxMemory";
                }
                
                // Add -T parameter for parallel file hashing if set
                $parallelFileHashing = $config->get('resource_limits.parallel_file_hashing');
                if ($parallelFileHashing) {
                    $command .= " -T$parallelFileHashing";
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
                    $command = "ionice -c $ioniceClass -n $ioniceLevel " . $command;
                }
                
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
     * Update verification status
     *
     * @param int $itemId Protected item ID
     * @param string $status Verification status
     * @param string $details Verification details
     * @return bool
     */
    private function updateVerificationStatus($itemId, $status, $details) {
        try {
            $this->db->beginTransaction();
            
            // Get current timestamp
            $now = date('Y-m-d H:i:s');
            
            // Log the update operation
            $this->logger->debug("Updating verification status", [
                'item_id' => $itemId,
                'status' => $status,
                'timestamp' => $now
            ]);
            
            // Update protected item with explicit commit
            $this->db->query(
                "UPDATE protected_items SET
                last_verified = :now,
                last_status = :status,
                last_details = :details
                WHERE id = :id",
                [
                    ':id' => $itemId,
                    ':now' => $now,
                    ':status' => $status,
                    ':details' => $details
                ]
            );
            
            // Verify the update was successful
            $result = $this->db->query(
                "SELECT last_verified, last_status FROM protected_items WHERE id = :id",
                [':id' => $itemId]
            );
            $item = $this->db->fetchOne($result);
            
            if ($item) {
                $this->logger->debug("Verification status updated successfully", [
                    'item_id' => $itemId,
                    'last_verified' => $item['last_verified'],
                    'last_status' => $item['last_status']
                ]);
            } else {
                $this->logger->warning("Failed to verify update", [
                    'item_id' => $itemId
                ]);
            }
            
            // Add verification history
            $this->db->query(
                "INSERT INTO verification_history
                (protected_item_id, verification_date, status, details)
                VALUES (:item_id, :now, :status, :details)",
                [
                    ':item_id' => $itemId,
                    ':now' => $now,
                    ':status' => $status,
                    ':details' => $details
                ]
            );
            
            $this->db->commit();
            
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error("Failed to update verification status", [
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Repair damaged files
     *
     * @param string $path Path to repair
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
            $item = $this->getProtectedItem($path, $preferDirectory);
            
            if (!$item) {
                throw ApiException::notFound("Item not found in protected items: $path");
            }
            
            // Check if par2 file exists
            $par2Path = $item['par2_path'];
            if (!file_exists($par2Path)) {
                throw ApiException::badRequest("Par2 file not found: $par2Path");
            }
            
            // Repair files
            $repairResult = $this->repairPar2Files($path, $par2Path, $item['mode']);
            
            // Restore metadata after repair if requested
            if ($restoreMetadata && ($repairResult['status'] === 'REPAIRED')) {
                $this->logger->debug("Restoring metadata after repair by ID", [
                    'id' => $item['id'],
                    'path' => $path
                ]);
                
                $metadataResult = $this->restoreMetadata($item['id'], $path, $item['mode']);
                
                // Append metadata restoration results to the details
                $repairResult['details'] .= "\n\n--- Metadata Restoration ---\n" . $metadataResult['details'];
            }
            
            // Update verification status
            $this->updateVerificationStatus($item['id'], $repairResult['status'], $repairResult['details']);
            
            // Clear cache for this item
            $this->clearVerificationCache($item['id'], $path);
            
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
            $result = $this->db->query(
                "SELECT * FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            $item = $this->db->fetchOne($result);
            
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
            $repairResult = $this->repairPar2Files($path, $par2Path, $item['mode']);
            
            // Restore metadata after repair if requested
            if ($restoreMetadata && ($repairResult['status'] === 'REPAIRED')) {
                $this->logger->debug("Restoring metadata after repair", [
                    'path' => $path,
                    'item_id' => $item['id']
                ]);
                
                $metadataResult = $this->restoreMetadata($item['id'], $path, $item['mode']);
                
                // Append metadata restoration results to the details
                $repairResult['details'] .= "\n\n--- Metadata Restoration ---\n" . $metadataResult['details'];
            }
            
            // Update verification status
            $this->updateVerificationStatus($item['id'], $repairResult['status'], $repairResult['details']);
            
            // Clear cache for this item
            $this->clearVerificationCache($item['id'], $path);
            
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
    
    /**
     * Repair par2 files
     *
     * @param string $path Path to repair
     * @param string $par2Path Path to par2 file
     * @param string $mode Repair mode (file or directory)
     * @return array
     */
    private function repairPar2Files($path, $par2Path, $mode) {
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
            
            // Get configuration settings
            $config = \Par2Protect\Core\Config::getInstance();
            
            // Build par2 command with resource limit parameters
            $command = "par2 repair -q -B\"$basePath\" \"$par2Path\"";
            
            // Add -t parameter for CPU threads if set
            $maxCpuThreads = $config->get('resource_limits.max_cpu_usage');
            if ($maxCpuThreads) {
                $command .= " -t$maxCpuThreads";
            }
            
            // Add -m parameter for memory usage if set
            $maxMemory = $config->get('resource_limits.max_memory_usage');
            if ($maxMemory) {
                $command .= " -m$maxMemory";
            }
            
            // Add -T parameter for parallel file hashing if set
            $parallelFileHashing = $config->get('resource_limits.parallel_file_hashing');
            if ($parallelFileHashing) {
                $command .= " -T$parallelFileHashing";
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
                $command = "ionice -c $ioniceClass -n $ioniceLevel " . $command;
            }
            
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
                // Get configuration settings
                $config = \Par2Protect\Core\Config::getInstance();
                
                // Build par2 command with resource limit parameters
                $command = "par2 repair -q -B\"$basePath\" \"$par2File\"";
                
                // Add -t parameter for CPU threads if set
                $maxCpuThreads = $config->get('resource_limits.max_cpu_usage');
                if ($maxCpuThreads) {
                    $command .= " -t$maxCpuThreads";
                }
                
                // Add -m parameter for memory usage if set
                $maxMemory = $config->get('resource_limits.max_memory_usage');
                if ($maxMemory) {
                    $command .= " -m$maxMemory";
                }
                
                // Add -T parameter for parallel file hashing if set
                $parallelFileHashing = $config->get('resource_limits.parallel_file_hashing');
                if ($parallelFileHashing) {
                    $command .= " -T$parallelFileHashing";
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
                    $command = "ionice -c $ioniceClass -n $ioniceLevel " . $command;
                }
                
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
     * Verify file metadata
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Path to verify
     * @param string $mode Verification mode (file or directory)
     * @param bool $autoRestore Whether to automatically restore metadata if discrepancies are found
     * @return array
     */
    private function verifyMetadata($protectedItemId, $path, $mode, $autoRestore = false) {
        $this->logger->debug("Verifying file metadata", [
            'protected_item_id' => $protectedItemId,
            'path' => $path,
            'mode' => $mode,
            'auto_restore' => $autoRestore ? 'true' : 'false'
        ]);
        
        try {
            // Get stored metadata for this protected item
            $result = $this->db->query(
                "SELECT * FROM file_metadata WHERE protected_item_id = :protected_item_id",
                [':protected_item_id' => $protectedItemId]
            );
            $storedMetadata = $this->db->fetchAll($result);
            
            if (empty($storedMetadata)) {
                return [
                    'status' => 'NO_METADATA',
                    'details' => "No metadata found for this protected item."
                ];
            }
            
            $this->logger->debug("Found stored metadata", [
                'protected_item_id' => $protectedItemId,
                'metadata_count' => count($storedMetadata)
            ]);
            
            // Compare current metadata with stored metadata
            $issues = [];
            $verified = 0;
            $total = 0;
            
            foreach ($storedMetadata as $metadata) {
                $filePath = $metadata['file_path'];
                $total++;
                
                // Skip if file doesn't exist
                if (!file_exists($filePath)) {
                    $issues[] = "$filePath: File does not exist";
                    continue;
                }
                
                // Get current metadata
                $currentMetadata = $this->getFileMetadata($filePath);
                
                if (!$currentMetadata) {
                    $issues[] = "$filePath: Failed to get current metadata";
                    continue;
                }
                
                // Compare metadata
                $metadataIssues = [];
                
                // Check owner
                if ($currentMetadata['owner'] !== $metadata['owner']) {
                    $metadataIssues[] = "Owner mismatch: current={$currentMetadata['owner']}, stored={$metadata['owner']}";
                }
                
                // Check group
                if ($currentMetadata['group'] !== $metadata['group_name']) {
                    $metadataIssues[] = "Group mismatch: current={$currentMetadata['group']}, stored={$metadata['group_name']}";
                }
                
                // Check permissions
                if ($currentMetadata['permissions'] !== $metadata['permissions']) {
                    $metadataIssues[] = "Permissions mismatch: current={$currentMetadata['permissions']}, stored={$metadata['permissions']}";
                }
                
                // Check extended attributes if available
                if ($metadata['extended_attributes']) {
                    $storedAttrs = json_decode($metadata['extended_attributes'], true);
                    
                    if ($storedAttrs && is_array($storedAttrs)) {
                        if (!$currentMetadata['extended_attributes']) {
                            $metadataIssues[] = "Extended attributes missing";
                        } else {
                            foreach ($storedAttrs as $attrName => $attrValue) {
                                if (!isset($currentMetadata['extended_attributes'][$attrName]) || 
                                    $currentMetadata['extended_attributes'][$attrName] !== $attrValue) {
                                    $metadataIssues[] = "Extended attribute mismatch for $attrName";
                                }
                            }
                        }
                    }
                }
                
                // If there are issues, add to the list
                if (!empty($metadataIssues)) {
                    $issues[] = "$filePath: " . implode(", ", $metadataIssues);
                    
                    // Auto-restore if requested
                    if ($autoRestore) {
                        $this->restoreFileMetadata($filePath, $metadata);
                    }
                } else {
                    $verified++;
                }
            }
            
            // Determine status and create details
            $status = empty($issues) ? 'VERIFIED' : 'METADATA_ISSUES';
            $details = "Metadata verification: $verified/$total files verified.\n";
            
            if (!empty($issues)) {
                $details .= "Issues found:\n" . implode("\n", $issues);
                
                if ($autoRestore) {
                    $details .= "\n\nMetadata has been automatically restored.";
                }
            } else {
                $details .= "All file metadata verified successfully.";
            }
            
            return [
                'status' => $status,
                'details' => $details
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to verify metadata", [
                'protected_item_id' => $protectedItemId,
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'ERROR',
                'details' => "Error verifying metadata: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Restore metadata for all files in a protected item
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Path to restore
     * @param string $mode Restoration mode (file or directory)
     * @return array
     */
    private function restoreMetadata($protectedItemId, $path, $mode) {
        $this->logger->debug("Restoring file metadata", [
            'protected_item_id' => $protectedItemId,
            'path' => $path,
            'mode' => $mode
        ]);
        
        try {
            // Get stored metadata for this protected item
            $result = $this->db->query(
                "SELECT * FROM file_metadata WHERE protected_item_id = :protected_item_id",
                [':protected_item_id' => $protectedItemId]
            );
            $storedMetadata = $this->db->fetchAll($result);
            
            if (empty($storedMetadata)) {
                return [
                    'status' => 'NO_METADATA',
                    'details' => "No metadata found for this protected item."
                ];
            }
            
            $this->logger->debug("Found stored metadata for restoration", [
                'protected_item_id' => $protectedItemId,
                'metadata_count' => count($storedMetadata)
            ]);
            
            // Restore metadata for each file
            $restored = 0;
            $failed = 0;
            $skipped = 0;
            $details = [];
            
            foreach ($storedMetadata as $metadata) {
                $filePath = $metadata['file_path'];
                
                // Skip if file doesn't exist
                if (!file_exists($filePath)) {
                    $details[] = "$filePath: Skipped (file does not exist)";
                    $skipped++;
                    continue;
                }
                
                // Restore metadata for this file
                $result = $this->restoreFileMetadata($filePath, $metadata);
                
                if ($result) {
                    $restored++;
                    $details[] = "$filePath: Metadata restored";
                } else {
                    $failed++;
                    $details[] = "$filePath: Failed to restore metadata";
                }
            }
            
            // Create summary
            $summary = "Metadata restoration: $restored files restored, $failed failed, $skipped skipped.\n";
            if (!empty($details)) {
                $summary .= implode("\n", $details);
            }
            
            return [
                'status' => $failed > 0 ? 'PARTIAL_RESTORE' : 'RESTORED',
                'details' => $summary
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to restore metadata", [
                'protected_item_id' => $protectedItemId,
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'ERROR',
                'details' => "Error restoring metadata: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Restore metadata for a single file
     *
     * @param string $filePath Path to the file
     * @param array $metadata Stored metadata
     * @return bool
     */
    private function restoreFileMetadata($filePath, $metadata) {
        try {
            $this->logger->debug("Restoring metadata for file", [
                'file_path' => $filePath
            ]);
            
            // Restore owner and group
            $chownCommand = "chown " . escapeshellarg($metadata['owner'] . ":" . $metadata['group_name']) . " " . escapeshellarg($filePath);
            exec($chownCommand, $chownOutput, $chownReturnCode);
            
            if ($chownReturnCode !== 0) {
                $this->logger->warning("Failed to restore owner/group", [
                    'file_path' => $filePath,
                    'command' => $chownCommand,
                    'return_code' => $chownReturnCode
                ]);
                return false;
            }
            
            // Restore permissions
            $chmodCommand = "chmod " . escapeshellarg($metadata['permissions']) . " " . escapeshellarg($filePath);
            exec($chmodCommand, $chmodOutput, $chmodReturnCode);
            
            if ($chmodReturnCode !== 0) {
                $this->logger->warning("Failed to restore permissions", [
                    'file_path' => $filePath,
                    'command' => $chmodCommand,
                    'return_code' => $chmodReturnCode
                ]);
                return false;
            }
            
            // Restore extended attributes if available
            if ($metadata['extended_attributes']) {
                $extendedAttributes = json_decode($metadata['extended_attributes'], true);
                
                if ($extendedAttributes && is_array($extendedAttributes)) {
                    foreach ($extendedAttributes as $attrName => $attrValue) {
                        $setfattrCommand = "setfattr -n " . escapeshellarg($attrName) . " -v " . escapeshellarg($attrValue) . " " . escapeshellarg($filePath);
                        exec($setfattrCommand, $setfattrOutput, $setfattrReturnCode);
                        
                        if ($setfattrReturnCode !== 0) {
                            $this->logger->warning("Failed to restore extended attribute", [
                                'file_path' => $filePath,
                                'attribute' => $attrName,
                                'command' => $setfattrCommand,
                                'return_code' => $setfattrReturnCode
                            ]);
                            // Continue with other attributes even if one fails
                        }
                    }
                }
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Exception while restoring file metadata", [
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
    
    /**
     * Clear verification cache for an item
     *
     * @param int $itemId Protected item ID
     * @param string $path Item path
     * @return void
     */
    private function clearVerificationCache($itemId, $path) {
        $this->logger->debug("Clearing verification cache", [
            'item_id' => $itemId,
            'path' => $path
        ]);
        
        $this->cache->remove('verification_status_id_' . $itemId);
        $this->cache->remove('verification_status_' . md5($path));
    }
}