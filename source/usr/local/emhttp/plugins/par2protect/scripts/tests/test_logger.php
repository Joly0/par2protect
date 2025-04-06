<?php
/**
 * Test Logger Script
 * 
 * This script tests the Logger functionality, specifically writing logs to both
 * the permanent and temporary locations.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../../core/bootstrap.php'); // Corrected path

// No need for use statements
// use Par2Protect\Core\Logger;
// use Par2Protect\Core\Config;

// Get components from container
$container = get_container();
$logger = $container->get('logger');
$config = $container->get('config');

// Log start
echo "Starting Logger test\n";

// Get log file paths
$tmpLogFile = $config->get('logging.tmp_path', '/tmp/par2protect/logs/par2protect.log');
$permLogFile = $config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');

echo "Temporary log file: $tmpLogFile\n";
echo "Permanent log file: $permLogFile\n";

// Ensure log directories exist
$tmpLogDir = dirname($tmpLogFile);
$permLogDir = dirname($permLogFile);

if (!is_dir($tmpLogDir)) {
    mkdir($tmpLogDir, 0755, true);
    echo "Created temporary log directory: $tmpLogDir\n";
}

if (!is_dir($permLogDir)) {
    mkdir($permLogDir, 0755, true);
    echo "Created permanent log directory: $permLogDir\n";
}

// Generate a unique test ID
$testId = 'logger_test_' . time();

// Write test logs at different levels
$logger->debug("Debug message from test script [$testId]");
$logger->info("Info message from test script [$testId]");
$logger->warning("Warning message from test script [$testId]");
$logger->error("Error message from test script [$testId]");
$logger->critical("Critical message from test script [$testId]");

echo "Wrote test logs with ID: $testId\n";

// Wait a moment for logs to be written
sleep(1);

// Check if logs were written to both locations
$tmpLogExists = file_exists($tmpLogFile);
$permLogExists = file_exists($permLogFile);

echo "Temporary log file exists: " . ($tmpLogExists ? 'Yes' : 'No') . "\n";
echo "Permanent log file exists: " . ($permLogExists ? 'Yes' : 'No') . "\n";

// Check if test logs are in both files
$tmpLogContent = $tmpLogExists ? file_get_contents($tmpLogFile) : '';
$permLogContent = $permLogExists ? file_get_contents($permLogFile) : '';

$tmpLogHasTestId = strpos($tmpLogContent, $testId) !== false;
$permLogHasTestId = strpos($permLogContent, $testId) !== false;

echo "Temporary log contains test ID: " . ($tmpLogHasTestId ? 'Yes' : 'No') . "\n";
echo "Permanent log contains test ID: " . ($permLogHasTestId ? 'Yes' : 'No') . "\n";

// Test backup functionality
echo "Testing log backup functionality...\n";

// Create a backup log file
$backupResult = $logger->backupLogs(true); // Force backup

echo "Backup result: " . ($backupResult ? 'Success' : 'Failed') . "\n";

// Check backup directory
$backupPath = $config->get('logging.backup_path', '/boot/config/plugins/par2protect/logs');
$backupFiles = glob($backupPath . '/*.log');

echo "Backup files found: " . count($backupFiles) . "\n";
if (count($backupFiles) > 0) {
    echo "Latest backup file: " . basename(end($backupFiles)) . "\n";
}

// Test activity log
echo "Testing activity log functionality...\n";

// Get activity log file
$activityLogFile = dirname($tmpLogFile) . '/activity.json';
$activityLogExists = file_exists($activityLogFile);

echo "Activity log exists: " . ($activityLogExists ? 'Yes' : 'No') . "\n";

// Get recent activity
$recentActivity = $logger->getRecentActivity(5);

echo "Recent activity count: " . count($recentActivity) . "\n";
if (count($recentActivity) > 0) {
    echo "Latest activity: " . json_encode($recentActivity[0]) . "\n";
}

// Log completion
echo "Logger test completed\n";

// Exit with success
exit(0);