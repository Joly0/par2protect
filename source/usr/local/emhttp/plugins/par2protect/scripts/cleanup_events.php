<?php
/**
 * Event Cleanup Script
 * 
 * This script cleans up old events from the events table.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

use Par2Protect\Core\Logger;
use Par2Protect\Core\EventSystem;

$logger = Logger::getInstance();
$eventSystem = EventSystem::getInstance();

$logger->info("Starting event cleanup");

// Clean up events older than 1 day
$count = $eventSystem->cleanupOldEvents(1);

$logger->info("Event cleanup completed", [
    'deleted_count' => $count
]);