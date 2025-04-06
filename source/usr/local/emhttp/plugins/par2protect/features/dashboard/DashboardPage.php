<?php
namespace Par2Protect\Features\Dashboard;

class DashboardPage {
    private $logger;
    private $config;
    
    public function __construct() {
        // Ensure bootstrap is included (if not already)
        include_once(dirname(dirname(__DIR__)) . '/core/bootstrap.php');
        
        // Get the container instance using the global function
        $container = get_container();

        // Get required services from the container
        $this->logger = $container->get('logger');
        $this->config = $container->get('config');
    }
    
    public function render() {
        // Get logger and config from class properties
        $logger = $this->logger;
        $config = $this->config;
        
        // Logger and Config should always be available via constructor injection now
        
        // Settings are now accessed via the injected $this->config service
        // $par2protect_settings = $this->config->getAll(); // Example if needed in template
        
        // Log page load with settings
        $logger->debug("DashboardPage.php file loaded", [
            'file' => 'DashboardPage.php',
            // 'mode' => $par2protect_settings['mode'], // Removed reference to undefined variable
            '_dashboard' => false // Ensure this doesn't show in activity log
        ]); // Don't show in dashboard
        
        // Get current values from settings
        $currentPaths = ''; // Will be populated from settings later
        
        // Include the template
        include __DIR__ . '/dashboard.php';
    }
}