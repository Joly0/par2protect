<?php
/**
 * Bootstrap file for Par2Protect plugin
 * 
 * This file initializes the core components and sets up error handling.
 */

// Define plugin root directory
define('PAR2PROTECT_ROOT', dirname(__DIR__));

// Set up autoloading
spl_autoload_register(function ($class) {
    // Only handle our namespace
    if (strpos($class, 'Par2Protect\\') !== 0) {
        return;
    }
    
    // Convert namespace to path
    $path = str_replace('\\', '/', substr($class, 12));
    
    // Convert path to lowercase for directory part, keep original case for filename
    $pathParts = explode('/', $path);
    $fileName = array_pop($pathParts);
    $dirPath = strtolower(implode('/', $pathParts));
    
    // Try with original case for filename
    $file = PAR2PROTECT_ROOT . '/' . $dirPath . '/' . $fileName . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
    
    // Try with lowercase filename as fallback
    $file = PAR2PROTECT_ROOT . '/' . $dirPath . '/' . strtolower($fileName) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Set up error handling
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Only handle errors that are reported based on error_reporting setting
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // Convert errors to exceptions
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Set up exception handling
set_exception_handler(function ($exception) {
    $logger = \Par2Protect\Core\Logger::getInstance();
    
    if ($exception instanceof \Par2Protect\Core\Exceptions\ApiException) {
        $logger->error("API Exception: " . $exception->getMessage(), [
            'code' => $exception->getErrorCode(),
            'status_code' => $exception->getStatusCode(),
            'context' => $exception->getContext(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    } else {
        $logger->error("Unhandled Exception: " . $exception->getMessage(), [
            'type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
    
    // If this is an API request, return JSON error response
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        \Par2Protect\Core\Response::error(
            'An unexpected error occurred: ' . $exception->getMessage(),
            500,
            'server_error',
            [
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]
        );
    } else {
        // Otherwise, display error message
        echo "<h1>Error</h1>";
        echo "<p>An unexpected error occurred: " . htmlspecialchars($exception->getMessage()) . "</p>";
        
        if (ini_get('display_errors')) {
            echo "<h2>Details</h2>";
            echo "<p>File: " . htmlspecialchars($exception->getFile()) . "</p>";
            echo "<p>Line: " . htmlspecialchars($exception->getLine()) . "</p>";
            echo "<h2>Stack Trace</h2>";
            echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        }
    }
    
    exit(1);
});

// Initialize core components
$config = \Par2Protect\Core\Config::getInstance();
$logger = \Par2Protect\Core\Logger::getInstance();
$cache = \Par2Protect\Core\Cache::getInstance();

// Set log file paths from config
$logger->setLogFile($config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log'));
$logger->setTmpLogFile($config->get('logging.tmp_path', '/tmp/par2protect/logs/par2protect.log'));

// Initialize queue database (separate from main database)
$queueDb = \Par2Protect\Core\QueueDatabase::getInstance();

// Log bootstrap completion
/* $logger->debug("Par2Protect bootstrap completed", [
    'version' => '2.0.0',
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
]); */

// Return initialized components
return [
    'config' => $config,
    'logger' => $logger,
    'cache' => $cache,
    'queueDb' => $queueDb
];