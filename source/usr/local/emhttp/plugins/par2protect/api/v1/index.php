<?php
/**
 * API Entry Point
 * 
 * This file handles all API requests and routes them to the appropriate endpoint.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../../core/bootstrap.php');

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

// Get logger
$logger = Logger::getInstance();
$logger->debug("API request received", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'query' => $_GET,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none'
]);

// Create router
$router = new Router();

// Load routes
require_once(__DIR__ . '/routes.php');

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
        
        $logger->debug("Routing to endpoint via parameter", [
            'endpoint' => $endpoint,
            'uri' => $uri,
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        
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
    
    // Return generic error response
    Response::json([
        'success' => false,
        'error' => [
            'code' => 'internal_error',
            'message' => 'An unexpected error occurred'
        ]
    ], 500);
}