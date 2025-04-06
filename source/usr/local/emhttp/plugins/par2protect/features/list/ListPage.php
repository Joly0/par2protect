<?php
namespace Par2Protect\Features\List;

class ListPage {
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
        
        // Logger should always be available via constructor injection now
        
        // Log page load
        $logger->debug("Protected Files list page loaded");
        
        // Include the template
        include __DIR__ . '/list.php';
    }
}