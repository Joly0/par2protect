<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Database;
use Par2Protect\Core\EventSystem;

class EventsEndpoint {
    private $logger;
    private $db;
    private $eventSystem;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->db = Database::getInstance();
        $this->eventSystem = EventSystem::getInstance();
    }
    
    public function getEvents() {
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // For Nginx
        
        // Prevent output buffering
        if (ob_get_level()) ob_end_clean();
        
        // Send an initial comment to establish the connection
        echo ": " . str_repeat(" ", 2048) . "\n\n";
        flush();
        
        // Get the last event ID from the request
        $lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ?
            intval($_SERVER['HTTP_LAST_EVENT_ID']) : 0;
        
        // Keep the connection open
        set_time_limit(0); // Disable time limit
        
        while (true) {
            // Check for new events
            $events = $this->eventSystem->getEvents($lastEventId);
            
            if (!empty($events)) {
                foreach ($events as $event) {
                    echo "id: " . $event['id'] . "\n";
                    echo "event: " . $event['type'] . "\n";
                    echo "data: " . $event['data'] . "\n\n";
                    $lastEventId = $event['id'];
                }
                flush();
            }
            
            // Sleep to prevent CPU usage - use a longer sleep time to reduce log spam
            sleep(3);
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        exit();
    }
}