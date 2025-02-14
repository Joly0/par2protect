<?php
namespace Par2Protect;

class Config {
    private static $instance = null;
    private $config = [];
    private $configFile = '';
    private $defaultConfigFile = '';
    
    const CONFIG_PATH = '/boot/config/plugins/par2protect';
    const DEFAULT_CONFIG_PATH = '/usr/local/emhttp/plugins/par2protect/config';
    
    private function __construct() {
        // Ensure config directory exists
        if (!is_dir(self::CONFIG_PATH)) {
            mkdir(self::CONFIG_PATH, 0755, true);
        }
        
        $this->configFile = self::CONFIG_PATH . '/par2protect.cfg';
        $this->defaultConfigFile = self::DEFAULT_CONFIG_PATH . '/default.cfg';
        $this->loadConfig();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadConfig() {
        // Load default config first
        if (file_exists($this->defaultConfigFile)) {
            $this->config = parse_ini_file($this->defaultConfigFile, true);
        }
        
        // Override with user config if exists
        if (file_exists($this->configFile)) {
            $userConfig = parse_ini_file($this->configFile, true);
            $this->config = array_replace_recursive($this->config, $userConfig);
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
        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }
        
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        
        return $array;
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
        
        return file_put_contents($this->configFile, $content) !== false;
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