#!/usr/bin/php
<?php
/**
 * Save settings script for PAR2Protect
 * 
 * This script is called by the Unraid UI when the settings form is submitted.
 * It processes the form data and updates the configuration.
 */

// Include bootstrap
require_once dirname(__DIR__) . '/core/bootstrap.php';

// Get components from container
$container = get_container();
$config = $container->get('config');
$logger = $container->get('logger');

// Create settings page instance
$settingsPage = new \Par2Protect\Features\Settings\SettingsPage();

// Check if we have form data in $_POST
$formData = $_POST;

// Log form data with more detail
$logger->debug("Processing settings form", [
    'form_data' => json_encode($formData),
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'not set',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'not set',
    'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set'
]);

// If $_POST is empty, try to get form data from the query string
if (empty($formData) && isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $queryParams);
    $formData = $queryParams;
    $logger->debug("Using query string parameters", [
        'query_string' => $_SERVER['QUERY_STRING'],
        'parsed_params' => json_encode($queryParams)
    ]);
}

// If we still don't have form data, check if this script is being called from update.php
if (empty($formData)) {
    // Get the raw POST data from php://input
    $rawPostData = file_get_contents('php://input');
    if (!empty($rawPostData)) {
        parse_str($rawPostData, $parsedPostData);
        $formData = $parsedPostData;
        $logger->debug("Using raw POST data", [
            'raw_post_data' => $rawPostData,
            'parsed_data' => json_encode($parsedPostData)
        ]);
    }
}

// If we still don't have form data, try to get it from the Unraid update.php mechanism
if (empty($formData)) {
    // Check if we can access the form data from the parent process
    $updatePhpFile = '/usr/local/emhttp/update.php';
    if (file_exists($updatePhpFile)) {
        $logger->debug("Attempting to get form data from update.php");
        
        // Create a temporary file to store the form data
        $tempFile = sys_get_temp_dir() . '/par2protect_form_data_' . time() . '.json';
        
        // Create a script to extract and save the form data
        $extractScript = sys_get_temp_dir() . '/par2protect_extract_form_data.php';
        $scriptContent = <<<'EOT'
<?php
// This script is executed by update.php to extract form data
$formData = $_POST;
// Remove special fields
foreach ($formData as $key => $value) {
    if (substr($key, 0, 1) === '#') {
        unset($formData[$key]);
    }
}
// Save to temp file
file_put_contents($argv[1], json_encode($formData));
EOT;
        file_put_contents($extractScript, $scriptContent);
        chmod($extractScript, 0755);
        
        // Execute the script via update.php
        $cmd = "php $extractScript " . escapeshellarg($tempFile);
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempFile)) {
            $extractedData = json_decode(file_get_contents($tempFile), true);
            if (!empty($extractedData)) {
                $formData = $extractedData;
                $logger->debug("Successfully extracted form data from update.php", [
                    'extracted_data' => json_encode($formData)
                ]);
            }
            // Clean up
            @unlink($tempFile);
        }
        @unlink($extractScript);
    }
}

// If we still don't have form data, use the current config values
if (empty($formData)) {
    $logger->warning("No form data found, using current config values");
    
    // Create form data from current config
    $currentConfig = $config->getAll();
    $formData = [
        'place' => $currentConfig['display']['place'] ?? 'Tasks:95',
        'default_redundancy' => $currentConfig['protection']['default_redundancy'] ?? 10,
        'verify_schedule' => $currentConfig['protection']['verify_schedule'] ?? '2',
        'verify_cron' => $currentConfig['protection']['verify_cron'] ?? '0 3 * * *',
        'verify_day' => $currentConfig['protection']['verify_day'] ?? '0',
        'verify_dotm' => $currentConfig['protection']['verify_dotm'] ?? '*',
        'verify_hour1' => $currentConfig['protection']['verify_hour1'] ?? '3',
        'verify_hour2' => $currentConfig['protection']['verify_hour2'] ?? '*/1',
        'verify_min' => $currentConfig['protection']['verify_min'] ?? '0',
        'max_cpu_usage' => $currentConfig['resource_limits']['max_cpu_usage'] ?? null,
        'max_memory' => $currentConfig['resource_limits']['max_memory_usage'] ?? null,
        'io_priority' => $currentConfig['resource_limits']['io_priority'] ?? 'low',
        'max_concurrent_operations' => $currentConfig['resource_limits']['max_concurrent_operations'] ?? 2,
        'parallel_file_hashing' => $currentConfig['resource_limits']['parallel_file_hashing'] ?? 2,
        'log_level' => $currentConfig['logging']['level'] ?? 'INFO',
        'error_log_mode' => $currentConfig['logging']['error_log_mode'] ?? 'both',
        'log_backup_interval' => $currentConfig['logging']['backup_interval'] ?? 'daily',
        'log_retention_days' => $currentConfig['logging']['retention_days'] ?? 7,
        'debug_logging' => $currentConfig['debug']['debug_logging'] ? 'true' : 'false'
    ];
}

// Get current config before changes
$beforeConfig = $config->getAll();
$logger->debug("Config before changes", [
    'config' => json_encode($beforeConfig)
]);

// Process settings
$result = $settingsPage->processSettings($formData);

// Get config after changes
$afterConfig = $config->getAll();
$logger->debug("Config after changes", [
    'config' => json_encode($afterConfig)
]);

// Log differences
$changes = [];
foreach ($afterConfig as $section => $values) {
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            $beforeValue = $beforeConfig[$section][$key] ?? null;
            if ($beforeValue !== $value) {
                $changes[] = "$section.$key: " . json_encode($beforeValue) . " -> " . json_encode($value);
            }
        }
    }
}
$logger->debug("Config changes", [
    'changes' => $changes
]);

// Log the result
$logger->debug("Settings processing result", [
    'success' => $result,
    'config_file' => $config->getConfigFile(),
    'config_content' => json_encode($config->getAll())
]);

// Redirect back to settings page
if ($result) {
    // Success
    $logger->debug("Settings saved successfully");
    echo "<div style='color:green;font-weight:bold;'>Settings saved successfully.</div>";
    
    // Log the config file path for debugging
    // $logger->debug("Config file saved to: " . $config->getConfigFile());
} else {
    // Error
    $logger->error("Failed to save settings");
    echo "<div style='color:red;font-weight:bold;'>Error: Failed to save settings.</div>";
    
    // Log more details about the failure
    $logger->error("Save failure details", [
        'config_file' => $config->getConfigFile(),
        'config_dir_exists' => is_dir(dirname($config->getConfigFile())),
        'config_dir_writable' => is_writable(dirname($config->getConfigFile())),
        'config_file_exists' => file_exists($config->getConfigFile()),
        'config_file_writable' => file_exists($config->getConfigFile()) ? is_writable($config->getConfigFile()) : 'N/A'
    ]);
}

// Migrate legacy config if it exists
$legacyConfigFile = '/boot/config/plugins/par2protect/par2protect.cfg';
if (file_exists($legacyConfigFile)) {
    $config->migrateLegacyConfig();
    $logger->debug("Migrated legacy configuration to JSON format");
}

// Update menu placement
$updateMenuScript = dirname(__FILE__) . '/update_menu_placement.php';
if (file_exists($updateMenuScript)) {
    // $logger->debug("Updating menu placement");
    exec($updateMenuScript, $output, $returnCode);
    
    if ($returnCode === 0) {
        $logger->debug("Menu placement updated successfully", [
            'output' => implode("\n", $output)
        ]);
    } else {
        $logger->error("Failed to update menu placement", [
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ]);
    }
}

exit(0);