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
     */
    public function __construct(Database $db, Logger $logger, Cache $cache) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->initializeTables();
    }

    /**
     * Initialize database tables
     */
    public function initializeTables() {
        // Create protected_items table if it doesn't exist
        if (!$this->db->tableExists('protected_items')) {
            $this->logger->debug("Creating protected_items table");
            $this->db->query("
                CREATE TABLE protected_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT NOT NULL,
                    mode TEXT NOT NULL,
                    redundancy INTEGER NOT NULL,
                    protected_date DATETIME NOT NULL,
                    last_verified DATETIME,
                    last_status TEXT,
                    last_details TEXT,
                    size INTEGER,
                    par2_size INTEGER,
                    data_size INTEGER,
                    par2_path TEXT,
                    file_types TEXT,
                    parent_dir TEXT,
                    protected_files TEXT,
                    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                    UNIQUE(path, mode) WHERE mode = 'directory',
                    UNIQUE(path, file_types) WHERE file_types IS NOT NULL
                )
            ");
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
                    FOREIGN KEY (protected_item_id) REFERENCES protected_items(id) ON DELETE CASCADE
                )
            ");
             $this->db->query("CREATE INDEX IF NOT EXISTS idx_vh_protected_item_id ON verification_history(protected_item_id)");
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
                    mtime INTEGER,
                    extended_attributes TEXT,
                    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                    FOREIGN KEY (protected_item_id) REFERENCES protected_items(id) ON DELETE CASCADE,
                    UNIQUE(protected_item_id, file_path)
                )
            ");
            $this->db->query("CREATE INDEX idx_file_metadata_protected_item_id ON file_metadata(protected_item_id)");
            $this->db->query("CREATE INDEX idx_file_metadata_file_path ON file_metadata(file_path)");
        }
         // Add columns if they don't exist (migration)
         $this->addColumnIfNotExists('protected_items', 'last_details', 'TEXT');
         $this->addColumnIfNotExists('file_metadata', 'mtime', 'INTEGER');
         // Check/warn about FK
         $this->updateForeignKeyIfNotExists('verification_history', 'protected_items', 'protected_item_id', 'id', 'CASCADE');
    }

     /** Helper function to add a column if it doesn't exist */
     private function addColumnIfNotExists($tableName, $columnName, $columnType) {
         $columnsResult = $this->db->query("PRAGMA table_info($tableName)");
         $columnExists = false;
         while ($column = $columnsResult->fetchArray(SQLITE3_ASSOC)) {
             if (isset($column['name']) && $column['name'] === $columnName) { $columnExists = true; break; }
         }
         if (!$columnExists) {
             $this->logger->debug("Adding column $columnName to $tableName table...");
             try { $this->db->query("ALTER TABLE $tableName ADD COLUMN $columnName $columnType"); $this->logger->debug("Column $columnName added successfully."); }
             catch (\Exception $e) { $this->logger->error("Failed to add column $columnName to $tableName", ['error' => $e->getMessage()]); }
         }
     }

     /** Helper function to update foreign key constraints */
     private function updateForeignKeyIfNotExists($tableName, $referencedTable, $foreignKeyColumn, $referencedColumn, $onDeleteAction) {
         $foreignKeysResult = $this->db->query("PRAGMA foreign_key_list($tableName)");
         $fkExists = false; $correctAction = false;
         while ($fk = $foreignKeysResult->fetchArray(SQLITE3_ASSOC)) {
             if ($fk['table'] === $referencedTable && $fk['from'] === $foreignKeyColumn && $fk['to'] === $referencedColumn) {
                 $fkExists = true; if (strtoupper($fk['on_delete']) === strtoupper($onDeleteAction)) { $correctAction = true; } break;
             }
         }
         if (!$fkExists || !$correctAction) { $this->logger->warning("Foreign key constraint for $tableName.$foreignKeyColumn might be missing or incorrect (requires ON DELETE $onDeleteAction). Manual intervention might be needed."); }
     }

    /** Find a protected item by ID */
    public function findById($id) {
        $result = $this->db->query("SELECT * FROM protected_items WHERE id = :id", [':id' => $id]);
        return $this->db->fetchOne($result);
    }

    /** Find a protected item by path */
    public function findByPath($path) {
        $result = $this->db->query("SELECT * FROM protected_items WHERE path = :path", [':path' => $path]);
        return $this->db->fetchOne($result);
    }

    /** Find a protected item with specific file types */
    public function findWithFileTypes($path, $fileTypes) {
        $fileTypesJson = json_encode($fileTypes);
        $result = $this->db->query("SELECT * FROM protected_items WHERE path = :path AND file_types = :file_types", [':path' => $path, ':file_types' => $fileTypesJson]);
        return $this->db->fetchOne($result);
    }

    /** Get all protected items */
    public function findAllProtectedItems() {
        $result = $this->db->query("SELECT * FROM protected_items ORDER BY protected_date DESC");
        $items = $this->db->fetchAll($result);
        $filteredItems = []; $parentDirsWithIndividualFiles = [];
        foreach ($items as $item) { if ($item['parent_dir']) { $parentDirsWithIndividualFiles[$item['parent_dir']] = true; } }
        foreach ($items as $item) { if ($item['mode'] === 'directory' && isset($parentDirsWithIndividualFiles[$item['path']])) { continue; } $filteredItems[] = $item; }
        return $filteredItems;
    }

    /** Find individual files for a parent directory */
    public function findIndividualFiles($parentDir) {
        $result = $this->db->query("SELECT * FROM protected_items WHERE parent_dir = :parent_dir ORDER BY path", [':parent_dir' => $parentDir]);
        return $this->db->fetchAll($result);
    }

    /** Add a new protected item or update if exists */
    public function addProtectedItem($path, $mode, $redundancy, $par2Path, $fileTypes = null, $parentDir = null, $protectedFiles = null, $fileCategories = null) {
        try {
            $fileTypesJson = $fileTypes ? json_encode($fileTypes) : null;
            $protectedFilesJson = $protectedFiles ? json_encode($protectedFiles) : null;
            $now = date('Y-m-d H:i:s');
            $existingItem = null;
            if ($mode === 'directory' && $fileTypesJson !== null) { $existingItem = $this->findWithFileTypes($path, $fileTypes); }
            else if ($mode === 'directory') { $result = $this->db->query("SELECT * FROM protected_items WHERE path = :path AND mode = 'directory'", [':path' => $path]); $existingItem = $this->db->fetchOne($result); }
            else { $existingItem = $this->findByPath($path); }

            if ($existingItem) {
                $this->db->query(
                    "UPDATE protected_items SET mode = :mode, redundancy = :redundancy, protected_date = :protected_date, par2_path = :par2_path, file_types = :file_types, parent_dir = :parent_dir, protected_files = :protected_files, last_status = 'PROTECTED', last_verified = NULL, last_details = NULL WHERE id = :id",
                    [':id' => $existingItem['id'], ':mode' => $mode, ':redundancy' => $redundancy, ':protected_date' => $now, ':par2_path' => $par2Path, ':file_types' => $fileTypesJson, ':parent_dir' => $parentDir, ':protected_files' => $protectedFilesJson]
                );
                $this->logger->debug("Updated existing protected item", ['id' => $existingItem['id'], 'path' => $path]);
                $this->clearProtectedItemsCache();
                return $existingItem['id'];
            } else {
                $this->db->query(
                    "INSERT INTO protected_items (path, mode, redundancy, protected_date, par2_path, file_types, parent_dir, protected_files, last_status) VALUES (:path, :mode, :redundancy, :protected_date, :par2_path, :file_types, :parent_dir, :protected_files, 'PROTECTED')",
                    [':path' => $path, ':mode' => $mode, ':redundancy' => $redundancy, ':protected_date' => $now, ':par2_path' => $par2Path, ':file_types' => $fileTypesJson, ':parent_dir' => $parentDir, ':protected_files' => $protectedFilesJson]
                );
                $id = $this->db->lastInsertId();
                $this->logger->debug("Added new protected item", ['id' => $id, 'path' => $path]);
                $this->clearProtectedItemsCache();
                return $id;
            }
        } catch (\Exception $e) { $this->logger->error("Failed to add/update protected item", ['path' => $path, 'error' => $e->getMessage()]); throw $e; }
    }

    /** Update the status and details of a protected item */
    public function updateStatus($id, $status, $details = null) {
        try {
            $this->db->beginTransaction();
            $now = date('Y-m-d H:i:s');
            $this->logger->debug("Updating verification status", ['item_id' => $id, 'status' => $status]);
            $this->db->query(
                "UPDATE protected_items SET last_verified = :now, last_status = :status, last_details = :details WHERE id = :id",
                [':id' => $id, ':now' => $now, ':status' => $status, ':details' => $details]
            );
            $this->db->query(
                "INSERT INTO verification_history (protected_item_id, verification_date, status, details) VALUES (:id, :date, :status, :details)",
                [':id' => $id, ':date' => $now, ':status' => $status, ':details' => $details]
            );
            $this->db->commit();
            $this->clearProtectedItemsCache();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) { $this->db->rollback(); }
            $this->logger->error("Failed to update verification status", ['id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Update size information for a protected item */
    public function updateSizeInfo($itemId, $dataSize, $par2Size) {
        try {
            $totalSize = ($dataSize ?? 0) + ($par2Size ?? 0);
            $this->db->query(
                "UPDATE protected_items SET data_size = :data_size, par2_size = :par2_size, size = :total_size WHERE id = :id",
                [':id' => $itemId, ':data_size' => $dataSize, ':par2_size' => $par2Size, ':total_size' => $totalSize]
            );
            $this->logger->debug("Updated size info for item", ['id' => $itemId, 'data_size' => $dataSize, 'par2_size' => $par2Size]);
            $this->clearProtectedItemsCache();
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to update size info for item", ['id' => $itemId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Remove a protected item by ID */
    public function removeItem($id) {
        try {
            $this->db->beginTransaction();
            $this->db->query("DELETE FROM verification_history WHERE protected_item_id = :id", [':id' => $id]);
            $this->db->query("DELETE FROM file_metadata WHERE protected_item_id = :id", [':id' => $id]);
            $changes = $this->db->query("DELETE FROM protected_items WHERE id = :id", [':id' => $id]);
            $this->db->commit();
            if ($changes > 0) { $this->logger->debug("Removed protected item", ['id' => $id]); $this->clearProtectedItemsCache(); return true; }
            else { $this->logger->warning("Attempted to remove item ID that doesn't exist?", ['id' => $id]); return false; }
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) { $this->db->rollback(); }
            $this->logger->error("Failed to remove protected item", ['id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Clear cache for protected items */
    public function clearProtectedItemsCache() {
        $this->cache->remove('protected_items_all');
        $this->logger->debug("Cleared protected items cache");
    }

    /** Find redundancy levels for multiple protected item IDs */
    public function findRedundancyByIds(array $itemIds): array {
        if (empty($itemIds)) { return []; }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = "SELECT id, redundancy FROM protected_items WHERE id IN ($placeholders)";
        try {
            $stmt = $this->db->getSQLite()->prepare($sql);
            if (!$stmt) { throw new \Exception("Failed to prepare statement: " . $this->db->getSQLite()->lastErrorMsg()); }
            foreach ($itemIds as $index => $id) { $stmt->bindValue($index + 1, $id, SQLITE3_INTEGER); }
            $result = $stmt->execute();
            if (!$result) { throw new \Exception("Failed to execute statement: " . $this->db->getSQLite()->lastErrorMsg()); }
            $redundancies = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $redundancies[$row['id']] = $row['redundancy']; }
            $result->finalize();
            return $redundancies;
        } catch (\Exception $e) {
            $this->logger->error("Failed to find redundancy by IDs", ['ids_count' => count($itemIds), 'error' => $e->getMessage()]);
            return [];
        }
    }

} // End of class ProtectionRepository