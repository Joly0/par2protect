<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;

class LogEndpoint {
    private $logger;
    private $config;
    
    /**
     * LogEndpoint constructor
     */
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
    }
    
    /**
     * Get recent activity
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getActivity($params) {
        try {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $type = isset($_GET['type']) ? $_GET['type'] : null;
            $includeSystem = isset($_GET['include_system']) ? (bool)$_GET['include_system'] : false;
            
            /* $this->logger->debug("LogEndpoint::getActivity called", [
                'limit' => $limit, 'type' => $type, 'includeSystem' => $includeSystem
            ]); */
            $activity = $this->logger->getRecentActivity($limit, $type, $includeSystem);
            // $this->logger->debug("Activity data retrieved", ['count' => count($activity)]);

            Response::success($activity);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get activity: " . $e->getMessage());
        }
    }
    
    /**
     * Get log entries
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getEntries($params) {
        try {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $level = isset($_GET['level']) ? strtoupper($_GET['level']) : null;
            $search = isset($_GET['search']) ? $_GET['search'] : null;
            
            $entries = $this->getLogEntries($limit, $level, $search);
            Response::success($entries);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get log entries: " . $e->getMessage());
        }
    }
    
    /**
     * Clear log file
     *
     * @param array $params Request parameters
     * @return void
     */
    public function clear($params) {
        try {
            $logFile = $this->config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
            
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
                $this->logger->debug("Log file cleared");
            }
            
            Response::success(null, 'Log file cleared');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to clear log file: " . $e->getMessage());
        }
    }
    
    /**
     * Get log entries from log file
     *
     * @param int $limit Maximum number of entries to return
     * @param string $level Filter by log level
     * @param string $search Search term
     * @return array
     */
    private function getLogEntries($limit = 100, $level = null, $search = null) {
        $logFile = $this->config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $entries = [];
        
        try {
            // Read the last part of the log file
            $fileSize = filesize($logFile);
            $readSize = min($fileSize, 100000); // Read at most 100KB from the end
            
            $handle = fopen($logFile, 'r');
            if ($handle === false) {
                return [];
            }
            
            fseek($handle, -$readSize, SEEK_END);
            $content = fread($handle, $readSize);
            fclose($handle);
            
            // Split into lines and reverse to get newest first
            $lines = array_reverse(explode(PHP_EOL, $content));
            
            foreach ($lines as $line) {
                if (count($entries) >= $limit) {
                    break;
                }
                
                if (preg_match('/\[(.*?)\] (.*?): (.*?)( \{.*\})?$/', $line, $matches)) {
                    $time = $matches[1];
                    $logLevel = $matches[2];
                    $message = $matches[3];
                    $contextJson = isset($matches[4]) ? trim($matches[4]) : '';
                    
                    // Filter by level
                    if ($level !== null && $logLevel !== $level) {
                        continue;
                    }
                    
                    // Filter by search term
                    if ($search !== null && stripos($message, $search) === false) {
                        continue;
                    }
                    
                    // Parse context
                    $context = [];
                    if (!empty($contextJson)) {
                        $context = json_decode($contextJson, true) ?: [];
                    }
                    
                    // Format entry
                    $entry = [
                        'time' => $time,
                        'level' => $logLevel,
                        'message' => $message,
                        'context' => $context
                    ];
                    
                    $entries[] = $entry;
                }
            }
        } catch (\Exception $e) {
            throw new ApiException("Failed to read log file: " . $e->getMessage());
        }
        
        return $entries;
    }
    
    /**
     * Download log file
     *
     * @param array $params Request parameters
     * @return void
     */
    public function download($params) {
        try {
            $logFile = $this->config->get('logging.path', '/boot/config/plugins/par2protect/par2protect.log');
            
            if (!file_exists($logFile)) {
                throw ApiException::notFound("Log file not found");
            }
            
            // Log the download
            // $this->logger->debug("Log file downloaded");
            
            // Send file
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="par2protect.log"');
            header('Content-Length: ' . filesize($logFile));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            readfile($logFile);
            exit;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to download log file: " . $e->getMessage());
        }
    }
}