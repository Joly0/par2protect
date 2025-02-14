<?php
/**
 * API Entry Point
 * 
 * This file handles all API requests and routes them to the appropriate version.
 */

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

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'];

// Check if this is a v1 API request
if (strpos($requestUri, '/plugins/par2protect/api/v1') === 0) {
    // Route to v1 API
    require_once(__DIR__ . '/v1/index.php');
    exit;
}

// For backward compatibility, route other requests to appropriate endpoints
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove base path
$basePath = '/plugins/par2protect/api';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Ensure path starts with /
if (empty($path) || $path[0] !== '/') {
    $path = '/' . $path;
}

// Route based on path
switch (true) {
    // Status endpoint
    case $path === '/status.php':
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Queue endpoints
    case strpos($path, '/queue/') === 0:
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Protection endpoints
    case strpos($path, '/protection/') === 0:
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Verification endpoints
    case strpos($path, '/verification/') === 0:
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Tasks endpoints
    case strpos($path, '/tasks/') === 0:
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Report endpoint
    case $path === '/report.php':
        require_once(__DIR__ . '/v1/index.php');
        exit;
    
    // Default: 404 Not Found
    default:
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'not_found',
                'message' => 'Endpoint not found: ' . $path
            ]
        ]);
        exit;
}