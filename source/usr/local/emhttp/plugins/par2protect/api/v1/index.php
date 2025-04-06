<?php
/**
 * API Entry Point
 * 
 * This file handles all API requests and routes them to the appropriate endpoint.
 */

// Load bootstrap
// $bootstrap now holds the container instance
$container = require_once(__DIR__ . '/../../core/bootstrap.php');

use Par2Protect\Api\V1\Router;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Response;

// Set up CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Max-Age: 86400'); // 24 hours
    header('Content-Length: 0');
    http_response_code(204); // No content
    exit;
}

// Get logger from container
$logger = $container->get('logger');
// Generate a unique request ID that will be used throughout the request lifecycle
$requestId = 'req_' . bin2hex(random_bytes(8));
// Make the request ID available globally
$GLOBALS['requestId'] = $requestId;

// Only log basic request info for non-automatic requests
// This prevents excessive logging from frequent automatic refreshes
$isAutoRequest = isset($_GET['_manual']) && $_GET['_manual'] === 'false';
$isRefreshRequest = isset($_GET['_caller']) && ($_GET['_caller'] === 'refreshProtectedList' || $_GET['_caller'] === 'updateStatus');

// Only log if it's a manual request or if debug mode is explicitly enabled
if ((!$isAutoRequest && !$isRefreshRequest) || (defined('DEBUG_MODE') && DEBUG_MODE)) {
    $logger->debug("API request received", [
        'request_id' => $requestId,
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'query' => $_GET,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ''
    ]);
}

// Create router
$router = new Router($container); // Pass container to router

// Load routes
require_once(__DIR__ . '/routes.php');

// Get endpoint from query parameter if available
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;

// If this is the events endpoint, send SSE headers immediately
if ($endpoint === 'events' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Set headers for SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // For Nginx

    // Prevent output buffering
    if (ob_get_level()) ob_end_clean();

    // Send an initial comment to establish the connection immediately
    // This helps satisfy the browser quickly
    echo ": SSE connection init\n\n";
    @flush(); // Use @ to suppress potential errors if output buffering is weird
    @ob_flush();
}

// Error handling
try {
    // Parse JSON request body for POST, PUT methods
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT']) && 
        isset($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $_POST = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ApiException::badRequest(
                    'Invalid JSON in request body: ' . json_last_error_msg(),
                    ['input' => substr($input, 0, 1000)]
                );
            }
        }
    }
    
    // Get endpoint from query parameter if available
    $endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;
    
    if ($endpoint) {
        // Build a simulated URI for the router
        $uri = '/plugins/par2protect/api/v1/' . $endpoint;
        
        // Add any ID parameter
        if (isset($_GET['id'])) {
            $uri .= '/' . $_GET['id'];
        }
        
        // Check for method override
        if (isset($_POST['_method'])) {
            $_SERVER['REQUEST_METHOD'] = strtoupper($_POST['_method']);
        }
        
        // Reuse the same variables we defined earlier
        // Only log routing information for non-automatic requests or in explicit debug mode
        if ((!$isAutoRequest && !$isRefreshRequest) || (defined('DEBUG_MODE') && DEBUG_MODE)) {
            $logger->debug("Routing to endpoint via parameter", [
                'endpoint' => $endpoint,
                'uri' => $uri,
                'method' => $_SERVER['REQUEST_METHOD'],
                'request_id' => $requestId
            ]);
        }
        
        // Dispatch to the endpoint
        $router->dispatch($uri);
    } else {
        // Normal dispatch using the request URI
        $router->dispatch($_SERVER['REQUEST_URI']);
    }
} catch (ApiException $e) {
    // Log API exception
    $logger->error("API Error: " . $e->getMessage(), [
        'code' => $e->getErrorCode(),
        'status_code' => $e->getStatusCode(),
        'context' => $e->getContext()
    ]);
    
    // Return formatted error response
    Response::json($e->toArray(), $e->getStatusCode());
} catch (\Exception $e) {
    // Log general exception
    $logger->error("Unhandled Exception: " . $e->getMessage(), [
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Return more detailed error response for debugging
    Response::json([
        'success' => false,
        'error' => [
            'code' => 'internal_error',
            'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], 500);
}