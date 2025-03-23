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
        
        // Keep the connection open but with a time limit to prevent Nginx timeouts
        set_time_limit(0); // Disable PHP time limit
        
        // Set maximum connection time to 30 seconds (below typical Nginx timeout of 60s)
        $maxConnectionTime = 30; // seconds
        $startTime = time();
        $reconnectDelay = 1; // Recommend 1 second reconnect delay to client
        
        // Send initial retry directive to client
        echo "retry: " . ($reconnectDelay * 1000) . "\n\n"; // in milliseconds
        flush();
        
        // Instead of infinite loop, use a time-limited loop
        while ((time() - $startTime) < $maxConnectionTime) {
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
            
            // Send a keep-alive comment every 10 seconds
            if ((time() - $startTime) % 10 === 0) {
                echo ": keepalive " . time() . "\n\n";
                flush();
            }
            
            // Sleep to prevent CPU usage - shorter sleep time for more responsiveness
            sleep(2);
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        // Send a reconnect message before closing
        echo "event: reconnect\n";
        echo "data: {\"message\": \"Connection timeout, please reconnect\"}\n\n";
        flush();
        
        exit();
    }
}