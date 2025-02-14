<?php
namespace Par2Protect\Core;

class EventSystem {
    private static $instance = null;
    private $db;
    private $logger;
    
    private function __construct() {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->initializeEventTable();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initializeEventTable() {
        try {
            // Create events table if it doesn't exist
            $this->db->query("
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    data TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ");
            
            // Create index on created_at for faster queries
            $this->db->query("
                CREATE INDEX IF NOT EXISTS idx_events_created_at
                ON events (created_at)
            ");
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize event table", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function addEvent($type, $data) {
        try {
            // Add event to database
            $this->db->query(
                "INSERT INTO events (type, data, created_at) VALUES (:type, :data, :created_at)",
                [
                    ':type' => $type,
                    ':data' => json_encode($data),
                    ':created_at' => time()
                ]
            );
            
            $eventId = $this->db->lastInsertId();
            
            $this->logger->debug("Event added", [
                'id' => $eventId,
                'type' => $type
            ]);
            
            return $eventId;
        } catch (\Exception $e) {
            $this->logger->error("Failed to add event", [
                'error' => $e->getMessage(),
                'type' => $type
            ]);
            return false;
        }
    }
    
    public function getEvents($lastId = 0) {
        try {
            // Get events newer than lastId
            $result = $this->db->query(
                "SELECT * FROM events WHERE id > :last_id ORDER BY id ASC LIMIT 100",
                [':last_id' => $lastId]
            );
            
            return $this->db->fetchAll($result);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get events", [
                'error' => $e->getMessage(),
                'last_id' => $lastId
            ]);
            return [];
        }
    }
    
    public function cleanupOldEvents($days = 1) {
        try {
            // Delete events older than specified days
            $cutoff = time() - ($days * 86400);
            $this->db->query(
                "DELETE FROM events WHERE created_at < :cutoff",
                [':cutoff' => $cutoff]
            );
            
            return $this->db->changes();
        } catch (\Exception $e) {
            $this->logger->error("Failed to cleanup old events", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}