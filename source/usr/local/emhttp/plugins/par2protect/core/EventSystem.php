<?php
namespace Par2Protect\Core;

use Par2Protect\Core\Exceptions\DatabaseException; // Add exception namespace

class EventSystem {
    // private static $instance = null; // Removed for DI
    private $db;
    private $logger;
    private $dbPath = '/tmp/par2protect/events/events.db'; // New dedicated path
    private $busyTimeout = 5000; // Default busy timeout in ms

    // Make constructor public and inject Logger
    public function __construct(Logger $logger) {
        $this->logger = $logger;

        try {
            // Ensure the directory exists
            $dbDir = dirname($this->dbPath);
            if (!is_dir($dbDir)) {
                // Attempt to create, log error on failure but continue (init script should handle this)
                if (!@mkdir($dbDir, 0755, true)) {
                     $this->logger->warning("Failed to create events directory, relying on init script: $dbDir");
                }
            }

            // Create and configure the dedicated SQLite connection
            $this->db = new \SQLite3($this->dbPath);
            $this->db->exec('PRAGMA journal_mode = WAL;');
            $this->db->exec('PRAGMA synchronous = NORMAL;');
            $this->db->busyTimeout($this->busyTimeout);

            $this->logger->debug("EventSystem initialized with dedicated database", ['path' => $this->dbPath]);

            // Initialize the table structure
            $this->initializeEventTable();

        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize EventSystem database connection", [
                'path' => $this->dbPath,
                'error' => $e->getMessage()
            ]);
            // If connection fails, set db to null to prevent further errors
            $this->db = null;
            // Re-throw or handle critical failure? For now, log and continue.
            // Depending on requirements, might need to throw a critical exception.
        }
    }

    // Removed getInstance() method

    private function initializeEventTable() {
        if (!$this->db) return; // Don't proceed if connection failed

        try {
            // Create events table if it doesn't exist
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    data TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ");

            // Create index on created_at for faster queries
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_events_created_at
                ON events (created_at)
            ");
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize event table", [
                'error' => $e->getMessage()
            ]);
            // Consider throwing an exception here if table init is critical
        }
    }

    public function addEvent($type, $data) {
        if (!$this->db) return false; // Don't proceed if connection failed

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO events (type, data, created_at) VALUES (:type, :data, :created_at)"
            );
            if (!$stmt) {
                 throw new DatabaseException("Failed to prepare statement: " . $this->db->lastErrorMsg());
            }

            $stmt->bindValue(':type', $type, \SQLITE3_TEXT);
            $stmt->bindValue(':data', json_encode($data), \SQLITE3_TEXT);
            $stmt->bindValue(':created_at', time(), \SQLITE3_INTEGER);

            $result = $stmt->execute();
            if (!$result) {
                throw new DatabaseException("Failed to execute statement: " . $this->db->lastErrorMsg());
            }

            $eventId = $this->db->lastInsertRowID();

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
        if (!$this->db) return []; // Don't proceed if connection failed

        try {
            // Get events newer than lastId AND not older than 5 minutes
            // This prevents old completed operations from being sent to clients
            $cutoffTime = time() - 300; // 5 minutes ago

            $stmt = $this->db->prepare(
                 "SELECT * FROM events WHERE id > :last_id AND created_at > :cutoff_time ORDER BY id ASC LIMIT 100"
            );
             if (!$stmt) {
                 throw new DatabaseException("Failed to prepare statement: " . $this->db->lastErrorMsg());
            }

            $stmt->bindValue(':last_id', $lastId, \SQLITE3_INTEGER);
            $stmt->bindValue(':cutoff_time', $cutoffTime, \SQLITE3_INTEGER);

            $result = $stmt->execute();
             if (!$result) {
                throw new DatabaseException("Failed to execute statement: " . $this->db->lastErrorMsg());
            }

            $events = [];
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $events[] = $row;
            }

            return $events;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get events", [
                'error' => $e->getMessage(),
                'last_id' => $lastId
            ]);
            return [];
        }
    }

    public function cleanupOldEvents($days = 1) {
         if (!$this->db) return 0; // Don't proceed if connection failed

        try {
            // Delete events older than specified days
            $cutoff = time() - ($days * 86400);
            $stmt = $this->db->prepare(
                "DELETE FROM events WHERE created_at < :cutoff"
            );
             if (!$stmt) {
                 throw new DatabaseException("Failed to prepare statement: " . $this->db->lastErrorMsg());
            }

            $stmt->bindValue(':cutoff', $cutoff, \SQLITE3_INTEGER);

            $result = $stmt->execute();
             if (!$result) {
                throw new DatabaseException("Failed to execute statement: " . $this->db->lastErrorMsg());
            }

            return $this->db->changes();
        } catch (\Exception $e) {
            $this->logger->error("Failed to cleanup old events", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    // Add destructor to close the connection if needed
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}