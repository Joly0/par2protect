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
    
    public function __construct(
        Logger $logger,
        Database $db,
        EventSystem $eventSystem
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->eventSystem = $eventSystem;
        
        // Endpoint instantiation is not logged to reduce noise
    }
    
    public function getEvents() {
        // SSE connection establishment is not logged to reduce noise
        
        // Headers and initial flush are now handled in api/v1/index.php for the events endpoint
        
        // Get the last event ID from the request
        $lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ?
            intval($_SERVER['HTTP_LAST_EVENT_ID']) : 0;
        
        // Keep the connection open but with a time limit to prevent Nginx timeouts
        set_time_limit(0); // Disable PHP time limit
        
        // Set maximum connection time to 5 minutes (300 seconds)
        // This is a balance between keeping connections open longer and
        // still allowing for reconnection to handle potential issues
        $maxConnectionTime = 300; // seconds (5 minutes)
        $startTime = time();
        $reconnectDelay = 1; // Recommend 1 second reconnect delay to client
        $lastKeepAliveTime = $startTime;
        $keepAliveInterval = 45; // Send keepalive every 45 seconds
        
        // Send initial retry directive to client
        echo "retry: " . ($reconnectDelay * 1000) . "\n\n"; // in milliseconds
        flush();
        
        // Instead of infinite loop, use a time-limited loop
        while ((time() - $startTime) < $maxConnectionTime) {
            $currentTime = time();
            
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
            
            // Send a keep-alive comment every keepAliveInterval seconds
            // This helps keep the connection alive through proxies and load balancers
            if (($currentTime - $lastKeepAliveTime) >= $keepAliveInterval) {
                // Send a named keepalive event instead of a comment
                echo "event: keepalive\n";
                echo "data: " . json_encode(['timestamp' => $currentTime]) . "\n\n";
                flush();
                $lastKeepAliveTime = $currentTime;
            }
            
            // Sleep to prevent CPU usage - shorter sleep time for more responsiveness
            // Using 1 second instead of 2 for more responsive keepalives
            sleep(1);
            
            // Check if client disconnected
            if (connection_aborted()) {
                // Client disconnection is not logged to reduce noise
                break;
            }
        }
        
        // Log connection timeout - this is now a normal part of the connection lifecycle
        // rather than an error condition since we're using a longer timeout with keepalives
        $this->logger->debug("EventsEndpoint::getEvents - SSE connection reached max time limit, sending reconnect message", [
            'file' => 'EventsEndpoint.php',
            'method' => 'getEvents',
            'reason' => 'max_time_reached',
            'max_time_seconds' => $maxConnectionTime,
            'actual_time_seconds' => (time() - $startTime),
            'keepalives_sent' => floor((time() - $startTime) / $keepAliveInterval),
            '_dashboard' => false
        ]);
        
        // Send a reconnect message before closing
        // This is a controlled reconnection to maintain a healthy connection
        echo "event: reconnect\n";
        echo "data: " . json_encode([
            "message" => "Connection max time reached, reconnecting for a fresh connection",
            "max_time_seconds" => $maxConnectionTime,
            "keepalive_interval" => $keepAliveInterval
        ]) . "\n\n";
        flush();
        
        exit();
    }
}