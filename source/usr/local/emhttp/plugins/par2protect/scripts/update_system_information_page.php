#!/usr/bin/php
<?php
/**
 * Update SystemInformation.page Script for PAR2Protect
 * 
 * This script adds or removes the SystemInformation.page file based on the menu placement setting.
 * If the plugin is configured to be shown in the header menu, the SystemInformation.page file should be removed.
 * If the plugin is configured to be shown in the Tools menu, the SystemInformation.page file should be created.
 */

// Include bootstrap
require_once dirname(__DIR__) . '/core/bootstrap.php';

// Get config and logger
$config = \Par2Protect\Core\Config::getInstance();
$logger = \Par2Protect\Core\Logger::getInstance();

// Get menu placement from config
$place = $config->get('display.place', 'Tasks:95');

// Path to SystemInformation.page file
$systemInfoPage = dirname(__DIR__) . '/SystemInformation.page';

// Check if the menu placement is set to header menu (Tasks:95)
if ($place === 'Tasks:95') {
    // If the plugin is in the header menu, remove the SystemInformation.page file
    if (file_exists($systemInfoPage)) {
        if (unlink($systemInfoPage)) {
            $logger->debug("SystemInformation.page file removed (plugin in header menu)");
            echo "SystemInformation.page file removed (plugin in header menu)\n";
        } else {
            $logger->error("Failed to remove SystemInformation.page file");
            echo "Failed to remove SystemInformation.page file\n";
        }
    } else {
        $logger->debug("SystemInformation.page file already removed");
        echo "SystemInformation.page file already removed\n";
    }
} else if ($place === 'SystemInformation') {
    // If the plugin is in the Tools menu, create the SystemInformation.page file
    if (!file_exists($systemInfoPage)) {
        $content = "Menu=\"Tools\"\nTitle=\"System Information\"\nType=\"menu\"";
        if (file_put_contents($systemInfoPage, $content)) {
            $logger->debug("SystemInformation.page file created (plugin in Tools menu)");
            echo "SystemInformation.page file created (plugin in Tools menu)\n";
        } else {
            $logger->error("Failed to create SystemInformation.page file");
            echo "Failed to create SystemInformation.page file\n";
        }
    } else {
        $logger->debug("SystemInformation.page file already exists");
        echo "SystemInformation.page file already exists\n";
    }
} else {
    $logger->warning("Unknown menu placement: $place");
    echo "Unknown menu placement: $place\n";
}

exit(0);