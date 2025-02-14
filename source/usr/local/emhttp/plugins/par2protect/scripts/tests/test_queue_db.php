<?php
/**
 * Test Queue Database Script
 * 
 * This script tests the QueueDatabase functionality.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\QueueDatabase;

// Get components
$logger = Logger::getInstance();
$config = Config::getInstance();
$queueDb = QueueDatabase::getInstance();

// Log start
$logger->info("Starting Queue Database test");

// Initialize queue database
try {
    $queueDb->initializeQueueTable();
    $logger->info("Queue database initialized successfully");
    echo "Queue database initialized successfully\n";
} catch (Exception $e) {
    $logger->error("Failed to initialize queue database: " . $e->getMessage());
    echo "Failed to initialize queue database: " . $e->getMessage() . "\n";
    exit(1);
}

// Add test operation to queue
try {
    $queueDb->beginTransaction();
    
    $now = time();
    $parameters = json_encode(['path' => '/mnt/user/test', 'redundancy' => 10]);
    
    $result = $queueDb->query(
        "INSERT INTO operation_queue (operation_type, parameters, status, created_at, updated_at)
        VALUES (:type, :parameters, 'pending', :now, :now)",
        [
            ':type' => 'test',
            ':parameters' => $parameters,
            ':now' => $now
        ]
    );
    
    $operationId = $queueDb->lastInsertId();
    $queueDb->commit();
    
    $logger->info("Test operation added to queue successfully", [
        'operation_id' => $operationId
    ]);
    echo "Test operation added to queue successfully (ID: $operationId)\n";
} catch (Exception $e) {
    if ($queueDb->inTransaction) {
        $queueDb->rollback();
    }
    
    $logger->error("Failed to add test operation to queue: " . $e->getMessage());
    echo "Failed to add test operation to queue: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if operation was added successfully
try {
    $result = $queueDb->query(
        "SELECT * FROM operation_queue WHERE id = :id",
        [':id' => $operationId]
    );
    
    $operation = $queueDb->fetchOne($result);
    
    if ($operation) {
        $logger->info("Operation retrieved successfully", [
            'operation_id' => $operation['id'],
            'operation_type' => $operation['operation_type'],
            'status' => $operation['status']
        ]);
        echo "Operation retrieved successfully:\n";
        echo "  ID: {$operation['id']}\n";
        echo "  Type: {$operation['operation_type']}\n";
        echo "  Status: {$operation['status']}\n";
    } else {
        $logger->error("Failed to retrieve operation");
        echo "Failed to retrieve operation\n";
        exit(1);
    }
} catch (Exception $e) {
    $logger->error("Failed to retrieve operation: " . $e->getMessage());
    echo "Failed to retrieve operation: " . $e->getMessage() . "\n";
    exit(1);
}

// Clean up test operation
try {
    $queueDb->query(
        "DELETE FROM operation_queue WHERE id = :id",
        [':id' => $operationId]
    );
    
    $logger->info("Test operation cleaned up successfully");
    echo "Test operation cleaned up successfully\n";
} catch (Exception $e) {
    $logger->error("Failed to clean up test operation: " . $e->getMessage());
    echo "Failed to clean up test operation: " . $e->getMessage() . "\n";
}

// Test changes() method
try {
    $queueDb->query(
        "DELETE FROM operation_queue WHERE operation_type = 'test'",
        []
    );
    
    $changesCount = $queueDb->changes();
    
    $logger->info("Changes count test successful", [
        'changes_count' => $changesCount
    ]);
    echo "Changes count test successful: $changesCount changes\n";
} catch (Exception $e) {
    $logger->error("Failed to test changes count: " . $e->getMessage());
    echo "Failed to test changes count: " . $e->getMessage() . "\n";
}

// Log completion
$logger->info("Queue Database test completed successfully");
echo "Queue Database test completed successfully\n";

// Exit with success
exit(0);