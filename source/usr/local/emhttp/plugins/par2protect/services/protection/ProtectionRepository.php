<?php
namespace Par2Protect\Services\Protection;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;

/**
 * Repository class for protected items database operations
 */
class ProtectionRepository {
    private $db;
    private $logger;
    private $cache;
    
    /**
     * ProtectionRepository constructor
     *
     * @param Database $db Database instance
     * @param Logger $logger Logger instance
     * @param Cache $cache Cache instance
     */
    public function __construct(Database $db, Logger $logger, Cache $cache) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        
        // Ensure database tables exist
        $this->initializeTables();
    }
    
    /**
     * Initialize database tables
     *
     * @return void
     */
    public function initializeTables() {
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
     * Find a protected item by ID
     *
     * @param int $id Protected item ID
     * @return array|null
     */
    public function findById($id) {
        $result = $this->db->query(
            "SELECT * FROM protected_items WHERE id = :id",
            [':id' => $id]
        );
        return $this->db->fetchOne($result);
    }
    
    /**
     * Find a protected item by path
     *
     * @param string $path Path to find
     * @return array|null
     */
    public function findByPath($path) {
        $result = $this->db->query(
            "SELECT * FROM protected_items WHERE path = :path",
            [':path' => $path]
        );
        return $this->db->fetchOne($result);
    }
    
    /**
     * Find a protected item with specific file types
     *
     * @param string $path Path to find
     * @param array $fileTypes File types to filter by
     * @return array|null
     */
    public function findWithFileTypes($path, $fileTypes) {
        // Convert file types array to JSON for comparison
        $fileTypesJson = json_encode($fileTypes);
        
        $result = $this->db->query(
            "SELECT * FROM protected_items WHERE path = :path AND file_types = :file_types",
            [
                ':path' => $path,
                ':file_types' => $fileTypesJson
            ]
        );
        return $this->db->fetchOne($result);
    }
    
    /**
     * Get all protected items
     *
     * @return array
     */
    public function findAllProtectedItems() {
        // Get all items, but exclude directory entries that have individual files protected
        $result = $this->db->query("SELECT * FROM protected_items ORDER BY protected_date DESC");
        $items = $this->db->fetchAll($result);
        
        // Filter out directory entries that have individual files protected
        $filteredItems = [];
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
            
            // Add the item to the filtered list
            $filteredItems[] = $item;
        }
        
        return $filteredItems;
    }
    
    /**
     * Find individual files for a parent directory
     *
     * @param string $parentDir Parent directory path
     * @return array
     */
    public function findIndividualFiles($parentDir) {
        $result = $this->db->query(
            "SELECT * FROM protected_items WHERE parent_dir = :parent_dir ORDER BY path",
            [':parent_dir' => $parentDir]
        );
        return $this->db->fetchAll($result);
    }
    
    /**
     * Add a new protected item
     *
     * @param string $path Path to protect
     * @param string $mode Protection mode (file or directory)
     * @param int $redundancy Redundancy percentage
     * @param string $par2Path Path to PAR2 files
     * @param array $fileTypes File types to protect (for directory mode)
     * @param string $parentDir Parent directory (for individual files)
     * @param array $protectedFiles List of protected files (for individual files mode)
     * @param array $fileCategories File categories selected by the user
     * @return int Protected item ID
     */
    public function addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes = null, $parentDir = null, $protectedFiles = null, $fileCategories = null) {
        try {
            // Convert arrays to JSON
            $fileTypesJson = $fileTypes ? json_encode($fileTypes) : null;
            $protectedFilesJson = $protectedFiles ? json_encode($protectedFiles) : null;
            
            // Get current date/time
            $now = date('Y-m-d H:i:s');
            
            // Check if item already exists with the same path and file types
            $existingItem = null;
            if ($fileTypes) {
                $existingItem = $this->findWithFileTypes($path, $fileTypes);
            } else {
                $existingItem = $this->findByPath($path);
            }
            
            if ($existingItem) {
                // Update existing item
                $this->db->query(
                    "UPDATE protected_items SET 
                    mode = :mode, 
                    redundancy = :redundancy, 
                    protected_date = :protected_date, 
                    par2_path = :par2_path, 
                    file_types = :file_types,
                    parent_dir = :parent_dir,
                    protected_files = :protected_files
                    WHERE id = :id",
                    [
                        ':id' => $existingItem['id'],
                        ':mode' => $mode,
                        ':redundancy' => $redundancy,
                        ':protected_date' => $now,
                        ':par2_path' => $par2Path,
                        ':file_types' => $fileTypesJson,
                        ':parent_dir' => $parentDir,
                        ':protected_files' => $protectedFilesJson
                    ]
                );
                
                $this->logger->debug("Updated existing protected item", [
                    'id' => $existingItem['id'],
                    'path' => $path,
                    'mode' => $mode
                ]);
                
                return $existingItem['id'];
            } else {
                // Insert new item
                $this->db->query(
                    "INSERT INTO protected_items 
                    (path, mode, redundancy, protected_date, par2_path, file_types, parent_dir, protected_files, last_status) 
                    VALUES 
                    (:path, :mode, :redundancy, :protected_date, :par2_path, :file_types, :parent_dir, :protected_files, 'PROTECTED')",
                    [
                        ':path' => $path,
                        ':mode' => $mode,
                        ':redundancy' => $redundancy,
                        ':protected_date' => $now,
                        ':par2_path' => $par2Path,
                        ':file_types' => $fileTypesJson,
                        ':parent_dir' => $parentDir,
                        ':protected_files' => $protectedFilesJson
                    ]
                );
                
                $id = $this->db->lastInsertId();
                
                $this->logger->debug("Added new protected item", [
                    'id' => $id,
                    'path' => $path,
                    'mode' => $mode
                ]);
                
                return $id;
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to add protected item", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Update the status of a protected item
     *
     * @param int $id Protected item ID
     * @param string $status New status
     * @param string $details Status details
     * @return void
     */
    public function updateStatus($id, $status, $details = null) {
        try {
            // Update item status
            $this->db->query(
                "UPDATE protected_items SET 
                last_status = :status, 
                last_verified = :verified
                WHERE id = :id",
                [
                    ':id' => $id,
                    ':status' => $status,
                    ':verified' => date('Y-m-d H:i:s')
                ]
            );
            
            // Add to verification history
            $this->db->query(
                "INSERT INTO verification_history 
                (protected_item_id, verification_date, status, details) 
                VALUES 
                (:id, :date, :status, :details)",
                [
                    ':id' => $id,
                    ':date' => date('Y-m-d H:i:s'),
                    ':status' => $status,
                    ':details' => $details
                ]
            );
            
            $this->logger->debug("Updated protected item status", [
                'id' => $id,
                'status' => $status
            ]);
            
            // Clear cache for this item
            $this->clearProtectedItemsCache();
        } catch (\Exception $e) {
            $this->logger->error("Failed to update protected item status", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Remove a protected item
     *
     * @param int $id Protected item ID
     * @return void
     */
    public function removeItem($id) {
        try {
            // Delete item
            $this->db->query(
                "DELETE FROM protected_items WHERE id = :id",
                [':id' => $id]
            );
            
            // Delete verification history
            $this->db->query(
                "DELETE FROM verification_history WHERE protected_item_id = :id",
                [':id' => $id]
            );
            
            // Delete file metadata
            $this->db->query(
                "DELETE FROM file_metadata WHERE protected_item_id = :id",
                [':id' => $id]
            );
            
            $this->logger->debug("Removed protected item", [
                'id' => $id
            ]);
            
            // Clear cache
            $this->clearProtectedItemsCache();
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove protected item", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Clear cache for protected items
     *
     * @return void
     */
    public function clearProtectedItemsCache() {
        // Clear all protected items cache
        $this->cache->delete('protected_items_all');
        
        // Clear individual files cache (can't target specific ones, so clear all)
        $keys = $this->cache->getKeys('individual_files_*');
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
        
        // Clear status cache
        $keys = $this->cache->getKeys('protected_item_status_*');
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }
}