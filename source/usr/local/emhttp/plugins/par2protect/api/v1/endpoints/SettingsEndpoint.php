<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;

class SettingsEndpoint {
    private $config;
    private $logger;
    
    /**
     * SettingsEndpoint constructor
     */
    public function __construct(
        Config $config,
        Logger $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Get all settings
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getAll($params) {
        try {
            $settings = $this->config->getAll();
            
            // Filter out sensitive information
            if (isset($settings['database']['path'])) {
                $settings['database']['path'] = $this->sanitizePath($settings['database']['path']);
            }
            
            if (isset($settings['logging']['path'])) {
                $settings['logging']['path'] = $this->sanitizePath($settings['logging']['path']);
            }
            
            Response::success($settings);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get settings: " . $e->getMessage());
        }
    }
    
    /**
     * Update settings
     *
     * @param array $params Request parameters
     * @return void
     */
    public function update($params) {
        try {
            // Get request data
            $data = $_POST;
            
            // Validate request
            if (empty($data)) {
                throw ApiException::badRequest("No settings provided");
            }
            
            // Update settings
            $updated = [];
            
            foreach ($data as $key => $value) {
                // Skip invalid keys
                if (!$this->isValidSettingKey($key)) {
                    continue;
                }
                
                // Update setting
                $this->config->set($key, $value);
                $updated[] = $key;
            }
            
            if (empty($updated)) {
                throw ApiException::badRequest("No valid settings provided");
            }
            
            $this->logger->debug("Settings updated", [
                'updated_keys' => $updated
            ]);
            
            Response::success(['updated' => $updated], 'Settings updated successfully');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to update settings: " . $e->getMessage());
        }
    }
    
    /**
     * Reset settings to defaults
     *
     * @param array $params Request parameters
     * @return void
     */
    public function reset($params) {
        try {
            // Get config file path
            $configFile = $this->config->getConfigFile();
            
            // Delete config file
            if (file_exists($configFile)) {
                unlink($configFile);
            }
            
            // Reload config
            $this->config->reload();
            
            $this->logger->debug("Settings reset to defaults");
            
            Response::success(null, 'Settings reset to defaults');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to reset settings: " . $e->getMessage());
        }
    }
    
    /**
     * Check if setting key is valid
     *
     * @param string $key Setting key
     * @return bool
     */
    private function isValidSettingKey($key) {
        // List of valid setting keys
        $validKeys = [
            'database.journal_mode',
            'database.synchronous',
            'database.busy_timeout',
            'logging.max_size',
            'logging.max_files',
            'logging.level',
            'queue.enabled',
            'queue.max_execution_time',
            'protection.default_redundancy',
            'protection.parity_dir',
            'verification.interval'
        ];
        
        return in_array($key, $validKeys);
    }
    
    /**
     * Sanitize path for security
     *
     * @param string $path Path to sanitize
     * @return string
     */
    private function sanitizePath($path) {
        // Replace home directory with ~
        $homeDir = '/boot/config';
        if (strpos($path, $homeDir) === 0) {
            return '~' . substr($path, strlen($homeDir));
        }
        
        return $path;
    }
}