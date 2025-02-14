<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/logging.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/database.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/FileOperations.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/ProtectionManager.php");

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get path and redundancy from POST data
    $path = $_POST['path'] ?? null;
    $logger = Logger::getInstance();
    $logger->info("Received path", ['path' => $path]);
    $redundancy = isset($_POST['redundancy']) ? intval($_POST['redundancy']) : null;
    
    if (!$path) {
        throw new \Exception("Path parameter is required");
    }
    
    // Start protection task
    try {
        $protectionManager = ProtectionManager::getInstance();
        $logger->debug("Starting protection task", [
            'path' => $path,
            'redundancy' => $redundancy
        ]);
        
        $result = $protectionManager->protect($path, $redundancy);
        
        if (!$result || !isset($result['success'])) {
            throw new \Exception("Invalid result from protection manager");
        }
        
        if (!$result['success']) {
            throw new \Exception($result['error'] ?? "Protection task failed without specific error");
        }
        
        $logger->info("Protection task completed successfully", [
            'path' => $path,
            'operation_id' => $result['operation_id'] ?? null
        ]);
        
        echo json_encode($result);
        
    } catch (\Exception $e) {
        $logger->error("Protection task failed", [
            'path' => $path,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
    
} catch (\Exception $e) {
    $logger->error("Request failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}