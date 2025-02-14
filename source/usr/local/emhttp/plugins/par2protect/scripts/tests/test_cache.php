<?php
/**
 * Test Cache Script
 * 
 * This script tests the Cache functionality.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Cache;

// Get components
$logger = Logger::getInstance();
$config = Config::getInstance();
$cache = Cache::getInstance();

// Log start
$logger->info("Starting Cache test");

// Test set and get
try {
    $testKey = 'test_key';
    $testValue = ['name' => 'Test Value', 'timestamp' => time()];
    
    // Set value in cache
    $result = $cache->set($testKey, $testValue, 60); // 60 seconds TTL
    
    if ($result) {
        $logger->info("Value set in cache successfully");
        echo "Value set in cache successfully\n";
    } else {
        $logger->error("Failed to set value in cache");
        echo "Failed to set value in cache\n";
        exit(1);
    }
    
    // Get value from cache
    $retrievedValue = $cache->get($testKey);
    
    if ($retrievedValue && $retrievedValue['name'] === $testValue['name']) {
        $logger->info("Value retrieved from cache successfully");
        echo "Value retrieved from cache successfully\n";
        echo "  Name: {$retrievedValue['name']}\n";
        echo "  Timestamp: {$retrievedValue['timestamp']}\n";
    } else {
        $logger->error("Failed to retrieve value from cache");
        echo "Failed to retrieve value from cache\n";
        exit(1);
    }
} catch (Exception $e) {
    $logger->error("Cache set/get test failed: " . $e->getMessage());
    echo "Cache set/get test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test has
try {
    $hasKey = $cache->has($testKey);
    
    if ($hasKey) {
        $logger->info("Cache has key test successful");
        echo "Cache has key test successful\n";
    } else {
        $logger->error("Cache has key test failed");
        echo "Cache has key test failed\n";
        exit(1);
    }
    
    // Test with non-existent key
    $nonExistentKey = 'non_existent_key';
    $hasNonExistentKey = $cache->has($nonExistentKey);
    
    if (!$hasNonExistentKey) {
        $logger->info("Cache has non-existent key test successful");
        echo "Cache has non-existent key test successful\n";
    } else {
        $logger->error("Cache has non-existent key test failed");
        echo "Cache has non-existent key test failed\n";
        exit(1);
    }
} catch (Exception $e) {
    $logger->error("Cache has test failed: " . $e->getMessage());
    echo "Cache has test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test remove
try {
    $removeResult = $cache->remove($testKey);
    
    if ($removeResult) {
        $logger->info("Cache remove test successful");
        echo "Cache remove test successful\n";
    } else {
        $logger->error("Cache remove test failed");
        echo "Cache remove test failed\n";
        exit(1);
    }
    
    // Verify key was removed
    $hasKeyAfterRemove = $cache->has($testKey);
    
    if (!$hasKeyAfterRemove) {
        $logger->info("Key was successfully removed from cache");
        echo "Key was successfully removed from cache\n";
    } else {
        $logger->error("Key was not removed from cache");
        echo "Key was not removed from cache\n";
        exit(1);
    }
} catch (Exception $e) {
    $logger->error("Cache remove test failed: " . $e->getMessage());
    echo "Cache remove test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test clear
try {
    // Add multiple items to cache
    $cache->set('test_key1', 'Test Value 1', 60);
    $cache->set('test_key2', 'Test Value 2', 60);
    $cache->set('test_key3', 'Test Value 3', 60);
    
    // Clear cache
    $clearResult = $cache->clear();
    
    if ($clearResult) {
        $logger->info("Cache clear test successful");
        echo "Cache clear test successful\n";
    } else {
        $logger->error("Cache clear test failed");
        echo "Cache clear test failed\n";
        exit(1);
    }
    
    // Verify all keys were removed
    $hasKey1 = $cache->has('test_key1');
    $hasKey2 = $cache->has('test_key2');
    $hasKey3 = $cache->has('test_key3');
    
    if (!$hasKey1 && !$hasKey2 && !$hasKey3) {
        $logger->info("All keys were successfully removed from cache");
        echo "All keys were successfully removed from cache\n";
    } else {
        $logger->error("Not all keys were removed from cache");
        echo "Not all keys were removed from cache\n";
        exit(1);
    }
} catch (Exception $e) {
    $logger->error("Cache clear test failed: " . $e->getMessage());
    echo "Cache clear test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test cleanExpired
try {
    // Add items with different expiration times
    $cache->set('expired_key', 'Expired Value', 1); // 1 second TTL
    $cache->set('valid_key', 'Valid Value', 3600); // 1 hour TTL
    
    // Wait for the first key to expire
    sleep(2);
    
    // Clean expired entries
    $cleanedCount = $cache->cleanExpired();
    
    $logger->info("Cache cleanExpired test successful", [
        'cleaned_count' => $cleanedCount
    ]);
    echo "Cache cleanExpired test successful: $cleanedCount entries cleaned\n";
    
    // Verify expired key was removed but valid key remains
    $hasExpiredKey = $cache->has('expired_key');
    $hasValidKey = $cache->has('valid_key');
    
    if (!$hasExpiredKey && $hasValidKey) {
        $logger->info("Expired key was removed but valid key remains");
        echo "Expired key was removed but valid key remains\n";
    } else if ($hasExpiredKey) {
        $logger->error("Expired key was not removed");
        echo "Expired key was not removed\n";
        exit(1);
    } else if (!$hasValidKey) {
        $logger->error("Valid key was incorrectly removed");
        echo "Valid key was incorrectly removed\n";
        exit(1);
    }
    
    // Clean up
    $cache->clear();
} catch (Exception $e) {
    $logger->error("Cache cleanExpired test failed: " . $e->getMessage());
    echo "Cache cleanExpired test failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Log completion
$logger->info("Cache test completed successfully");
echo "Cache test completed successfully\n";

// Exit with success
exit(0);