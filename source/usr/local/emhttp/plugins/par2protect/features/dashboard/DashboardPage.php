<?php
namespace Par2Protect\Features\Dashboard;

class DashboardPage {
    private $logger;
    private $config;
    
    public function __construct() {
        $this->logger = \Par2Protect\Core\Logger::getInstance();
        $this->config = \Par2Protect\Core\Config::getInstance();
    }
    
    public function render() {
        // Get logger and config from class properties
        $logger = $this->logger;
        $config = $this->config;
        
        // Get settings from file directly for the UI
        $par2protect_settings = parse_ini_file("/boot/config/plugins/par2protect/par2protect.cfg") ?: [];
        $par2protect_settings['mode'] = $par2protect_settings['mode'] ?? 'file';
        
        // Log page load with settings
        $logger->debug("Dashboard page loaded", [
            'mode' => $par2protect_settings['mode']
        ], false); // Don't show in dashboard
        
        // Get current values from settings
        $currentPaths = ''; // Will be populated from settings later
        
        // Include the template
        include __DIR__ . '/dashboard.php';
    }
}