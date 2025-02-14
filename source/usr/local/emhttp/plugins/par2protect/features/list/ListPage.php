<?php
namespace Par2Protect\Features\List;

class ListPage {
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
        
        // Log page load
        $logger->debug("Protected Files list page loaded");
        
        // Include the template
        include __DIR__ . '/list.php';
    }
}