<?php
namespace Par2Protect\Core;

/**
 * Config class for managing plugin configuration
 */
class Config {
    private static $instance = null;
    private $config = [];
    private $configFile = '/boot/config/plugins/par2protect/config.json';
    
    /**
     * Configuration schema with default values and descriptions
     * This serves as both documentation and default values
     */
    private $schema = [
        'database' => [
            'description' => 'Database connection and performance settings',
            'settings' => [
                'path' => [
                    'default' => '/boot/config/plugins/par2protect/par2protect.db',
                    'description' => 'Path to the SQLite database file'
                ],
                'journal_mode' => [
                    'default' => 'WAL',
                    'description' => 'SQLite journal mode (WAL recommended for better performance)',
                    'options' => ['WAL', 'DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'OFF']
                ],
                'synchronous' => [
                    'default' => 'NORMAL',
                    'description' => 'SQLite synchronous setting (NORMAL balances safety and performance)',
                    'options' => ['OFF', 'NORMAL', 'FULL', 'EXTRA']
                ],
                'busy_timeout' => [
                    'default' => 5000,
                    'description' => 'Timeout in milliseconds to wait when database is locked'
                ],
                'max_connections' => [
                    'default' => 5,
                    'description' => 'Maximum number of database connections to maintain in the pool'
                ],
                'min_connections' => [
                    'default' => 1,
                    'description' => 'Minimum number of database connections to maintain in the pool'
                ],
                'max_idle_time' => [
                    'default' => 30,
                    'description' => 'Maximum time in seconds a connection can remain idle before being closed'
                ],
                'health_check_interval' => [
                    'default' => 60,
                    'description' => 'Interval in seconds between database connection health checks'
                ]
            ]
        ],
        'logging' => [
            'description' => 'Logging configuration settings',
            'settings' => [
                'path' => [
                    'default' => '/boot/config/plugins/par2protect/par2protect.log',
                    'description' => 'Path to the log file'
                ],
                'max_size' => [
                    'default' => 10,
                    'description' => 'Maximum log file size in MB before rotation'
                ],
                'max_files' => [
                    'default' => 5,
                    'description' => 'Maximum number of rotated log files to keep'
                ],
                'error_log_mode' => [
                    'default' => 'both',
                    'description' => 'Where to log warnings and errors',
                    'options' => ['none', 'logfile', 'syslog', 'both']
                ],
                'tmp_path' => [
                    'default' => '/tmp/par2protect/logs/par2protect.log',
                    'description' => 'Path in /tmp for logs'
                ],
                'backup_path' => [
                    'default' => '/boot/config/plugins/par2protect/logs',
                    'description' => 'User-specified path for important logs backup'
                ],
                'backup_interval' => [
                    'default' => 'daily',
                    'description' => 'How often to copy logs to backup location',
                    'options' => ['hourly', 'daily', 'weekly', 'never']
                ],
                'retention_days' => [
                    'default' => 7,
                    'description' => 'How many days to keep logs'
                ]
            ]
        ],
        'queue' => [
            'description' => 'Task queue configuration',
            'settings' => [
                'enabled' => [
                    'default' => true,
                    'description' => 'Enable or disable the task queue system'
                ],
                'processor_path' => [
                    'default' => '/usr/local/emhttp/plugins/par2protect/scripts/process_queue.php',
                    'description' => 'Path to the queue processor script'
                ],
                'max_execution_time' => [
                    'default' => 1800,
                    'description' => 'Maximum execution time in seconds for the queue processor'
                ]
            ]
        ],
        'protection' => [
            'description' => 'File protection settings',
            'settings' => [
                'default_redundancy' => [
                    'default' => 10,
                    'description' => 'Default redundancy percentage for protected files'
                ],
                'parity_dir' => [
                    'default' => '.parity',
                    'description' => 'Directory name for storing parity files'
                ],
                'verify_schedule' => [
                    'default' => 'weekly',
                    'description' => 'How often to verify protected files',
                    'options' => ['daily', 'weekly', 'monthly']
                ],
                'verify_time' => [
                    'default' => '03:00',
                    'description' => 'Time of day to run scheduled verifications (24-hour format)'
                ],
                'verify_cron' => [
                    'default' => '-1',
                    'description' => 'Cron expression for verification schedule (-1 means disabled)'
                ],
                'verify_day' => [
                    'default' => '*',
                    'description' => 'Day of the week for weekly verification (0-6, Sunday is 0)'
                ],
                'verify_dotm' => [
                    'default' => '*',
                    'description' => 'Day of the month for monthly verification (1-31)'
                ],
                'verify_hour1' => [
                    'default' => '3',
                    'description' => 'Hour for daily, weekly, and monthly verification (0-23)'
                ],
                'verify_hour2' => [
                    'default' => '*/1',
                    'description' => 'Hour interval for hourly verification (*/1, */2, */3, */4, */6, */8)'
                ],
                'verify_min' => [
                    'default' => '0',
                    'description' => 'Minute for verification (0-59)'
                ]
            ]
        ],
        'verification' => [
            'description' => 'Verification settings',
            'settings' => [
                'interval' => [
                    'default' => 86400,
                    'description' => 'Default interval in seconds between verifications (86400 = 24 hours)'
                ]
            ]
        ],
        'resource_limits' => [
            'description' => 'System resource usage limits',
            'settings' => [
                'max_cpu_usage' => [
                    'default' => 50,
                    'description' => 'Maximum CPU usage percentage for par2 operations'
                ],
                'max_concurrent_operations' => [
                    'default' => 2,
                    'description' => 'Maximum number of concurrent par2 operations'
                ],
                'max_memory_usage' => [
                    'default' => 80,
                    'description' => 'Maximum memory usage in MB (0 for unlimited)'
                ],
                'max_io_usage' => [
                    'default' => 70,
                    'description' => 'Maximum I/O usage percentage'
                ],
                'io_priority' => [
                    'default' => 'low',
                    'description' => 'I/O priority for par2 operations',
                    'options' => ['high', 'normal', 'low']
                ]
            ]
        ],
        'resource_monitoring' => [
            'description' => 'Resource monitoring settings',
            'settings' => [
                'cpu_sample_interval' => [
                    'default' => 5,
                    'description' => 'Interval in seconds between CPU usage samples'
                ],
                'memory_sample_interval' => [
                    'default' => 10,
                    'description' => 'Interval in seconds between memory usage samples'
                ],
                'io_sample_interval' => [
                    'default' => 15,
                    'description' => 'Interval in seconds between I/O usage samples'
                ],
                'max_history_entries' => [
                    'default' => 60,
                    'description' => 'Maximum number of resource usage history entries to keep'
                ],
                'adaptive_limits' => [
                    'default' => true,
                    'description' => 'Dynamically adjust resource limits based on system load'
                ]
            ]
        ],
        'monitoring' => [
            'description' => 'System monitoring settings',
            'settings' => [
                'enabled' => [
                    'default' => true,
                    'description' => 'Enable or disable system monitoring'
                ],
                'check_interval' => [
                    'default' => 300,
                    'description' => 'Interval in seconds between system checks'
                ]
            ]
        ],
        'notifications' => [
            'description' => 'Notification settings',
            'settings' => [
                'enabled' => [
                    'default' => true,
                    'description' => 'Enable or disable notifications'
                ],
                'level' => [
                    'default' => 'warnings',
                    'description' => 'Minimum level for notifications',
                    'options' => ['all', 'warnings', 'errors', 'none']
                ]
            ]
        ],
        'debug' => [
            'description' => 'Debugging settings',
            'settings' => [
                'debug_logging' => [
                    'default' => false,
                    'description' => 'Enable or disable debug logging'
                ]
            ]
        ],
        'display' => [
            'description' => 'UI display settings',
            'settings' => [
                'place' => [
                    'default' => 'SystemInformation',  // Changed from 'Tasks:95'
                    'description' => 'Menu location for the plugin',
                    'options' => ['Tasks:95', 'SystemInformation']
                ]
            ]
        ],
        'file_types' => [
            'description' => 'File type categories and custom extensions',
            'settings' => [
                'custom_extensions' => [
                    'default' => [
                        'documents' => [], 'images' => [], 'videos' => [], 
                        'audio' => [], 'archives' => [], 'code' => []
                    ],
                    'description' => 'Custom file extensions added by the user for each category'
                ]
            ]
        ]
    ];
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->loadConfig();
    }
    
