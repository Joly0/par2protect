<?php
/**
 * Setup Check Schedule Cron Script
 * 
 * This script sets up a cron job to run the scheduler check script every 5 minutes.
 */

// Load bootstrap
$bootstrap = require_once(__DIR__ . '/../core/bootstrap.php');

// Get components from container
$container = get_container();
$logger = $container->get('logger');

// Log start
$logger->info("Setting up check schedule cron job");

// Define cron expression and script path
$cronExpression = '*/5 * * * *'; 
$checkScriptPath = __DIR__ . '/check_schedule.php'; // Use absolute path resolved by __DIR__

// Create cron job entry - redirect output to prevent mail spam
$cronEntry = "$cronExpression php $checkScriptPath > /dev/null 2>&1\n";

// Get current crontab
exec('crontab -l 2>/dev/null', $currentCrontab, $returnCode);
if (!is_array($currentCrontab)) {
    $currentCrontab = []; // Ensure it's an array even if crontab -l fails or is empty
}

// Check if cron job already exists
$cronJobExists = false;
foreach ($currentCrontab as $line) {
    // Check specifically for the check_schedule.php script path
    if (strpos($line, $checkScriptPath) !== false) {
        $cronJobExists = true;
        break;
    }
}

// If cron job doesn't exist, add it
if (!$cronJobExists) {
    $logger->info("Check schedule cron job not found, adding it.");
    echo "Check schedule cron job not found, adding it.\n";
    
    // Add our cron job to the current crontab array
    $currentCrontab[] = $cronEntry;
    
    // Ensure there's a newline at the end of the file for crontab compatibility
    $newCrontabContent = trim(implode("\n", $currentCrontab)) . "\n";

    // Write new crontab to a temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'crontab_schedule_');
    if ($tempFile === false) {
        $logger->error("Failed to create temporary file for crontab.");
        echo "Error: Failed to create temporary file.\n";
        exit(1);
    }
    
    if (file_put_contents($tempFile, $newCrontabContent) === false) {
        $logger->error("Failed to write to temporary crontab file.", ['file' => $tempFile]);
        echo "Error: Failed to write to temporary file: $tempFile\n";
        unlink($tempFile); // Clean up temp file
        exit(1);
    }
    
    // Install new crontab
    exec("crontab $tempFile", $output, $returnCode);
    
    // Remove temporary file
    unlink($tempFile);
    
    if ($returnCode === 0) {
        $logger->info("Check schedule cron job set up successfully", [
            'cron_expression' => $cronExpression,
            'script_path' => $checkScriptPath
        ]);
        echo "Check schedule cron job set up successfully\n";
        echo "Cron expression: $cronExpression\n";
    } else {
        $logger->error("Failed to set up check schedule cron job", [
            'return_code' => $returnCode,
            'output' => $output
        ]);
        echo "Failed to set up check schedule cron job\n";
        echo "Return code: $returnCode\n";
        // Optionally print output if needed: echo "Output: " . implode("\n", $output) . "\n";
        exit(1); // Exit with error status
    }
} else {
    $logger->info("Check schedule cron job already exists.");
    echo "Check schedule cron job already exists.\n";
}

// Exit with success
exit(0);