<?php
namespace Par2Protect\Features\Settings;

class SettingsPage {
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
        
        // Get settings from config class
        $settings = $config->getAll();
        $schema = $config->getSchema();
        
        // Log page load
        $logger->debug("Settings page loaded", [
            'settings' => $settings
        ]);
        
        // Include the template
        include __DIR__ . '/settings.php';
    }
    
    /**
     * Process settings form submission
     *
     * @param array $formData Form data from POST
     * @return bool Success status
     */
    public function processSettings($formData) {
        $config = $this->config;
        $logger = $this->logger;
        
        $logger->debug("Processing settings form", [
            'form_data_count' => count($formData)
        ]);
        
        // Process each setting
        foreach ($formData as $key => $value) {
            // Skip special form fields
            if (substr($key, 0, 1) === '#') {
                continue;
            }
            
            // Map form fields to config keys
            $configKey = $this->mapFormFieldToConfigKey($key);
            if ($configKey) {
                // Convert value types as needed
                $value = $this->convertValueType($configKey, $value);
                
                // Update config
                $config->set($configKey, $value);
                
                /*$logger->debug("Updated setting", [
                    'key' => $configKey,
                    'value' => $value
                ]); */
            }
        }
        
        // Save config
        $result = $config->saveConfig();
        
        if ($result) {
            $logger->debug("Settings saved successfully");
        } else {
            $logger->error("Failed to save settings");
        }
        
        return $result;
    }
    
    /**
     * Map form field name to config key
     * 
     * @param string $field Form field name
     * @return string|null Config key or null if not found
     */
    private function mapFormFieldToConfigKey($field) {
        // Direct mappings
        $mappings = [
            'place' => 'display.place',
            'default_redundancy' => 'protection.default_redundancy',
            'verify_schedule' => 'protection.verify_schedule',
            'verify_time' => 'protection.verify_time',
            'verify_cron' => 'protection.verify_cron',
            'verify_day' => 'protection.verify_day',
            'verify_dotm' => 'protection.verify_dotm',
            'verify_hour1' => 'protection.verify_hour1',
            'verify_hour2' => 'protection.verify_hour2',
            'verify_min' => 'protection.verify_min',
            'max_cpu_usage' => 'resource_limits.max_cpu_usage',
            'max_memory' => 'resource_limits.max_memory_usage',
            'io_priority' => 'resource_limits.io_priority',
            'max_concurrent_operations' => 'resource_limits.max_concurrent_operations',
            'parallel_file_hashing' => 'resource_limits.parallel_file_hashing',
            'notifications_enabled' => 'notifications.enabled',
            'debug_logging' => 'debug.debug_logging',
            'error_log_mode' => 'logging.error_log_mode',
            'log_backup_interval' => 'logging.backup_interval',
            'log_retention_days' => 'logging.retention_days',
            'custom_extensions' => 'file_types.custom_extensions',
            'default_extensions' => 'file_types.default_extensions'
        ];
        
        // Log unmapped fields for debugging
        /* if (!isset($mappings[$field]) && substr($field, 0, 1) !== '#') {
            $this->logger->debug("Unmapped form field: $field");
        } */
        
        return $mappings[$field] ?? null;
    }
    
    /**
     * Convert value to appropriate type based on config key
     * 
     * @param string $key Config key
     * @param mixed $value Value to convert
     * @return mixed Converted value
     */
    private function convertValueType($key, $value) {
        // Boolean fields
        $booleanFields = [
            'file_types.custom_extensions',
            'file_types.default_extensions',
            'debug.debug_logging',
            'queue.enabled',
            'resource_monitoring.adaptive_limits',
            'notifications.enabled'
        ];
        
        // Numeric fields
        $numericFields = [
            'protection.default_redundancy',
            'resource_limits.max_cpu_usage',
            'resource_limits.max_memory_usage',
            'resource_limits.max_io_usage',
            'resource_limits.max_concurrent_operations',
            'resource_monitoring.cpu_sample_interval',
            'resource_monitoring.memory_sample_interval',
            'resource_monitoring.io_sample_interval',
            'resource_monitoring.max_history_entries',
            'database.max_connections',
            'database.min_connections',
            'database.max_idle_time',
            'database.health_check_interval',
            'database.busy_timeout',
            'logging.max_size',
            'logging.max_files',
            'logging.retention_days',
            'queue.max_execution_time',
            'verification.interval'
        ];
        
        if (in_array($key, $booleanFields)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } else if (in_array($key, $numericFields)) {
            return is_numeric($value) ? $value + 0 : $value;
        } else if ($key === 'file_types.custom_extensions') {
            // Handle custom extensions
            try {
                // Parse JSON string
                $customExtensions = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($customExtensions)) {
                    return $customExtensions;
                }
            } catch (\Exception $e) {
                $this->logger->error("Error parsing custom extensions: " . $e->getMessage());
            }
            return [];
        }
        else if ($key === 'file_types.default_extensions') {
            // Handle default extensions
            try {
                // Parse JSON string
                $defaultExtensions = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($defaultExtensions)) {
                    return $defaultExtensions;
                }
            } catch (\Exception $e) {
                $this->logger->error("Error parsing default extensions: " . $e->getMessage());
            }
            return [];
        }
        
        return $value;
    }
}