<?php
namespace Par2Protect\Core; // New namespace

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Traits\ReadsFileSystemMetadata;

/**
 * Manages storage, verification, and restoration of file metadata.
 */
class MetadataManager {
    use ReadsFileSystemMetadata; // Provides getFileMetadata()

    private $db;
    private $logger;

    /**
     * Constructor
     * @param Database $db Database instance
     * @param Logger $logger Logger instance
     */
    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    // --- Metadata Storage ---

    /**
     * Collect and store metadata for a path (file or directory).
     * Iterates through directories.
     *
     * @param string $path Path to collect metadata for
     * @param string $mode Mode ('file' or 'directory')
     * @param int $protectedItemId Protected item ID
     * @return bool True on success (at least one file processed), false otherwise.
     */
    public function storeMetadata(string $path, string $mode, int $protectedItemId): bool
    {
        $this->logger->debug("Starting metadata collection for path", [
            'path' => $path,
            'mode' => $mode,
            'protected_item_id' => $protectedItemId
        ]);

        $processed = false;
        try {
            if ($mode === 'file') {
                if (file_exists($path)) {
                    $processed = $this->storeFileMetadata($path, $protectedItemId);
                } else {
                     $this->logger->warning("File not found for metadata collection", ['file_path' => $path]);
                }
            } elseif ($mode === 'directory') {
                 if (!is_dir($path)) {
                     $this->logger->error("Directory not found for metadata collection", ['path' => $path]);
                     return false;
                 }
                // For directories, collect metadata for all files within
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    // Skip directories and links for storing metadata, only store for files
                    if ($file->isFile() && !$file->isLink()) {
                         if ($this->storeFileMetadata($file->getPathname(), $protectedItemId)) {
                             $processed = true; // Mark as processed if at least one file succeeds
                         }
                    }
                }
            } else {
                 $this->logger->error("Invalid mode specified for metadata collection", ['mode' => $mode]);
                 return false;
            }
        } catch (\Exception $e) {
             $this->logger->error("Exception during metadata collection", [
                 'path' => $path,
                 'mode' => $mode,
                 'error' => $e->getMessage()
             ]);
             return false;
        }
        $this->logger->debug("Metadata collection finished", ['path' => $path, 'processed_at_least_one' => $processed]);
        return $processed;
    }

    /**
     * Collect and store metadata for a specific file.
     *
     * @param string $filePath Path to the file
     * @param int $protectedItemId Protected item ID
     * @return bool True on success, false on failure.
     */
    private function storeFileMetadata(string $filePath, int $protectedItemId): bool
    {
        try {
            // Get file metadata using the trait
            $metadata = $this->getFileMetadata($filePath);
            if (!$metadata) {
                 $this->logger->warning("Could not get metadata for file, skipping storage.", ['file_path' => $filePath]);
                 return false;
            }

            // Store metadata in database
            $this->db->query(
                "INSERT OR REPLACE INTO file_metadata
                (protected_item_id, file_path, owner, group_name, permissions, mtime, extended_attributes)
                VALUES (:protected_item_id, :file_path, :owner, :group_name, :permissions, :mtime, :extended_attributes)",
                [
                    ':protected_item_id' => $protectedItemId,
                    ':file_path' => $filePath,
                    ':owner' => $metadata['owner'],
                    ':group_name' => $metadata['group'],
                    ':permissions' => $metadata['permissions'],
                    ':mtime' => $metadata['mtime'], // Store modification time
                    ':extended_attributes' => $metadata['extended_attributes'] ? json_encode($metadata['extended_attributes']) : null
                ]
            );
             $this->logger->debug("Stored metadata for file", ['file_path' => $filePath]);
             return true;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to collect/store metadata for file", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // --- Metadata Retrieval ---

    /**
     * Get stored metadata for a protected item or a specific file within it.
     *
     * @param int $protectedItemId
     * @param string|null $filePath Optional: Specific file path to retrieve metadata for.
     * @return array Array of metadata rows or empty array if not found.
     */
    public function getStoredMetadata(int $protectedItemId, ?string $filePath = null): array
    {
        try {
            $sql = "SELECT * FROM file_metadata WHERE protected_item_id = :protected_item_id";
            $params = [':protected_item_id' => $protectedItemId];

            if ($filePath !== null) {
                $sql .= " AND file_path = :file_path";
                $params[':file_path'] = $filePath;
            }

            $result = $this->db->query($sql, $params);
            $metadata = $this->db->fetchAll($result);
            return $metadata ?: [];
        } catch (\Exception $e) {
             $this->logger->error("Failed to retrieve stored metadata", [
                 'protected_item_id' => $protectedItemId,
                 'file_path' => $filePath,
                 'error' => $e->getMessage()
             ]);
             return [];
        }
    }


    // --- Metadata Verification ---

    /**
     * Verify metadata for a protected item (file or directory).
     * Matches method name used in Verification service.
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Path to verify
     * @param string $mode Verification mode ('file' or 'directory')
     * @param bool $autoRestore Whether to automatically restore metadata if discrepancies are found
     * @return array ['status' => string, 'details' => string]
     */
    public function verifyMetadata(int $protectedItemId, string $path, string $mode, bool $autoRestore = false): array
    {
        $this->logger->debug("Verifying file metadata", [
            'protected_item_id' => $protectedItemId,
            'path' => $path,
            'mode' => $mode,
            'auto_restore' => $autoRestore
        ]);

        try {
            // Get all stored metadata for this protected item
            $allStoredMetadata = $this->getStoredMetadata($protectedItemId);

            if (empty($allStoredMetadata)) {
                return [
                    'status' => 'NO_METADATA',
                    'details' => "No stored metadata found for this protected item."
                ];
            }

            $this->logger->debug("Found stored metadata for verification", [
                'protected_item_id' => $protectedItemId,
                'metadata_count' => count($allStoredMetadata)
            ]);

            $issues = [];
            $verifiedCount = 0;
            $restoredCount = 0;
            $failedRestoreCount = 0;
            $missingCount = 0;
            $totalChecked = 0;

            foreach ($allStoredMetadata as $storedMeta) {
                $filePath = $storedMeta['file_path'];
                $totalChecked++;

                if (!file_exists($filePath)) {
                    $issues[] = "$filePath: File does not exist";
                    $missingCount++;
                    continue;
                }

                // Get current metadata using the trait
                $currentMeta = $this->getFileMetadata($filePath);

                if (!$currentMeta) {
                    $issues[] = "$filePath: Failed to get current metadata";
                    continue;
                }

                // Compare metadata
                $comparisonResult = $this->compareMetadata($storedMeta, $currentMeta);

                if (!empty($comparisonResult)) {
                    $issueDetail = "$filePath: " . implode(", ", $comparisonResult);
                    $issues[] = $issueDetail;
                    $this->logger->debug("Metadata mismatch found", ['detail' => $issueDetail]);


                    if ($autoRestore) {
                        if ($this->restoreFileMetadata($filePath, $storedMeta)) {
                            $restoredCount++;
                            $this->logger->debug("Auto-restored metadata", ['file_path' => $filePath]);
                        } else {
                            $failedRestoreCount++;
                            $issues[] = "$filePath: Auto-restore failed";
                             $this->logger->warning("Auto-restore failed", ['file_path' => $filePath]);
                        }
                    }
                } else {
                    $verifiedCount++;
                }
            }

            // Determine overall status and details
            $status = 'VERIFIED';
            $details = "Metadata verification: $verifiedCount/$totalChecked files verified.";

            if ($missingCount > 0) {
                 $status = 'MISSING_FILES'; // Prioritize missing files status
                 $details .= "\n$missingCount files listed in metadata do not exist.";
            }
            if (!empty($issues) && $status === 'VERIFIED') { // Only set if not already missing
                 $status = 'METADATA_ISSUES';
            }


            if (!empty($issues)) {
                $details .= "\nIssues found (" . count($issues) . "):\n" . implode("\n", array_slice($issues, 0, 20)); // Limit details length
                if (count($issues) > 20) {
                     $details .= "\n... (more issues truncated)";
                }

                if ($autoRestore) {
                    $details .= "\n\nAttempted auto-restore: $restoredCount successful, $failedRestoreCount failed.";
                    // If restore happened, status might be considered 'RESTORED' or still 'METADATA_ISSUES' if some failed
                    if ($restoredCount > 0 && $failedRestoreCount == 0 && $missingCount == 0) {
                         // If all issues were restorable and successful, maybe status is VERIFIED now? Or a new RESTORED status?
                         // Let's keep METADATA_ISSUES for simplicity if any issue was detected, but add note.
                         $details .= "\nMetadata may now be consistent after restore.";
                    }
                }
            } else {
                $details .= "\nAll file metadata verified successfully.";
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
     * Compares stored metadata array with current metadata array.
     *
     * @param array $stored Stored metadata row from DB.
     * @param array $current Current metadata fetched from filesystem.
     * @return array List of differences found.
     */
    private function compareMetadata(array $stored, array $current): array
    {
        $differences = [];

        // Check owner (use string comparison)
        if ((string)$current['owner'] !== (string)$stored['owner']) {
            $differences[] = "Owner mismatch: current={$current['owner']}, stored={$stored['owner']}";
        }

        // Check group (use string comparison)
        if ((string)$current['group'] !== (string)$stored['group_name']) {
            $differences[] = "Group mismatch: current={$current['group']}, stored={$stored['group_name']}";
        }

        // Check permissions (use string comparison after formatting?)
        // Ensure consistent format before comparison if needed, e.g., octal string
        if ((string)$current['permissions'] !== (string)$stored['permissions']) {
            $differences[] = "Permissions mismatch: current={$current['permissions']}, stored={$stored['permissions']}";
        }

        // Check modification time
        if ((int)$current['mtime'] !== (int)$stored['mtime']) {
             $differences[] = "Modification time mismatch: current={$current['mtime']}, stored={$stored['mtime']}";
        }

        // Check extended attributes if available
        $storedAttrs = $stored['extended_attributes'] ? json_decode($stored['extended_attributes'], true) : null;
        $currentAttrs = $current['extended_attributes']; // Already an array or null from trait

        if ($storedAttrs && is_array($storedAttrs)) {
            if (!$currentAttrs) {
                $differences[] = "Stored extended attributes missing from current file";
            } else {
                // Check if all stored attributes exist and match in current
                foreach ($storedAttrs as $attrName => $attrValue) {
                    if (!isset($currentAttrs[$attrName])) {
                        $differences[] = "Stored extended attribute '{$attrName}' missing from current file";
                    } elseif ($currentAttrs[$attrName] !== $attrValue) {
                        $differences[] = "Extended attribute '{$attrName}' mismatch"; // Avoid logging values directly
                    }
                }
                // Optionally, check if current file has extra attributes not stored
                // foreach ($currentAttrs as $attrName => $attrValue) {
                //     if (!isset($storedAttrs[$attrName])) {
                //         $differences[] = "Current file has extra extended attribute '{$attrName}' not stored";
                //     }
                // }
            }
        } elseif ($currentAttrs) {
             // Stored has no attributes, but current does
             $differences[] = "Current file has extended attributes, but none were stored";
        }

        return $differences;
    }


    // --- Metadata Restoration ---

    /**
     * Restore metadata for all files associated with a protected item.
     * Matches method name used in Verification service.
     *
     * @param int $protectedItemId Protected item ID
     * @param string $path Original path (used for logging context)
     * @param string $mode Original mode (used for logging context)
     * @return array ['status' => string, 'details' => string]
     */
    public function restoreMetadata(int $protectedItemId, string $path, string $mode): array
    {
        $this->logger->debug("Restoring file metadata", [
            'protected_item_id' => $protectedItemId,
            'path' => $path,
            'mode' => $mode
        ]);

        try {
            // Get stored metadata for this protected item
            $allStoredMetadata = $this->getStoredMetadata($protectedItemId);

            if (empty($allStoredMetadata)) {
                return [
                    'status' => 'NO_METADATA',
                    'details' => "No stored metadata found to restore for this protected item."
                ];
            }

            $this->logger->debug("Found stored metadata for restoration", [
                'protected_item_id' => $protectedItemId,
                'metadata_count' => count($allStoredMetadata)
            ]);

            $restoredCount = 0;
            $failedCount = 0;
            $missingCount = 0;
            $totalAttempted = 0;
            $errors = [];

            foreach ($allStoredMetadata as $storedMeta) {
                $filePath = $storedMeta['file_path'];
                $totalAttempted++;

                if (!file_exists($filePath)) {
                     $this->logger->warning("File not found for metadata restoration, skipping.", ['file_path' => $filePath]);
                     $missingCount++;
                     $errors[] = "$filePath: File not found";
                     continue;
                }

                if ($this->restoreFileMetadata($filePath, $storedMeta)) {
                    $restoredCount++;
                } else {
                    $failedCount++;
                    $errors[] = "$filePath: Restore failed (see logs for details)";
                }
            }

            $status = ($failedCount === 0 && $missingCount === 0) ? 'RESTORED' : 'RESTORE_FAILED';
            $details = "Metadata restoration: $restoredCount/$totalAttempted files successful.";
            if ($failedCount > 0) {
                 $details .= "\n$failedCount files failed to restore.";
            }
            if ($missingCount > 0) {
                 $details .= "\n$missingCount files were missing.";
            }
            if (!empty($errors)) {
                 $details .= "\nErrors:\n" . implode("\n", array_slice($errors, 0, 10));
                 if(count($errors) > 10) $details .= "\n...";
            }


            return [
                'status' => $status,
                'details' => $details
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
     * Restore metadata for a specific file using stored data.
     *
     * @param string $filePath Path to the file
     * @param array $metadata Stored metadata row from DB.
     * @return bool True on success, false on failure.
     */
    private function restoreFileMetadata(string $filePath, array $metadata): bool
    {
        $this->logger->debug("Attempting to restore metadata", ['file_path' => $filePath]);
        $success = true;

        try {
            // Restore Permissions
            if (isset($metadata['permissions'])) {
                // Convert stored permissions (string like '0644') to octal integer
                $octalPerms = octdec($metadata['permissions']);
                if (@chmod($filePath, $octalPerms) === false) {
                    $this->logger->warning("Failed to restore permissions", ['file' => $filePath, 'perms' => $metadata['permissions']]);
                    $success = false;
                } else {
                     $this->logger->debug("Restored permissions", ['file' => $filePath, 'perms' => $metadata['permissions']]);
                }
            }

            // Restore Owner/Group (use numeric IDs if possible, fallback to names)
            // Note: chown/chgrp often require root privileges.
            if (isset($metadata['owner'])) {
                 // Try numeric ID first if available, else name
                 $ownerIdentifier = is_numeric($metadata['owner']) ? (int)$metadata['owner'] : $metadata['owner'];
                 if (@chown($filePath, $ownerIdentifier) === false) {
                     $this->logger->warning("Failed to restore owner", ['file' => $filePath, 'owner' => $ownerIdentifier]);
                     $success = false;
                 } else {
                      $this->logger->debug("Restored owner", ['file' => $filePath, 'owner' => $ownerIdentifier]);
                 }
            }
            if (isset($metadata['group_name'])) {
                 // Try numeric ID first if available, else name
                 $groupIdentifier = is_numeric($metadata['group_name']) ? (int)$metadata['group_name'] : $metadata['group_name'];
                 if (@chgrp($filePath, $groupIdentifier) === false) {
                     $this->logger->warning("Failed to restore group", ['file' => $filePath, 'group' => $groupIdentifier]);
                     $success = false;
                 } else {
                      $this->logger->debug("Restored group", ['file' => $filePath, 'group' => $groupIdentifier]);
                 }
            }

             // Restore Modification Time
             if (isset($metadata['mtime'])) {
                 if (@touch($filePath, (int)$metadata['mtime']) === false) {
                     $this->logger->warning("Failed to restore modification time", ['file' => $filePath, 'mtime' => $metadata['mtime']]);
                     $success = false;
                 } else {
                      $this->logger->debug("Restored modification time", ['file' => $filePath, 'mtime' => $metadata['mtime']]);
                 }
             }

            // Restore Extended Attributes (if function exists and data available)
            if (function_exists('xattr_set') && isset($metadata['extended_attributes']) && $metadata['extended_attributes']) {
                $storedAttrs = json_decode($metadata['extended_attributes'], true);
                if ($storedAttrs && is_array($storedAttrs)) {
                    // Clear existing attributes first? Maybe not, just set stored ones.
                    foreach ($storedAttrs as $attrName => $attrValue) {
                        if (@xattr_set($filePath, $attrName, $attrValue) === false) {
                            $this->logger->warning("Failed to restore extended attribute", ['file' => $filePath, 'attr' => $attrName]);
                            $success = false;
                        } else {
                             $this->logger->debug("Restored extended attribute", ['file' => $filePath, 'attr' => $attrName]);
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Exception during metadata restoration", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }

        return $success;
    }


    // --- Size Calculation (Using original names for compatibility) ---

    /**
     * Get the size of a path (file or directory). Recursive for directories.
     *
     * @param string $path Path to get size for
     * @return int Size in bytes, or 0 on error.
     */
    public function getPathSize(string $path): int
    {
         if (!file_exists($path)) return 0;
        if (is_file($path)) {
            return filesize($path) ?: 0;
        }
        if (!is_dir($path)) return 0;

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
             $this->logger->warning("Failed to calculate directory size", ['path' => $path, 'error' => $e->getMessage()]);
             return 0; // Return 0 on error
        }
        return $size;
    }

    /**
     * Get the size of protected data files (can filter by type or use provided list).
     *
     * @param string $path Path (file or directory)
     * @param array|null $fileTypes Optional: Array of file extensions (lowercase) to include.
     * @param array|null $protectedFiles Optional: Pre-defined list of file paths to calculate size for.
     * @return int Size in bytes, or 0 on error.
     */
    public function getDataSize(string $path, ?array $fileTypes = null, ?array $protectedFiles = null): int
    {
         if (!file_exists($path)) return 0;
        // If protected files list is provided, use it
        if ($protectedFiles && is_array($protectedFiles)) {
            $size = 0;
            foreach ($protectedFiles as $file) {
                if (file_exists($file) && is_file($file)) {
                    $size += filesize($file) ?: 0;
                }
            }
            return $size;
        }

        // If path is a file
        if (is_file($path)) {
             // Apply file type filter if provided
             if ($fileTypes && !empty($fileTypes)) {
                 $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                 if (!in_array($extension, $fileTypes)) {
                     return 0; // File type doesn't match filter
                 }
             }
            return filesize($path) ?: 0;
        }

        // If path is a directory
        if (!is_dir($path)) return 0;

        $size = 0;
         try {
             $iterator = new \RecursiveIteratorIterator(
                 new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                 \RecursiveIteratorIterator::CHILD_FIRST
             );

             foreach ($iterator as $file) {
                 if ($file->isFile() && !$file->isLink()) {
                     // If file types are specified, check if file matches
                     if ($fileTypes && !empty($fileTypes)) {
                         $extension = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
                         if (!in_array($extension, $fileTypes)) {
                             continue;
                         }
                     }
                     $size += $file->getSize();
                 }
             }
         } catch (\Exception $e) {
              $this->logger->warning("Failed to calculate data size", ['path' => $path, 'error' => $e->getMessage()]);
              return 0; // Return 0 on error
         }
        return $size;
    }

    /**
     * Get the total size of PAR2 files associated with a given path.
     * Handles cases where par2Path is a directory or a specific .par2 file.
     *
     * @param string $par2Path Path to PAR2 file or directory containing PAR2 files.
     * @return int Size in bytes, or 0 if path doesn't exist.
     */
    public function getPar2Size(string $par2Path): int
    {
        if (!file_exists($par2Path)) {
            return 0;
        }

        $size = 0;
        try {
            if (is_file($par2Path)) {
                // If par2Path is a file, find related files (e.g., *.par2, *.vol*)
                $dir = dirname($par2Path);
                $baseName = basename($par2Path);
                // Extract base name without .par2 extension if present
                if (strtolower(substr($baseName, -5)) === '.par2') {
                     $baseName = substr($baseName, 0, -5);
                }

                // Glob for all related par2 files (index and volumes)
                $pattern = "$dir/" . preg_quote($baseName, '/') . "*.par2";
                foreach (glob($pattern) as $file) {
                    if (is_file($file)) {
                        $size += filesize($file) ?: 0;
                    }
                }
            } elseif (is_dir($par2Path)) {
                // If par2Path is a directory, sum size of all .par2 files within it
                 $iterator = new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator($par2Path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
                 );
                 foreach ($iterator as $file) {
                     if ($file->isFile() && !$file->isLink() && strtolower($file->getExtension()) === 'par2') {
                         $size += $file->getSize();
                     }
                 }
            }
        } catch (\Exception $e) {
             $this->logger->warning("Failed to calculate PAR2 size", ['par2Path' => $par2Path, 'error' => $e->getMessage()]);
             return 0; // Return 0 on error
        }
        return $size;
    }
}