<?php
/**
 * Bootstrap file for Par2Protect plugin
 *
 * This file initializes the core components and sets up error handling.
 */

// Define plugin root directory
define('PAR2PROTECT_ROOT', dirname(__DIR__));

// Include the Container class
require_once __DIR__ . '/Container.php';

// Add use statements for services, helpers, and endpoints
use Par2Protect\Services\Protection;
use Par2Protect\Services\Verification;
use Par2Protect\Services\Queue;
use Par2Protect\Services\Protection\ProtectionRepository;
use Par2Protect\Services\Protection\ProtectionOperations;
use Par2Protect\Services\Protection\Helpers\FormatHelper;
use Par2Protect\Services\Verification\VerificationRepository;
use Par2Protect\Services\Verification\VerificationOperations;
use Par2Protect\Core\MetadataManager; // Use Core manager
use Par2Protect\Api\V1\Endpoints\ProtectionEndpoint;
use Par2Protect\Api\V1\Endpoints\VerificationEndpoint;
use Par2Protect\Api\V1\Endpoints\SettingsEndpoint;
use Par2Protect\Api\V1\Endpoints\QueueEndpoint;
use Par2Protect\Api\V1\Endpoints\StatusEndpoint;
use Par2Protect\Api\V1\Endpoints\LogEndpoint;
use Par2Protect\Api\V1\Endpoints\EventsEndpoint;
use Par2Protect\Core\Commands\Par2VerifyCommandBuilder;
use Par2Protect\Core\Commands\Par2RepairCommandBuilder;
use Par2Protect\Api\V1\Endpoints\DebugEndpoint;
use Par2Protect\Core\Commands\Par2CreateCommandBuilder;
use Par2Protect\Core\Database; // Added use for Database type hint
use Par2Protect\Core\Logger;   // Added use for Logger type hint
use Par2Protect\Core\Config;   // Added use for Config type hint
use Par2Protect\Core\Cache;    // Added use for Cache type hint
use Par2Protect\Core\EventSystem; // Added use for EventSystem type hint
use Par2Protect\Core\QueueDatabase; // Added use for QueueDatabase type hint

// Set up autoloading
spl_autoload_register(function ($class) {
    if (strpos($class, 'Par2Protect\\') !== 0) { return; }
    $path = str_replace('\\', '/', substr($class, 12));
    $pathParts = explode('/', $path);
    $fileName = array_pop($pathParts);
    $dirPath = strtolower(implode('/', $pathParts));
    $file = PAR2PROTECT_ROOT . '/' . $dirPath . '/' . $fileName . '.php';
    if (file_exists($file)) { require_once $file; return; }
    $file = PAR2PROTECT_ROOT . '/' . $dirPath . '/' . strtolower($fileName) . '.php';
    if (file_exists($file)) { require_once $file; }
});

// Set up error handling
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) { return false; }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Set up exception handling
set_exception_handler(function ($exception) {
    $logger = null;
    global $container;
    if (isset($container) && $container instanceof \Par2Protect\Core\Container && $container->has('logger')) {
        try { $logger = $container->get('logger'); } catch (\Exception $e) {}
    }
    $logMessage = ($exception instanceof \Par2Protect\Core\Exceptions\ApiException) ? "API Exception: " . $exception->getMessage() : "Unhandled Exception: " . $exception->getMessage();
    $logContext = ($exception instanceof \Par2Protect\Core\Exceptions\ApiException) ? [
        'code' => $exception->getErrorCode(), 'status_code' => $exception->getStatusCode(),
        'context' => $exception->getContext(), 'file' => $exception->getFile(), 'line' => $exception->getLine()
    ] : [
        'type' => get_class($exception), 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTraceAsString()
    ];
    if ($logger) { $logger->critical($logMessage, $logContext); } else { error_log($logMessage . " | Context: " . json_encode($logContext)); }

    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        \Par2Protect\Core\Response::error('An unexpected error occurred: ' . $exception->getMessage(), 500, 'server_error', ['file' => $exception->getFile(), 'line' => $exception->getLine()]);
    } else {
        echo "<h1>Error</h1><p>An unexpected error occurred: " . htmlspecialchars($exception->getMessage()) . "</p>";
        if (ini_get('display_errors')) {
            echo "<h2>Details</h2><p>File: " . htmlspecialchars($exception->getFile()) . "</p><p>Line: " . htmlspecialchars($exception->getLine()) . "</p><h2>Stack Trace</h2><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        }
    }
    exit(1);
});

// --- Dependency Injection Container Setup ---
$container = new \Par2Protect\Core\Container();

// Register core services
$container->register('config', function ($c) {
    return new Config($c->get('logger')); // Use statement used
});

// Create the logger instance ONCE, before registration
$loggerInstance = new Logger(); // Use statement used

// Register the logger service to return the SAME instance
$container->register('logger', function ($c) use ($loggerInstance) {
    return $loggerInstance;
});

$container->register('cache', function ($c) {
    return new Cache($c->get('logger')); // Use statement used
});

$container->register('database', function ($c) {
    return new Database($c->get('logger'), $c->get('config')); // Use statement used
});

$container->register('queueDb', function ($c) {
    return new QueueDatabase($c->get('logger'), $c->get('config')); // Use statement used
});

