<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;

/**
 * Manager class for handling file metadata operations
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
    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Collect and store metadata for a path
     *
     * @param string $path Path to collect metadata for
     * @param string $mode Mode (file or directory)
     * @param int $protectedItemId Protected item ID
     * @return void
     */
    public function collectAndStoreMetadata($path, $mode, $protectedItemId) {
        $this->logger->debug("Collecting metadata for path", [
            'path' => $path,
            'mode' => $mode,
            'protected_item_id' => $protectedItemId
        ]);
        
        if ($mode === 'file') {
            // For individual files, collect metadata for the file
            $this->collectAndStoreFileMetadata($path, $protectedItemId);
        } else {
            // For directories, collect metadata for all files in the directory
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $this->collectAndStoreFileMetadata($file->getPathname(), $protectedItemId);
                }
            }
        }
    }
    
    /**
     * Collect and store metadata for a specific file
     *
     * @param string $filePath Path to the file
     * @param int $protectedItemId Protected item ID
     * @return void
     */
    public function collectAndStoreFileMetadata($filePath, $protectedItemId) {
        try {
            // Get file metadata
            $metadata = $this->getFileMetadata($filePath);
            
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
        } catch (\Exception $e) {
            $this->logger->warning("Failed to collect metadata for file", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get metadata for a file
     *
     * @param string $filePath Path to the file
     * @return array
     */
    public function getFileMetadata($filePath) {
        // Get file owner and group
        $owner = posix_getpwuid(fileowner($filePath))['name'] ?? 'unknown';
        $group = posix_getgrgid(filegroup($filePath))['name'] ?? 'unknown';
        
        // Get file permissions
        $perms = fileperms($filePath);
        $permissions = '';
        
        // Owner
        $permissions .= (($perms & 0x0100) ? 'r' : '-');
        $permissions .= (($perms & 0x0080) ? 'w' : '-');
        $permissions .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));
        
        // Group
        $permissions .= (($perms & 0x0020) ? 'r' : '-');
        $permissions .= (($perms & 0x0010) ? 'w' : '-');
        $permissions .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));
        
        // World
        $permissions .= (($perms & 0x0004) ? 'r' : '-');
        $permissions .= (($perms & 0x0002) ? 'w' : '-');
        $permissions .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));
        
        // Get extended attributes if available
        $extendedAttributes = null;
        if (function_exists('xattr_list')) {
            try {
                $attrs = xattr_list($filePath);
                if ($attrs && !empty($attrs)) {
                    $extendedAttributes = [];
                    foreach ($attrs as $attr) {
                        $extendedAttributes[$attr] = xattr_get($filePath, $attr);
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors with extended attributes
            }
        }
        
        return [
            'owner' => $owner,
            'group' => $group,
            'permissions' => $permissions,
            'extended_attributes' => $extendedAttributes
        ];
    }
    
    /**
     * Get the size of a path (file or directory)
     *
     * @param string $path Path to get size for
     * @return int Size in bytes
     */
    public function getPathSize($path) {
        if (is_file($path)) {
            return filesize($path);
        }
        
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
    
    /**
     * Get the size of protected data
     *
     * @param string $path Path to get size for
     * @param array $fileTypes File types to filter by
     * @param array $protectedFiles Protected files list
     * @return int Size in bytes
     */
    public function getDataSize($path, $fileTypes = null, $protectedFiles = null) {
        // If protected files list is provided, use it
        if ($protectedFiles && is_array($protectedFiles)) {
            $size = 0;
            foreach ($protectedFiles as $file) {
                if (file_exists($file)) {
                    $size += filesize($file);
                }
            }
            return $size;
        }
        
        // If path is a file, return its size
        if (is_file($path)) {
            return filesize($path);
        }
        
        // If path is a directory, calculate size based on file types
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
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
        
        return $size;
    }
    
    /**
     * Get the size of PAR2 files
     *
     * @param string $par2Path Path to PAR2 files
     * @return int Size in bytes
     */
    public function getPar2Size($par2Path) {
        if (!file_exists($par2Path)) {
            return 0;
        }
        
        if (is_file($par2Path)) {
            // If par2Path is a file, get its size and the size of related PAR2 files
            $dir = dirname($par2Path);
            $baseName = basename($par2Path, '.par2');
            $size = 0;
            
            foreach (glob("$dir/$baseName*.par2") as $file) {
                $size += filesize($file);
            }
            
            return $size;
        }
        
        // If par2Path is a directory, get the size of all PAR2 files in it
        $size = 0;
        foreach (glob("$par2Path/*.par2") as $file) {
            $size += filesize($file);
        }
        
        return $size;
    }
}