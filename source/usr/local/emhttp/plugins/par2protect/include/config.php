<?php
namespace Par2Protect;

class Config {
    private static $instance = null;
    private $config = [];
    private $configFile = '';
    private $defaultConfigFile = '';
    
    const CONFIG_PATH = '/boot/config/plugins/par2protect';
    const PLUGIN_PATH = '/usr/local/emhttp/plugins/par2protect';
    
    private function __construct() {
        try {
            // Ensure config directory exists
            if (!is_dir(self::CONFIG_PATH)) {
                if (!@mkdir(self::CONFIG_PATH, 0755, true)) {
                    error_log("Failed to create config directory: " . self::CONFIG_PATH);
                    throw new \Exception("Failed to create config directory");
                }
            }
            
            $this->configFile = self::CONFIG_PATH . '/par2protect.cfg';
            $this->defaultConfigFile = self::PLUGIN_PATH . '/config/default.cfg';
            
            // Log paths for debugging
            error_log("Config paths - User: {$this->configFile}, Default: {$this->defaultConfigFile}");
            
            $this->loadConfig();
        } catch (\Exception $e) {
            error_log("Config initialization failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadConfig() {
        // Set default configuration
        $this->config = [
            'debug' => ['debug_logging' => false],
            'resource_limits' => [
                'max_cpu_usage' => 50,
                'max_concurrent_operations' => 2,
                'io_priority' => 'low'
            ]
        ];
        
        // Load user config if exists
        if (file_exists($this->configFile)) {
            $userConfig = @parse_ini_file($this->configFile, true);
            if ($userConfig !== false) {
                foreach ($userConfig as $section => $values) {
                    if (!isset($this->config[$section])) {
                        $this->config[$section] = [];
                    }
                    foreach ($values as $key => $value) {
                        $this->config[$section][$key] = $value;
                    }
                }
            }
        }
    }
    
    public function get($key, $default = null) {
        return $this->getNestedValue($this->config, $key, $default);
    }
    
    public function set($key, $value) {
        $this->setNestedValue($this->config, $key, $value);
        return $this->saveConfig();
    }
    
    private function getNestedValue(array $array, $key, $default = null) {
        $segments = explode('.', $key);
        $current = $array;
        
        foreach ($segments as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }
        
        return $current;
    }
    
    private function setNestedValue(array &$array, $key, $value) {
        if (strpos($key, '.') === false) {
            $array[$key] = $value;
            return;
        }
        
        $keys = explode('.', $key);
        $current = &$array;
        
        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        
        $current = $value;
    }
    
    private function saveConfig() {
        try {
            $content = '';
            foreach ($this->config as $section => $values) {
                if (is_array($values)) {
                    $content .= "[$section]\n";
                    foreach ($values as $key => $value) {
                        $content .= "$key = " . $this->formatValue($value) . "\n";
                    }
                    $content .= "\n";
                } else {
                    $content .= "$section = " . $this->formatValue($values) . "\n";
                }
            }
            
            $result = @file_put_contents($this->configFile, $content);
            if ($result === false) {
                error_log("Failed to write config file: " . $this->configFile);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            error_log("Failed to save config: " . $e->getMessage());
            return false;
        }
    }
    
    private function formatValue($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return $value;
        }
        if (is_array($value)) {
            return '"' . implode(',', $value) . '"';
        }
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}