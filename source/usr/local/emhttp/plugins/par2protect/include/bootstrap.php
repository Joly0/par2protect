<?php
namespace Par2Protect;

// Load required files
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/logging.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/database.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/FileOperations.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/ConnectionPool.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/DatabaseManager.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/ProtectionManager.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/VerificationManager.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/ResourceManager.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");

// Load exceptions
require_once("/usr/local/emhttp/plugins/par2protect/include/exceptions/ResourceException.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/exceptions/DatabaseException.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/exceptions/ProtectionException.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/exceptions/VerificationException.php");

// Initialize core components
$logger = Logger::getInstance();
$config = Config::getInstance();
$db = DatabaseManager::getInstance();
$resourceManager = ResourceManager::getInstance();

// Generate a unique request ID for this page load
$requestId = uniqid('req_', true);

// Log initialization on main page load
if (basename($_SERVER['SCRIPT_NAME']) === 'template.php') {
    $logger->info("System initialized", [
        'debug_mode' => $config->get('debug.debug_logging', false),
        'request_id' => $requestId
    ]);
}

// Function to get initialized components
function getInitializedComponents() {
    return [
        'logger' => Logger::getInstance(),
        'config' => Config::getInstance(),
        'db' => DatabaseManager::getInstance(),
        'fileOps' => FileOperations::getInstance(),
        'resourceManager' => ResourceManager::getInstance()
    ];
}