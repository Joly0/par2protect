// Common JavaScript for PAR2Protect Plugin

// Check if this script has already been loaded to prevent duplicate initialization
if (window.Par2ProtectScriptsLoaded && window.Par2ProtectScriptsLoaded.common) {
    // Skip initialization if already loaded
} else {
    // Initialize scripts loaded tracking object if it doesn't exist
    window.Par2ProtectScriptsLoaded = window.Par2ProtectScriptsLoaded || {};
    window.Par2ProtectScriptsLoaded.common = true;
    
    // Initialize Par2Protect namespace if it doesn't exist
    window.Par2Protect = window.Par2Protect || {};
    
    // Debug logging is now disabled in production

    (function(P) {
        // We'll log to the logger after it's available in the IIFE
        // Configuration
        P.config = {
            isInitialized: false,
            isLoading: false,
            statusCheckTimer: null,
            updateInterval: 5000, // 5 seconds
            hasActiveOperations: false, // Flag to track if there are active operations
            lastOperationStatus: {}, // Track last known operation status
            recentlyCompletedOperations: [], // Track recently completed operations
            // Server settings (will be populated from API)
            serverSettings: null,
            fileCategories: {
                documents: {
                    description: 'Documents',
                    extensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp']
                },
                images: {
                    description: 'Images',
                    extensions: ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'svg']
                },
                videos: {
                    description: 'Videos',
                    extensions: ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg']
                },
                audio: {
                    description: 'Audio',
                    extensions: ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma']
                },
                archives: {
                    description: 'Archives',
                    extensions: ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz']
                },
                code: {
                    description: 'Code Files',
                    extensions: ['php', 'js', 'html', 'css', 'py', 'java', 'c', 'cpp', 'h', 'sh', 'json', 'xml', 'yml', 'yaml']
                }
            }
        };

        // Set loading state
        P.setLoading = function(isLoading) {
            // Debug logging is now disabled in production
            
            P.config.isLoading = isLoading;
            
            // Remove existing overlay
            $('.loading-overlay').remove();
            
            // Add overlay if loading
            if (isLoading) {
                $('body').append(`
                    <div class="loading-overlay">
                        <div class="loading-spinner"></div>
                    </div>
                `);
            }
        };

        // Show notification
        P.showNotification = function(message, type = 'info') {
            // Remove existing notifications
            $('.notification').remove();
            
            // Create notification
            const notification = $(`
                <div class="notification ${type}">
                    <span class="message">${message}</span>
                    <span class="close">&times;</span>
                </div>
            `);
            
            // Add to body
            $('body').append(notification);
            
            // Show notification
            setTimeout(() => {
                notification.addClass('show');
            }, 10);
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                notification.removeClass('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
            
            // Close button
            notification.find('.close').on('click', function() {
                notification.removeClass('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            });
        };

        // Format file size
        P.formatBytes = function(bytes) { // Renamed from formatSize
            if (bytes === 0) return '0 B';
            
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        };

        // Format date
        P.formatDate = function(date) {
            if (!date) return '';
            
            const d = new Date(date);
            return d.toLocaleString();
        };

        // Escape HTML
        P.escapeHtml = function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        };

        // Add notice to the page
        window.addNotice = function(message, type = 'info') {
            P.showNotification(message, type);
        };

        // Event system for operation completion
        P.events = {
            // Event listeners
            listeners: {},
            // Track registered listeners by function toString to prevent duplicates
            registeredListeners: {},
            // Flag to track if event system is initialized
            initialized: false,

            // Add event listener with duplicate prevention
            on: function(event, callback) {
                // Create a unique key for this callback
                const callbackKey = callback.toString();
                
                // Check if this exact callback is already registered for this event
                if (this.registeredListeners[event] && this.registeredListeners[event][callbackKey]) {
                    console.log('Preventing duplicate event listener registration for event:', event);
                    return; // Skip registration of duplicate listener
                }
                
                // Initialize arrays if needed
                if (!this.listeners[event]) {
                    this.listeners[event] = [];
                }
                if (!this.registeredListeners[event]) {
                    this.registeredListeners[event] = {};
                }
                
                // Register the callback
                this.listeners[event].push(callback);
                this.registeredListeners[event][callbackKey] = true;
                
                // Debug logging is now disabled in production
            },

            // Remove event listener
            off: function(event, callback) {
                if (!this.listeners[event]) return;
                
                if (callback) {
                    // Remove specific callback
                    const callbackKey = callback.toString();
                    this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
                    if (this.registeredListeners[event]) {
                        delete this.registeredListeners[event][callbackKey];
                    }
                } else {
                    // Remove all callbacks for this event
                    delete this.listeners[event];
                    delete this.registeredListeners[event];
                }
            },

            // Trigger event
            trigger: function(event, data) {
                if (!this.listeners[event]) return;
                
                // Debug logging is now disabled in production
                
                this.listeners[event].forEach(callback => {
                    try {
                        callback(data);
                    } catch (e) {
                        console.error('Error in event listener:', e);
                        if (P.logger) {
                            P.logger.error('Error in event listener:', { error: e.toString() });
                        }
                    }
                });
            }
        };

        // Operation types that affect the protected files list
        P.protectedListOperations = ['protect', 'verify', 'repair', 'remove'];
        
        // Trigger operation completed event for direct operations
        P.triggerOperationCompleted = function(operationType, status, result) {
            // Create a unique ID for the direct operation
            const operationId = 'direct-' + operationType + '-' + Date.now();
            
            // Add a flag to indicate this is a direct operation
            const eventData = {
                id: operationId,
                operation_type: operationType,
                status: status,
                result: result,
                _source: 'direct',
                _direct_operation: true
            };
            
            console.log('Triggering direct operation.completed event at:', new Date().toISOString(),
                'type:', operationType, 'status:', status);
            
            // Trigger the operation completed event
            P.events.trigger('operation.completed', eventData);
            
            // We no longer directly refresh the list here
            // This prevents duplicate refreshes since the event handler will handle it
        };

        // Initialize logger with server settings
        P.initializeLogger = function() {
            // Fetch settings from server
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=settings',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Store settings
                        P.config.serverSettings = response.data;
                        
                        // Update redundancy slider if available
                        if (response.data.protection && response.data.protection.default_redundancy) {
                            const defaultRedundancy = parseInt(response.data.protection.default_redundancy) || 10;
                            $('#redundancy-slider').val(defaultRedundancy);
                            $('.redundancy-value').text(defaultRedundancy);
                        }
                        
                        // Update file categories with custom extensions if available
                        if (response.data.file_types &&
                            response.data.file_types.custom_extensions) {
                            const customExtensions = response.data.file_types.custom_extensions;
                            // Store custom extensions for use in dashboard
                            P.config.customFileExtensions = customExtensions;
                        }
                        
                        // Initialize logger with settings
                        if (P.logger && typeof P.logger.initWithSettings === 'function') {
                            P.logger.initWithSettings(response.data);
                            
                            // Log that files are loaded (now that logger is initialized)
                            P.logger.debug('common.js file loaded', { file: 'common.js', _dashboard: false });
                            
                            // Also log for other files that might have been loaded before logger was initialized
                            P.logger.debug('dashboard.js file loaded (logged from common.js)', { file: 'dashboard.js', _dashboard: false });
                            P.logger.debug('queue-manager.js file loaded (logged from common.js)', { file: 'queue-manager.js', _dashboard: false });
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load settings for logger initialization:', error);
                }
            });
        };
        
        /* Removed fixSweetAlertTextColor function */
        
        // Initialize logger when document is ready
        $(document).ready(function() {
            P.initializeLogger();
            // EventSource initialization moved to dashboard.js to ensure proper timing
        });

    })(window.Par2Protect);
}