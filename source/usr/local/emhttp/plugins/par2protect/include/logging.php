<?php
namespace Par2Protect;

class Logger {
    private static $instance = null;
    private $logFile = '';
    private $config;
    
    const LOG_LEVELS = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'NOTICE'  => 2,
        'WARNING' => 3,
        'ERROR'   => 4,
        'CRITICAL'=> 5
    ];
    
    private function __construct() {
        $this->config = Config::getInstance();
        $logPath = $this->config->get('paths.log_path', '/mnt/user/appdata/par2protect/logs');
        
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        
        $this->logFile = $logPath . '/par2protect.log';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function log($message, $level = 'INFO', $context = []) {
        if (!isset(self::LOG_LEVELS[$level])) {
            $level = 'INFO';
        }
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        $logLine = sprintf(
            "[%s] %s: %s %s\n",
            $entry['timestamp'],
            $entry['level'],
            $entry['message'],
            !empty($context) ? json_encode($context) : ''
        );
        
        return file_put_contents($this->logFile, $logLine, FILE_APPEND) !== false;
    }
    
    public function debug($message, $context = []) {
        return $this->log($message, 'DEBUG', $context);
    }
    
    public function info($message, $context = []) {
        return $this->log($message, 'INFO', $context);
    }
    
    public function warning($message, $context = []) {
        return $this->log($message, 'WARNING', $context);
    }
    
    public function error($message, $context = []) {
        return $this->log($message, 'ERROR', $context);
    }
    
    public function critical($message, $context = []) {
        return $this->log($message, 'CRITICAL', $context);
    }
}