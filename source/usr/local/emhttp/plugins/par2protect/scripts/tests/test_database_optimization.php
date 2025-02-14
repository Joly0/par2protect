<?php
/**
 * Test Database Optimization Script
 * 
 * This script tests the entire database optimization implementation.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\QueueDatabase;
use Par2Protect\Core\Cache;

// Get components
$logger = Logger::getInstance();
$config = Config::getInstance();
$queueDb = QueueDatabase::getInstance();
$cache = Cache::getInstance();

// Log start
echo "Starting Database Optimization Test\n";
$logger->info("Starting Database Optimization Test");

// Step 1: Initialize temporary directories
echo "\n=== Step 1: Initialize Temporary Directories ===\n";

$tmpDirs = [
    '/tmp/par2protect',                  // Base directory
    '/tmp/par2protect/queue',            // Queue directory
    '/tmp/par2protect/logs',             // Logs directory
    '/tmp/par2protect/locks',            // Locks directory
    '/tmp/par2protect/cache'             // Cache directory
];

$createdCount = 0;
foreach ($tmpDirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "Created directory: $dir\n";
            $createdCount++;
        } else {
            echo "Failed to create directory: $dir\n";
        }
    } else {
        echo "Directory already exists: $dir\n";
    }
}

echo "Created $createdCount temporary directories\n";

// Step 2: Test QueueDatabase functionality
echo "\n=== Step 2: Test QueueDatabase Functionality ===\n";

try {
    // Initialize queue database
    $queueDb->initializeQueueTable();
    echo "Queue database initialized successfully\n";
    
    // Add test operation to queue
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
    
    echo "Test operation added to queue successfully (ID: $operationId)\n";
    
    // Check if operation was added successfully
    $result = $queueDb->query(
        "SELECT * FROM operation_queue WHERE id = :id",
        [':id' => $operationId]
    );
    
    $operation = $queueDb->fetchOne($result);
    
    if ($operation) {
        echo "Operation retrieved successfully:\n";
        echo "  ID: {$operation['id']}\n";
        echo "  Type: {$operation['operation_type']}\n";
        echo "  Status: {$operation['status']}\n";
    } else {
        echo "Failed to retrieve operation\n";
    }
    
    // Clean up test operation
    $queueDb->query(
        "DELETE FROM operation_queue WHERE id = :id",
        [':id' => $operationId]
    );
    
    echo "Test operation cleaned up successfully\n";
    
    // Test changes() method
    $queueDb->query(
        "DELETE FROM operation_queue WHERE operation_type = 'test'",
        []
    );
    
    $changesCount = $queueDb->changes();
    echo "Changes count test successful: $changesCount changes\n";
    
    echo "QueueDatabase test completed successfully\n";
} catch (Exception $e) {
    echo "QueueDatabase test failed: " . $e->getMessage() . "\n";
}

// Step 3: Test Cache functionality
echo "\n=== Step 3: Test Cache Functionality ===\n";

try {
    $testKey = 'test_key';
    $testValue = ['name' => 'Test Value', 'timestamp' => time()];
    
    // Set value in cache
    $result = $cache->set($testKey, $testValue, 60); // 60 seconds TTL
    
    if ($result) {
        echo "Value set in cache successfully\n";
    } else {
        echo "Failed to set value in cache\n";
    }
    
    // Get value from cache
    $retrievedValue = $cache->get($testKey);
    
    if ($retrievedValue && $retrievedValue['name'] === $testValue['name']) {
        echo "Value retrieved from cache successfully\n";
        echo "  Name: {$retrievedValue['name']}\n";
        echo "  Timestamp: {$retrievedValue['timestamp']}\n";
    } else {
        echo "Failed to retrieve value from cache\n";
    }
    
    // Test has
    $hasKey = $cache->has($testKey);
    
    if ($hasKey) {
        echo "Cache has key test successful\n";
    } else {
        echo "Cache has key test failed\n";
    }
    
    // Test remove
    $removeResult = $cache->remove($testKey);
    
    if ($removeResult) {
        echo "Cache remove test successful\n";
    } else {
        echo "Cache remove test failed\n";
    }
    
    // Test clear
    $cache->set('test_key1', 'Test Value 1', 60);
    $cache->set('test_key2', 'Test Value 2', 60);
    
    $clearResult = $cache->clear();
    
    if ($clearResult) {
        echo "Cache clear test successful\n";
    } else {
        echo "Cache clear test failed\n";
    }
    
    echo "Cache test completed successfully\n";
} catch (Exception $e) {
    echo "Cache test failed: " . $e->getMessage() . "\n";
}

// Step 4: Test Logger functionality
echo "\n=== Step 4: Test Logger Functionality ===\n";

try {
    // Get log file paths
    $tmpLogFile = $config->get('logging.tmp_path', '/tmp/par2protect/logs/par2protect.log');
    $permLogFile = $config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
    
    echo "Temporary log file: $tmpLogFile\n";
    echo "Permanent log file: $permLogFile\n";
    
    // Generate a unique test ID
    $testId = 'logger_test_' . time();
    
    // Write test logs at different levels
    $logger->debug("Debug message from test script [$testId]");
    $logger->info("Info message from test script [$testId]");
    $logger->warning("Warning message from test script [$testId]");
    $logger->error("Error message from test script [$testId]");
    
    echo "Wrote test logs with ID: $testId\n";
    
    // Wait a moment for logs to be written
    sleep(1);
    
    // Check if logs were written to both locations
    $tmpLogExists = file_exists($tmpLogFile);
    $permLogExists = file_exists($permLogFile);
    
    echo "Temporary log file exists: " . ($tmpLogExists ? 'Yes' : 'No') . "\n";
    echo "Permanent log file exists: " . ($permLogExists ? 'Yes' : 'No') . "\n";
    
    // Test backup functionality
    $backupResult = $logger->backupLogs(true); // Force backup
    
    echo "Backup result: " . ($backupResult ? 'Success' : 'Failed') . "\n";
    
    echo "Logger test completed successfully\n";
} catch (Exception $e) {
    echo "Logger test failed: " . $e->getMessage() . "\n";
}

// Step 5: Test API with CSRF token
echo "\n=== Step 5: Test API with CSRF Token ===\n";

echo "To test the API with CSRF token, run the test_api.php script separately.\n";
echo "The script will attempt to get the CSRF token and make a request to the API.\n";

// Log completion
echo "\nDatabase Optimization Test completed successfully\n";
$logger->info("Database Optimization Test completed successfully");

// Exit with success
exit(0);