$container->register('eventSystem', function ($c) {
    return new EventSystem($c->get('logger')); // Use statement used
});

// Register Consolidated Metadata Manager
$container->register(MetadataManager::class, function ($c) {
    return new MetadataManager($c->get('database'), $c->get('logger'), $c->get('config')); // Add config service
});

// Register Services
$container->register(Protection::class, function ($c) {
    return new Protection(
        $c->get('logger'), $c->get('config'), $c->get('cache'), $c->get('database'),
        $c->get(ProtectionRepository::class), $c->get(ProtectionOperations::class),
        $c->get(MetadataManager::class), $c->get(FormatHelper::class)
    );
});

$container->register(Verification::class, function ($c) {
    return new Verification(
        $c->get('logger'), $c->get('config'), $c->get('cache'), $c->get('database'),
        $c->get(VerificationRepository::class), $c->get(VerificationOperations::class),
        $c->get(MetadataManager::class)
    );
});

$container->register(Queue::class, function ($c) {
    return new Queue(
        $c->get('database'), $c->get('queueDb'), $c->get('logger'), $c->get('config')
    );
});

// Register Helpers
$container->register(FormatHelper::class, function ($c) {
    return new FormatHelper();
});

// Register Protection Components
$container->register(ProtectionRepository::class, function ($c) {
    return new ProtectionRepository($c->get('database'), $c->get('logger'), $c->get('cache'));
});
$container->register(ProtectionOperations::class, function ($c) {
    return new ProtectionOperations(
        $c->get('logger'), $c->get('config'), $c->get(FormatHelper::class),
        $c->get('eventSystem'), $c->get(Par2CreateCommandBuilder::class)
    );
});

// Register Verification Components
$container->register(VerificationRepository::class, function ($c) {
    return new VerificationRepository($c->get('database'), $c->get('logger'), $c->get('cache'));
});
$container->register(VerificationOperations::class, function ($c) {
    return new VerificationOperations(
        $c->get('logger'), $c->get('config'),
        $c->get(Par2VerifyCommandBuilder::class), $c->get(Par2RepairCommandBuilder::class)
    );
});

// Register Command Builders
$container->register(Par2CreateCommandBuilder::class, function ($c) {
    return new Par2CreateCommandBuilder($c->get('config'), $c->get('logger'));
});
$container->register(Par2VerifyCommandBuilder::class, function ($c) {
    return new Par2VerifyCommandBuilder($c->get('config'), $c->get('logger'));
});
$container->register(Par2RepairCommandBuilder::class, function ($c) {
    return new Par2RepairCommandBuilder($c->get('config'), $c->get('logger'));
});

// Register API Endpoints
$container->register(ProtectionEndpoint::class, function ($c) {
    return new ProtectionEndpoint(
        $c->get(Protection::class),
        $c->get(Queue::class),
        $c->get('config'),
        $c->get('logger'),
        $c->get('database') // Added missing Database dependency
    );
});
$container->register(VerificationEndpoint::class, function ($c) {
    return new VerificationEndpoint($c->get(Verification::class), $c->get(Queue::class));
});
$container->register(SettingsEndpoint::class, function ($c) {
    return new SettingsEndpoint($c->get('config'), $c->get('logger'));
});
$container->register(QueueEndpoint::class, function ($c) {
    return new QueueEndpoint($c->get(Queue::class), $c->get('logger')); // Inject Logger
});
$container->register(StatusEndpoint::class, function ($c) {
    return new StatusEndpoint(
        $c->get('config'), $c->get('logger'), $c->get('database'),
        $c->get(Queue::class), $c->get(Protection::class)
    );
});
$container->register(LogEndpoint::class, function ($c) {
    return new LogEndpoint($c->get('logger'), $c->get('config'));
});
$container->register(EventsEndpoint::class, function ($c) {
    return new EventsEndpoint($c->get('logger'), $c->get('database'), $c->get('eventSystem'));
});
$container->register(DebugEndpoint::class, function ($c) {
    return new DebugEndpoint($c);
});

// Global access function
function get_container() {
    global $container;
    return $container;
}
// --- End Container Setup ---

// Explicitly configure the logger after all services are registered
try {
    // Use the pre-existing instance variable for configuration
    $configInstance = $container->get('config');
    $loggerInstance->configure($configInstance); // $loggerInstance was created before registration
} catch (\Exception $e) {
     // Use error_log if logger configuration fails
     $errorMsg = "Par2Protect Bootstrap: Failed to perform post-registration logger configuration: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
     error_log($errorMsg);
     // Removed fallback file log
}

// Define a function to output frontend includes
function par2protect_output_frontend_includes() {
    echo <<<HTML
<!-- Common CSS -->
<link type="text/css" rel="stylesheet" href="/plugins/par2protect/shared/css/common.css">
<link type="text/css" rel="stylesheet" href="/plugins/par2protect/shared/css/sweetalert-dark-fix.css">
<!-- Common JavaScript -->
<script src="/plugins/par2protect/shared/js/logger.js"></script>
<script src="/plugins/par2protect/shared/js/common.js"></script>
<script src="/plugins/par2protect/shared/js/queue-manager.js"></script>
<script>
// SSE initialization is now handled solely within queue-manager.js
</script>
<script src="/plugins/par2protect/shared/js/help-text.js"></script>
HTML;
}

// Return the container instance
return $container;