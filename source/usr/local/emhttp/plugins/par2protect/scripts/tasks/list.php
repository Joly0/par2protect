<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/bootstrap.php");

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get initialized components
    $components = getInitializedComponents();
    $db = $components['db'];
    $fileOps = $components['fileOps'];
    $logger = $components['logger'];
    
    $logger->debug("Processing list request");
    
    // Get items from database with retry logic
    $result = $db->query("SELECT * FROM protected_items ORDER BY protected_date DESC");
    $items = $db->fetchAll($result);
    
    $logger->debug("Retrieved items from database", [
        'count' => count($items)
    ]);
    
    // Format items for response
    $formattedItems = array_map(function($item) use ($fileOps) {
        return [
            'path' => $item['path'],
            'size' => $fileOps->formatSize($item['size']),
            'mode' => $item['mode'],
            'redundancy' => $item['redundancy'],
            'status' => $item['last_status'] ?? 'UNKNOWN',
            'protectedDate' => $item['protected_date'],
            'lastVerified' => $item['last_verified'] ?? 'Never',
            'par2Path' => $item['par2_path']
        ];
    }, $items);
    
    echo json_encode([
        'success' => true,
        'items' => $formattedItems
    ]);
    
} catch (\Exception $e) {
    $logger->error("Failed to list protected items", ['exception' => $e]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}