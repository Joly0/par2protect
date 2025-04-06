#!/usr/bin/php
<?php
/**
 * Update Menu Placement Script for PAR2Protect
 *
 * This script updates the menu placement INI file based on the JSON configuration.
 * It's needed because Unraid expects an INI file for the Menu directive in .page files.
 * It also updates the SystemInformation.page file based on the menu placement setting.
 */

// Include bootstrap
require_once dirname(__DIR__) . '/core/bootstrap.php';

// Get components from container
$container = get_container();
$config = $container->get('config');
$logger = $container->get('logger');

// Get menu placement from config
$place = $config->get('display.place', 'Tasks:95');

// Create menu placement INI file
$menuFile = '/boot/config/plugins/par2protect/menu.cfg';
$menuDir = dirname($menuFile);

// Create directory if it doesn't exist
if (!is_dir($menuDir)) {
    mkdir($menuDir, 0755, true);
}

// Write INI file
$content = "[display]\nplace=$place\n";
file_put_contents($menuFile, $content);

$logger->debug("Menu placement updated to: $place");
echo "Menu placement updated to: $place\n";

// Update SystemInformation.page file
$updateSystemInfoScript = dirname(__FILE__) . '/update_system_information_page.php';
if (file_exists($updateSystemInfoScript)) {
    $logger->debug("Updating SystemInformation.page file");
    exec($updateSystemInfoScript, $output, $returnCode);
    
    if ($returnCode === 0) {
        $logger->debug("SystemInformation.page file updated successfully", [
            'output' => implode("\n", $output)
        ]);
        echo implode("\n", $output) . "\n";
    } else {
        $logger->error("Failed to update SystemInformation.page file", [
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ]);
        echo "Failed to update SystemInformation.page file\n";
    }
} else {
    $logger->error("update_system_information_page.php script not found");
    echo "update_system_information_page.php script not found\n";
}

exit(0);