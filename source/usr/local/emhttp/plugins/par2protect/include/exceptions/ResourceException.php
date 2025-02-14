<?php
namespace Par2Protect\Exceptions;

class ResourceException extends \Exception {
    protected $resourceType;
    protected $currentUsage;
    protected $limit;
    
    public function __construct($message, $resourceType = null, $currentUsage = null, $limit = null, $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->resourceType = $resourceType;
        $this->currentUsage = $currentUsage;
        $this->limit = $limit;
    }
    
    public function getResourceType() {
        return $this->resourceType;
    }
    
    public function getCurrentUsage() {
        return $this->currentUsage;
    }
    
    public function getLimit() {
        return $this->limit;
    }
    
    public function getContext() {
        return [
            'resource_type' => $this->resourceType,
            'current_usage' => $this->currentUsage,
            'limit' => $this->limit
        ];
    }
}

class ResourceLimitExceededException extends ResourceException {
    public function __construct($resourceType, $currentUsage, $limit, $code = 0, \Throwable $previous = null) {
        $message = sprintf(
            '%s usage exceeded: current usage %.2f%% > limit %.2f%%',
            ucfirst($resourceType),
            $currentUsage,
            $limit
        );
        parent::__construct($message, $resourceType, $currentUsage, $limit, $code, $previous);
    }
}

class ResourceMonitoringException extends ResourceException {
    public function __construct($resourceType, $message, $code = 0, \Throwable $previous = null) {
        $fullMessage = sprintf('Failed to monitor %s: %s', $resourceType, $message);
        parent::__construct($fullMessage, $resourceType, null, null, $code, $previous);
    }
}

class ResourceConfigurationException extends ResourceException {
    public function __construct($message, $code = 0, \Throwable $previous = null) {
        parent::__construct('Resource configuration error: ' . $message, null, null, null, $code, $previous);
    }
}