// Logger for Par2Protect JavaScript
(function() {
    // Initialize Par2Protect namespace if it doesn't exist first
    window.Par2Protect = window.Par2Protect || {}; 
    const P = window.Par2Protect;
    
    // Logger levels
    const LOG_LEVELS = {
        DEBUG: 0,
        INFO: 1,
        WARNING: 2,
        ERROR: 3,
        CRITICAL: 4
    };
    
    // Logger configuration
    const config = {
        // Minimum level to log to console (DEBUG = 0, INFO = 1, WARNING = 2, ERROR = 3, CRITICAL = 4)
        consoleLevel: LOG_LEVELS.INFO, // Default to INFO level to reduce console spam
        // Whether to send logs to server
        sendToServer: true,
        // API endpoint for logging
        apiEndpoint: '/plugins/par2protect/api/v1/index.php?endpoint=logs/entries',
        // Whether debug logging is enabled (matches server-side config)
        debugLoggingEnabled: false
    };
    
    // Logger methods
    const logger = {
        /**
         * Log debug message
         * @param {string} message - Message to log
         * @param {object} context - Additional context data
         */
        debug: function(message, context = {}) {
            this._log(message, LOG_LEVELS.DEBUG, 'DEBUG', context);
        },
        
        /**
         * Log info message
         * @param {string} message - Message to log
         * @param {object} context - Additional context data
         */
        info: function(message, context = {}) {
            this._log(message, LOG_LEVELS.INFO, 'INFO', context);
        },
        
        /**
         * Log warning message
         * @param {string} message - Message to log
         * @param {object} context - Additional context data
         */
        warning: function(message, context = {}) {
            this._log(message, LOG_LEVELS.WARNING, 'WARNING', context);
        },
        
        /**
         * Log error message
         * @param {string} message - Message to log
         * @param {object} context - Additional context data
         */
        error: function(message, context = {}) {
            this._log(message, LOG_LEVELS.ERROR, 'ERROR', context);
        },
        
        /**
         * Internal logging method
         * @param {string} message - Message to log
         * @param {number} level - Numeric log level
         * @param {string} levelName - String log level name
         * @param {object} context - Additional context data
         * @private
         */
        _log: function(message, level, levelName, context = {}) {
            // Format timestamp
            const timestamp = new Date().toISOString();
            
            // Skip debug logs if debug logging is disabled
            if (level === LOG_LEVELS.DEBUG && !config.debugLoggingEnabled) {
                return;
            }
            
            // Log to console if level is high enough
            if (level >= config.consoleLevel) {
                let consoleMsg = `[${levelName}] ${message}`;
                
                // Log to appropriate console method
                switch (level) {
                    case LOG_LEVELS.DEBUG:
                        console.log(consoleMsg, context);
                        break;
                    case LOG_LEVELS.INFO:
                        console.info(consoleMsg, context);
                        break;
                    case LOG_LEVELS.WARNING:
                        console.warn(consoleMsg, context);
                        break;
                    case LOG_LEVELS.ERROR:
                    case LOG_LEVELS.CRITICAL:
                        console.error(consoleMsg, context);
                        break;
                }
            }
            
            // Send to server if configured
            if (config.sendToServer) {
                // In a real implementation, we would send the log to the server
                // However, since there's no API endpoint for adding logs from JavaScript,
                // we'll just log to console for now
                
                // If an API endpoint is added in the future, we could use:
                /*
                $.ajax({
                    url: '/plugins/par2protect/api/v1/index.php?endpoint=logs/add',
                    method: 'POST',
                    data: {
                        level: levelName,
                        message: message,
                        context: JSON.stringify(context)
                    },
                    dataType: 'json'
                });
                */
            }
        },
        
        /**
         * Set minimum console log level
         * @param {number} level - Minimum level to log to console
         * @returns {object} - The logger instance for chaining
         */
        setConsoleLevel: function(level) {
            config.consoleLevel = level;
            return this;
        },
        
        /**
         * Enable or disable server logging
         * @param {boolean} enabled - Whether to send logs to server
         * @returns {object} - The logger instance for chaining
         */
        setSendToServer: function(enabled) {
            config.sendToServer = enabled;
            return this;
        },
        
        /**
         * Enable or disable debug logging
         * @param {boolean} enabled - Whether to enable debug logging
         * @returns {object} - The logger instance for chaining
         */
        setDebugLogging: function(enabled) {
            config.debugLoggingEnabled = enabled;
            return this;
        },
        
        /**
         * Initialize logger with server settings
         * @param {object} settings - Server settings
         * @returns {object} - The logger instance for chaining
         */
        initWithSettings: function(settings) {
            if (settings && typeof settings === 'object') {
                // Check if debug logging is enabled in server settings
                if (settings.debug && typeof settings.debug.debug_logging !== 'undefined') {
                    this.setDebugLogging(settings.debug.debug_logging === true);
                }
            }
            return this;
        }
    };
    
    // Add logger to Par2Protect
    P.logger = logger;
    
})();