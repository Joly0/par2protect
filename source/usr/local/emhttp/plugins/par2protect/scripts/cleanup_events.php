<?php
/**
 * Event Cleanup Script
 * 
 * This script cleans up old events from the events table.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// No need for use statements
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\EventSystem;

// Get components from container
$container = get_container();
$logger = $container->get('logger');
$eventSystem = $container->get('eventSystem');

$logger->info("Starting event cleanup");

// Clean up events older than 1 day
$count = $eventSystem->cleanupOldEvents(0.5); // 0.5 days = 12 hours

$logger->info("Event cleanup completed", [
    'deleted_count' => $count
]);