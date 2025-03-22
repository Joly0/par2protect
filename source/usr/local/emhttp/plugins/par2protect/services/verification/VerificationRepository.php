<?php
namespace Par2Protect\Services\Verification;

use Par2Protect\Core\Database;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Cache;
use Par2Protect\Core\Exceptions\ApiException;

/**
 * Repository for verification-related database operations
 */
class VerificationRepository {
    private $db;
    private $logger;
    private $cache;
    
    /**
     * VerificationRepository constructor
     *
     * @param Database $db Database instance
     * @param Logger $logger Logger instance
     * @param Cache $cache Cache instance
     */
    public function __construct($db, $logger, $cache) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
    }
    
    /**
     * Get protected item by path
     *
     * @param string $path Path to check
     * @param bool $preferDirectory Whether to prefer directory mode
     * @return array|null
     */
    public function getProtectedItem($path, $preferDirectory = false) {
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
     * Get verification history for a protected item
     *
     * @param int $itemId Protected item ID
     * @param int $limit Maximum number of history entries to return
     * @return array
     */
    public function getVerificationHistory($itemId, $limit = 10) {
        $result = $this->db->query(
            "SELECT * FROM verification_history 
            WHERE protected_item_id = :id 
            ORDER BY verification_date DESC 
            LIMIT :limit",
            [
                ':id' => $itemId,
                ':limit' => $limit
            ]
        );
        return $this->db->fetchAll($result);
    }
    
    /**
     * Update verification status for a protected item
     *
     * @param int $itemId Protected item ID
     * @param string $status Verification status
     * @param string $details Verification details
     * @return bool
     * @throws \Exception
     */
    public function updateVerificationStatus($itemId, $status, $details) {
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
     * Clear verification cache for an item
     *
     * @param int $itemId Protected item ID
     * @param string $path Item path
     * @return void
     */
    public function clearVerificationCache($itemId, $path) {
        $this->logger->debug("Clearing verification cache", [
            'item_id' => $itemId,
            'path' => $path
        ]);
        
        $this->cache->remove('verification_status_id_' . $itemId);
        $this->cache->remove('verification_status_' . md5($path));
    }
}