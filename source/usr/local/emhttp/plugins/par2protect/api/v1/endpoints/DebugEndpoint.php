<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Container;

/**
 * Debug endpoint for troubleshooting container issues
 * This is a temporary endpoint for debugging purposes only
 */
class DebugEndpoint {
    private $container;
    
    /**
     * Constructor
     * 
     * @param Container $container
     */
    public function __construct(Container $container) {
        $this->container = $container;
    }
    
    /**
     * Get registered services
     * 
     * @return void
     */
    public function getServices() {
        // Get all registered services
        $services = [];
        
        // Use reflection to access private properties
        $reflection = new \ReflectionClass($this->container);
        $servicesProperty = $reflection->getProperty('services');
        $servicesProperty->setAccessible(true);
        $registeredServices = $servicesProperty->getValue($this->container);
        
        // Format the services for display
        foreach ($registeredServices as $key => $value) {
            $services[] = $key;
        }
        
        // Return the services
        Response::json([
            'success' => true,
            'services' => $services
        ]);
    }
}