    /**
     * Get singleton instance
     *
     * @return Config
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Set config file path (useful for testing)
     *
     * @param string $path
     * @return void
     */
    public function setConfigFile($path) {
        $this->configFile = $path;
        $this->loadConfig();
    }
    
    /**
     * Load configuration from file
     *
     * @return void
     */
    private function loadConfig() {
        // Start with default configuration
        $defaultConfig = $this->getDefaultConfig();
        
        // Check for legacy INI config
        $legacyConfig = $this->loadLegacyConfig();
        
        // Load user configuration if exists
        if (file_exists($this->configFile)) {
            $userConfig = json_decode(file_get_contents($this->configFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Merge user config with defaults (user config takes precedence)
                $this->config = array_replace_recursive($defaultConfig, $userConfig);
                
                // If we have legacy config, merge it in and save
                if (!empty($legacyConfig)) {
                    $this->config = array_replace_recursive($this->config, $legacyConfig);
                    $this->saveConfig();
                }
            } else {
                // Log error and use defaults
                error_log("Failed to parse config file: " . json_last_error_msg());
                $this->config = $defaultConfig;
                
                // If we have legacy config, merge it in and save
                if (!empty($legacyConfig)) {
                    $this->config = array_replace_recursive($this->config, $legacyConfig);
                    $this->saveConfig();
                }
            }
        } else {
            // Use defaults and create config file
            $this->config = $defaultConfig;
            
            // If we have legacy config, merge it in
            if (!empty($legacyConfig)) {
                $this->config = array_replace_recursive($this->config, $legacyConfig);
            }
            
            $this->saveConfig();
        }
    }
    
    /**
     * Load legacy INI configuration if it exists
     *
     * @return array
     */
    private function loadLegacyConfig() {
        $legacyConfigFile = '/boot/config/plugins/par2protect/par2protect.cfg';
        $legacyConfig = [];
        
        if (file_exists($legacyConfigFile)) {
            $iniConfig = parse_ini_file($legacyConfigFile, true);
            
            if ($iniConfig) {
                // Map INI sections to JSON structure
                foreach ($iniConfig as $section => $values) {
                    if (is_array($values)) {
                        foreach ($values as $key => $value) {
                            // Convert string booleans to actual booleans
                            if ($value === 'true') {
                                $value = true;
                            } else if ($value === 'false') {
                                $value = false;
                            }
                            // Convert numeric strings to numbers
                            else if (is_numeric($value)) {
                                $value = $value + 0;
                            }
                            
                            $legacyConfig[$section][$key] = $value;
                        }
                    }
                }
            }
        }
        
        return $legacyConfig;
    }
    
    /**
     * Get default configuration based on schema
     *
     * @return array
     */
    private function getDefaultConfig() {
        $defaults = [];
        
        foreach ($this->schema as $section => $sectionData) {
            $defaults[$section] = [];
            
            if (isset($sectionData['settings'])) {
                foreach ($sectionData['settings'] as $key => $setting) {
                    $defaults[$section][$key] = $setting['default'];
                }
            }
        }
        
        return $defaults;
    }
    
    /**
     * Get configuration value
     *
     * @param string $key Dot notation key (e.g., 'database.path')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get($key, $default = null) {
        // Support dot notation for nested config (e.g., 'database.path')
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Set configuration value
     *
     * @param string $key Dot notation key (e.g., 'database.path')
     * @param mixed $value Value to set
     * @return bool
     */
    public function set($key, $value) {
        // Support dot notation for nested config
        $keys = explode('.', $key);
        $config = &$this->config;
        
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
        
        // Don't save changes here - let the caller save when all changes are done
        // This prevents multiple file writes during a single settings update
        return true;
    }
    
    /**
     * Save configuration to file
     *
     * @return bool
     */
    public function saveConfig() {
        try {
            $configDir = dirname($this->configFile);
            if (!is_dir($configDir)) {
                if (!@mkdir($configDir, 0755, true)) {
                    error_log("Failed to create config directory: $configDir");
                    return false;
                }
            }
            
            $result = file_put_contents(
                $this->configFile,
                json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            
            // Reset Logger instance to pick up new config
            if (class_exists('\Par2Protect\Core\Logger')) {
                \Par2Protect\Core\Logger::resetInstance();
            }
            
            return $result !== false;
        } catch (\Exception $e) {
            error_log("Failed to save config: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all configuration
     *
     * @return array
     */
    public function getAll() {
        return $this->config;
    }
    
    /**
     * Get configuration schema
     *
     * @return array
     */
    public function getSchema() {
        return $this->schema;
    }
    
    /**
     * Get configuration file path
     *
     * @return string
     */
    public function getConfigFile() {
        return $this->configFile;
    }
    
    /**
     * Reload configuration from file
     *
     * @return void
     */
    public function reload() {
        $this->loadConfig();
    }
    
    /**
     * Migrate legacy INI config to JSON and remove the old file
     *
     * @return bool
     */
    public function migrateLegacyConfig() {
        $legacyConfigFile = '/boot/config/plugins/par2protect/par2protect.cfg';
        
        if (file_exists($legacyConfigFile)) {
            // Load legacy config
            $legacyConfig = $this->loadLegacyConfig();
            
            // Merge with current config
            if (!empty($legacyConfig)) {
                $this->config = array_replace_recursive($this->config, $legacyConfig);
                
                // Save the merged config
                if ($this->saveConfig()) {
                    // Rename the legacy config file as backup
                    $backupFile = $legacyConfigFile . '.bak';
                    if (file_exists($backupFile)) {
                        @unlink($backupFile);
                    }
                    @rename($legacyConfigFile, $backupFile);
                    
                    return true;
                }
            }
        }
        
        return false;
    }
}