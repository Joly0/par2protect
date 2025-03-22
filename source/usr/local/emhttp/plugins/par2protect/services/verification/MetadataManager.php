<?php
namespace Par2Protect\Services\Verification;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;

/**
 * Manages metadata verification and restoration
 */
class MetadataManager {
    private $db;
    private $logger;
    
    /**
     * MetadataManager constructor
     *
     * @param Database $db Database instance
     * @param Logger $logger Logger instance
     */
    public function __construct($db, $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Verify metadata for a protected item
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Path to verify
     * @param string $mode Verification mode (file or directory)
     * @param bool $autoRestore Whether to automatically restore metadata if discrepancies are found
     * @return array
     */
    public function verifyMetadata($protectedItemId, $path, $mode, $autoRestore = false) {
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
    public function restoreMetadata($protectedItemId, $path, $mode) {
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
    public function restoreFileMetadata($filePath, $metadata) {
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
    public function getFileMetadata($filePath) {
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