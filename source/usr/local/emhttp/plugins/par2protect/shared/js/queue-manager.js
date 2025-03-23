// Queue Manager for Par2Protect

(function(P) {
    // Queue manager methods
    const queueManager = {
        // Check queue status
        checkQueueStatus: function(callback) {
            // If we have active operations, we don't need to check the queue status
            // since we're using SSE now for updates
            if (P.config.hasActiveOperations) {
                if (typeof callback === 'function') {
                    callback({
                        success: true,
                        data: []
                    });
                }
                return;
            }
            
            // Only make the API request if we don't have active operations
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue',
                method: 'GET',
                timeout: 5000,
                dataType: 'json',
                success: function(response) {
                    
                    // Check for completed operations that affect the protected files list
                    if (response && response.success && response.data) {
                        queueManager.checkCompletedOperations(response.data);
                    }
                    
                    if (typeof callback === 'function') {
                        callback(response);
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Queue status request failed:', { error });
                    if (typeof callback === 'function') {
                        callback(null);
                    }
                }
            });
        },
        
        // Check for completed operations
        checkCompletedOperations: function(operations) {
            if (!operations || !operations.length) return;
            
            operations.forEach(function(op) {
                // Skip if not a relevant operation type
                if (!P.protectedListOperations.includes(op.operation_type)) return;
                
                // Get the operation ID
                const opId = op.id;
                
                // Check if we've seen this operation before
                const lastStatus = P.config.lastOperationStatus[opId];
                
                // If operation is now completed or failed but was previously processing or pending
                if ((op.status === 'completed' || op.status === 'failed' || op.status === 'skipped') &&
                    lastStatus && (lastStatus === 'processing' || lastStatus === 'pending')) {
                    // P.logger.info('Operation completed:', { op });
                    
                    // Trigger operation completed event
                    P.events.trigger('operation.completed', {
                        id: opId,
                        type: op.operation_type,
                        status: op.status,
                        result: op.result
                    });
                }
                
                // Update last known status
                P.config.lastOperationStatus[opId] = op.status;
                
                // Clean up completed operations older than 5 minutes
                if (op.status === 'completed' || op.status === 'failed' || op.status === 'cancelled' || op.status === 'skipped') {
                    const completedTime = new Date(op.completed_at).getTime();
                    const now = Date.now();
                    
                    // If completed more than 5 minutes ago, remove from tracking
                    if (now - completedTime > 5 * 60 * 1000) {
                        delete P.config.lastOperationStatus[opId];
                    }
                }
            });
        },
        
        // Add operation to queue
        addToQueue: function(operationType, parameters, successCallback, errorCallback) {
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue',
                method: 'POST',
                data: {
                    operation_type: operationType,
                    parameters: JSON.stringify(parameters)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Immediately show the operation in the active operations area
                        queueManager.showOperationImmediately(operationType, parameters, response.operation_id);
                        
                        if (typeof successCallback === 'function') {
                            successCallback(response);
                        }
                    } else {
                        if (typeof errorCallback === 'function') {
                            errorCallback(response.error || 'Failed to add to queue');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Queue add request failed:', { error });
                    if (typeof errorCallback === 'function') {
                        errorCallback('Failed to add to queue: ' + error);
                    }
                }
            });
        },

        // Check if there are any active operations
        hasActiveOperations: function() {
            // Check if there are active operations in P.config
            if (P.config && P.config.hasActiveOperations !== undefined) {
                return P.config.hasActiveOperations;
            }
            
            // Check if there are any operations in the queue with status 'processing' or 'pending'
            if (P.config && P.config.queueStatus && P.config.queueStatus.queue) {
                return P.config.queueStatus.queue.some(function(op) {
                    return op.status === 'processing' || op.status === 'pending' || op.status === 'skipped';
                });
            }
            
            return false;
        },
        
        // Show operation immediately in the active operations area
        showOperationImmediately: function(operationType, parameters, operationId) {
            
            const $operationsContainer = $('#active-operations');
            if (!$operationsContainer.length) {
                P.logger.error('Operations container not found');
                return;
            }
            
            // Get path from parameters
            let path = 'Unknown path';
            if (parameters && parameters.path) {
                path = parameters.path;
                
                // Store the path in the operation data in P.config.lastOperationStatus
                // This ensures it's available when the operation completes
                if (operationId) {
                    // Create a temporary operation object to store in lastOperationStatus
                    P.config.lastOperationStatus[operationId] = {
                        status: 'pending',
                        path: path
                    };
                }
            }
            
            // Capitalize first letter of operation type
            const opTypeDisplay = operationType.charAt(0).toUpperCase() + operationType.slice(1);
            
            // Check if there are any operations currently processing
            const hasProcessingOperations = $operationsContainer.find('.operation-item').toArray()
                .some(item => $(item).find('div:contains("Progress")').text().includes('Processing'));
            
            // Set initial status based on whether there are already processing operations
            const initialStatus = hasProcessingOperations ? 'Waiting' : 'Processing';
            
            // Create operation item HTML - using the new format
            const html = `
                <div class="operation-item" id="operation-${operationId}">
                    <div><strong>Task:</strong> ${opTypeDisplay}</div>
                    <div><strong>Path:</strong> ${path}</div>
                    <div><strong>Progress:</strong> ${initialStatus}</div>
                    <button onclick="Par2Protect.dashboard.cancelOperation('${operationId}')">Cancel</button>
                </div>
            `;
            
            if ($operationsContainer.find('.notice').length > 0) {
                // Replace the notice with our operation
                $operationsContainer.html(html);
            } else {
                // Add our operation to the top
                $operationsContainer.prepend(html);
            }
            
            // Set the active operations flag to ensure faster refresh rate
            P.config.hasActiveOperations = true;
            // No need to start status updates since we're using SSE now
        },
        // Get queue item status
        getQueueItemStatus: function(operationId, callback) {
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue&id=' + operationId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (typeof callback === 'function') {
                        callback(response);
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Queue item status request failed:', { error });
                    if (typeof callback === 'function') {
                        callback(null);
                    }
                }
            });
        },
        
        // Update operations display with queue information
        updateOperationsDisplay: function(activeOperations, queueResponse) {
            
            // Extract skipped operations from the queue response
            let skippedOperations = [];
            if (queueResponse && queueResponse.success && queueResponse.data) {
                skippedOperations = queueResponse.data.filter(op => op.status === 'skipped');
            }
            
            // Add skipped operations to active operations
            if (skippedOperations.length > 0) {
                activeOperations = activeOperations || [];
                activeOperations = [...activeOperations, ...skippedOperations];
            }
            
            if (!P.dashboard) {
                P.logger.error('Dashboard not initialized');
                return;
            }
            
            const $operationsContainer = $('#active-operations');
            if (!$operationsContainer.length) {
                P.logger.error('Operations container not found');
                return;
            }
            
            // Clean up expired recently completed operations
            const now = new Date().getTime();
            P.config.recentlyCompletedOperations = P.config.recentlyCompletedOperations.filter(op =>
                op.displayUntil > now
            );
            
            // First check if there are active operations
            if (activeOperations && activeOperations.length > 0) {
                // No need to start status updates since we're using SSE now
                
                let html = '';
                // Use a Set to track unique operations by ID or path to avoid duplicates
                const processedOps = new Set();
                
                activeOperations.forEach(function(op) {
                    // Create a unique key for this operation
                    const opKey = op.id ||
                                 (op.operation_type + ':' + (op.parameters?.path || op.path || 'unknown'));
                    
                    // Skip if we've already processed this operation
                    if (processedOps.has(opKey)) return;
                    processedOps.add(opKey);
                    
                    // Get path from operation directly or from parameters
                    let path = op.path || 'Unknown path';
                    if (!path && op.parameters) {
                        try {
                            const params = typeof op.parameters === 'string' ?
                                          JSON.parse(op.parameters) : op.parameters;
                            path = params.path || path;
                            
                            // Store the extracted path directly on the operation object
                            // This ensures it's available even after the operation completes
                            op.path = path;
                            
                            // Also store in lastOperationStatus to ensure it's preserved
                            if (op.id) {
                                if (!P.config.lastOperationStatus[op.id] ||
                                    typeof P.config.lastOperationStatus[op.id] !== 'object') {
                                    P.config.lastOperationStatus[op.id] = {
                                        status: op.status || 'processing'
                                    };
                                }
                                P.config.lastOperationStatus[op.id].path = path;
                            }
                        } catch (e) {
                            P.logger.error('Error parsing parameters:', { error: e });
                        }
                    }
                    
                    // Determine progress display based on status and set appropriate status class
                    let progressDisplay = 'Processing';
                    let statusClass = '';
                    
                    if (op.progress !== null && op.progress !== undefined) {
                        progressDisplay = `Processing (${op.progress}%)`;
                    } else if (op.status === 'completed' || op.status === 'Completed') {
                        progressDisplay = 'Processed';
                        statusClass = 'success';
                    } else if (op.status === 'failed' || op.status === 'Failed') {
                        progressDisplay = 'Failed';
                        statusClass = 'error';
                    } else if (op.status === 'cancelled' || op.status === 'Cancelled') {
                        progressDisplay = 'Cancelled';
                        statusClass = 'warning';
                        } else if (op.status === 'skipped' || op.status === 'Skipped') {
                            progressDisplay = 'Skipped';
                            statusClass = 'warning';
                    } else if (op.status === 'pending' || op.status === 'Pending') {
                        progressDisplay = 'Waiting';
                    }
                    
                    // Normalize status display (capitalize first letter)
                    let statusDisplay = op.status || 'Processing';
                    statusDisplay = statusDisplay.charAt(0).toUpperCase() + statusDisplay.slice(1);
                    // Capitalize first letter of operation type
                    const opType = op.operation_type || op.type || 'Task';
                    const opTypeDisplay = opType.charAt(0).toUpperCase() + opType.slice(1);
                    
                    // Check if operation has been running for over 5 minutes
                    let isStuck = false;
                    let buttonHtml = '';
                    
                    if (op.status === 'processing' || op.status === 'pending') {
                        // Add cancel button for all running operations
                        buttonHtml = `<button onclick="Par2Protect.dashboard.cancelOperation('${op.id || op.pid}')">Cancel</button>`;
                        
                        // Check if operation has been running for over 5 minutes
                        if (op.created_at || op.started_at) {
                            const startTime = new Date(op.created_at || op.started_at).getTime();
                            const now = new Date().getTime();
                            const runningTimeMinutes = (now - startTime) / (1000 * 60);
                            
                            // If running for more than 5 minutes, add Kill button
                            if (op.status === 'processing' && runningTimeMinutes > 5) {
                                isStuck = true;
                                buttonHtml += ` <button class="btn-warning" onclick="Par2Protect.killStuckOperation('${op.id || op.pid}')">Kill stuck operation</button>`;
                            }
                        }
                    }
                    
                    html += `
                        <div class="operation-item ${statusClass}">
                            <div><strong>Task:</strong> ${opTypeDisplay}</div>
                            <div><strong>Path:</strong> ${path}</div>
                            <div><strong>Progress:</strong> ${progressDisplay}</div>
                            ${buttonHtml}
                        </div>
                    `;
                });
                $operationsContainer.html(html);
            }
            // Then check if there are queued operations
            else if (queueResponse && queueResponse.success && queueResponse.data && queueResponse.data.length > 0) {
                let html = '';
                let hasActiveQueue = false;
                
                queueResponse.data.forEach(function(op) {
                    if (op.status === 'pending' || op.status === 'processing' || op.status === 'skipped') {
                        hasActiveQueue = true;
                        let path = op.path || 'Unknown path';
                        if (!path && op.parameters) {
                            try {
                                const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters;
                                path = params.path || path;
                                
                                // Store the extracted path directly on the operation object
                                // This ensures it's available even after the operation completes
                                op.path = path;
                                
                                // Also store in lastOperationStatus to ensure it's preserved
                                if (op.id) {
                                    if (!P.config.lastOperationStatus[op.id] ||
                                        typeof P.config.lastOperationStatus[op.id] !== 'object') {
                                        P.config.lastOperationStatus[op.id] = {
                                            status: op.status || 'pending'
                                        };
                                    }
                                    P.config.lastOperationStatus[op.id].path = path;
                                }
                            } catch (e) {
                                P.logger.error('Error parsing parameters:', { error: e });
                            }
                        }
                        
                        // Determine progress display based on status
                        let progressDisplay = 'Processing';
                        let statusClass = '';
                        
                        if (op.progress !== null && op.progress !== undefined) {
                            progressDisplay = `Processing (${op.progress}%)`;
                        } else if (op.status === 'completed' || op.status === 'Completed') {
                            progressDisplay = 'Processed';
                            statusClass = 'success';
                        } else if (op.status === 'failed' || op.status === 'Failed') {
                            progressDisplay = 'Failed';
                            statusClass = 'error';
                        } else if (op.status === 'cancelled' || op.status === 'Cancelled') {
                            progressDisplay = 'Cancelled';
                            statusClass = 'warning';
                        } else if (op.status === 'skipped' || op.status === 'Skipped') {
                            progressDisplay = 'Skipped';
                            statusClass = 'warning';
                        } else if (op.status === 'pending' || op.status === 'Pending') {
                            progressDisplay = 'Waiting';
                        }
                        
                        // Capitalize first letter of operation type
                        const opType = op.operation_type || 'Task';
                        const opTypeDisplay = opType.charAt(0).toUpperCase() + opType.slice(1);
                        
                        // Check if operation has been running for over 5 minutes
                        let isStuck = false;
                        let buttonHtml = '';
                        
                        if (op.status === 'processing' || op.status === 'pending') {
                            // Add cancel button for all running operations
                            buttonHtml = op.id ? `<button onclick="Par2Protect.dashboard.cancelOperation('${op.id}')">Cancel</button>` : '';
                            
                            // Check if operation has been running for over 5 minutes
                            if (op.created_at || op.started_at) {
                                const startTime = new Date(op.created_at || op.started_at).getTime();
                                const now = new Date().getTime();
                                const runningTimeMinutes = (now - startTime) / (1000 * 60);
                                
                                // If running for more than 5 minutes, add Kill button
                                if (op.status === 'processing' && runningTimeMinutes > 5) {
                                    isStuck = true;
                                    buttonHtml += ` <button class="btn-warning" onclick="Par2Protect.killStuckOperation('${op.id}')">Kill stuck operation</button>`;
                                }
                            }
                        }
                        
                        html += `
                            <div class="operation-item ${statusClass}">
                                <div><strong>Task:</strong> ${opTypeDisplay}</div>
                                <div><strong>Path:</strong> ${path}</div>
                                <div><strong>Progress:</strong> ${progressDisplay}</div>
                                ${buttonHtml}
                            </div>
                        `;
                    }
                });
                
                if (hasActiveQueue) {
                    $operationsContainer.html(html);
                    // No need to start status updates since we're using SSE now
                } else {
                    // Check for recently completed operations
                    if (P.config.recentlyCompletedOperations.length > 0) {
                        let html = '';
                        P.config.recentlyCompletedOperations.forEach(function(op) {
                            // Use the directly stored path if available, otherwise try to extract it from parameters
                            let path = op.path || 'Unknown path';
                            if (!path && op.parameters) {
                                try {
                                    const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters;
                                    path = params.path || path;
                                } catch (e) {
                                    P.logger.error('Error parsing parameters:', { error: e });
                                }
                            }
                            
                            // Determine progress display based on operation status
                            let progressDisplay = 'Processed';
                            let statusClass = 'success';
                            
                            if (op.status === 'failed' || op.status === 'Failed') {
                                progressDisplay = 'Failed';
                                statusClass = 'error';
                            } else if (op.status === 'cancelled' || op.status === 'Cancelled') {
                                progressDisplay = 'Cancelled';
                                statusClass = 'warning';
                            } else if (op.status === 'skipped' || op.status === 'Skipped') {
                                progressDisplay = 'Skipped';
                                statusClass = 'warning';
                            }
                            
                            // Capitalize first letter of operation type
                            const opType = op.operation_type || 'Task';
                            const opTypeDisplay = opType.charAt(0).toUpperCase() + opType.slice(1);
                            
                            html += `
                                <div class="operation-item ${statusClass}">
                                    <div><strong>Task:</strong> ${opTypeDisplay}</div>
                                    <div><strong>Path:</strong> ${path}</div>
                                    <div><strong>Progress:</strong> ${progressDisplay}</div>
                                    <div><strong>Completed:</strong> ${new Date(op.completedAt).toLocaleTimeString()}</div>
                                </div>
                            `;
                        });
                        $operationsContainer.html(html);
                        
                        // No need to start status updates since we're using SSE now
                    } else {
                        // No active, pending, or recently completed operations
                        $operationsContainer.html('<div class="notice">No active operations</div>');
                    }
                }
            }
            // Check for recently completed operations
            else if (P.config.recentlyCompletedOperations.length > 0) {
                let html = '';
                P.config.recentlyCompletedOperations.forEach(function(op) {
                    // Use the directly stored path if available, otherwise try to extract it from parameters
                    let path = op.path || 'Unknown path';
                    if (!path && op.parameters) {
                        try {
                            const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters;
                            path = params.path || path;
                        } catch (e) {
                            P.logger.error('Error parsing parameters:', { error: e });
                        }
                    }
                    
                    // Determine progress display based on operation status
                    let progressDisplay = 'Processed';
                    let statusClass = 'success';
                    
                    if (op.status === 'failed' || op.status === 'Failed') {
                        progressDisplay = 'Failed';
                        statusClass = 'error';
                    } else if (op.status === 'cancelled' || op.status === 'Cancelled') {
                        progressDisplay = 'Cancelled';
                        statusClass = 'warning';
                    } else if (op.status === 'skipped' || op.status === 'Skipped') {
                        progressDisplay = 'Skipped';
                        statusClass = 'warning';
                    }
                    
                    // Capitalize first letter of operation type
                    const opType = op.operation_type || 'Task';
                    const opTypeDisplay = opType.charAt(0).toUpperCase() + opType.slice(1);
                    
                    html += `
                        <div class="operation-item ${statusClass}">
                            <div><strong>Task:</strong> ${opTypeDisplay}</div>
                            <div><strong>Path:</strong> ${path}</div>
                            <div><strong>Progress:</strong> ${progressDisplay}</div>
                            <div><strong>Completed:</strong> ${new Date(op.completedAt).toLocaleTimeString()}</div>
                        </div>
                    `;
                });
                $operationsContainer.html(html);
                
                // No need to start status updates since we're using SSE now
            }
            // No operations at all
            else {
                $operationsContainer.html('<div class="notice">No active operations</div>');
            }
        },

        // Add SSE methods to queueManager
        initEventSource: function() {
            if (typeof(EventSource) === "undefined") {
                P.logger.error('SSE not supported by browser');
                // Fall back to polling for browsers that don't support SSE
                return;
            }
            
            // Flag to track if the page is being unloaded
            this.isPageUnloading = false;
            
            // Remove any existing unload listeners to avoid duplicates
            window.removeEventListener('beforeunload', this.handleBeforeUnload);
            window.removeEventListener('unload', this.handleUnload);
            window.removeEventListener('pagehide', this.handlePageHide);
            
            // Store reference to this for use in event handlers
            const self = this;
            
            // Define handlers
            this.handleBeforeUnload = function() {
                self.isPageUnloading = true;
                self.closeEventSource();
            };
            
            this.handleUnload = function() {
                self.isPageUnloading = true;
                self.closeEventSource();
            };
            
            this.handlePageHide = function() {
                self.isPageUnloading = true;
                self.closeEventSource();
            };
            
            // Add event listeners for page unload events
            window.addEventListener('beforeunload', this.handleBeforeUnload);
            window.addEventListener('unload', this.handleUnload);
            window.addEventListener('pagehide', this.handlePageHide);
            
            // Close existing connection if any
            this.closeEventSource();
            
            // Add a small delay before creating the EventSource
            // This gives the browser time to fully load the page
            setTimeout(function() {
                // Only create the EventSource if the page is not being unloaded
                if (!self.isPageUnloading) {
                    // Create new EventSource
                    self.eventSource = new EventSource('/plugins/par2protect/api/v1/index.php?endpoint=events');
                    self.setupEventSourceListeners();
                }
            }, 500); // 500ms delay
        },
        
        // Set up event listeners for the EventSource
        setupEventSourceListeners: function() {
            if (!this.eventSource) return;
            
            // Set up event listeners
            this.eventSource.addEventListener('operation.completed', function(e) {
                const data = JSON.parse(e.data);
                // Update UI immediately
                if (P.dashboard) {
                    P.dashboard.updateStatus(false);
                }
                // Trigger operation completed event for other components
                P.events.trigger('operation.completed', data);
            });
            
            // Handle reconnect event from server
            this.eventSource.addEventListener('reconnect', function(e) {
                P.logger.debug('Server requested reconnection');
                queueManager.closeEventSource();
                
                // Reconnect immediately
                setTimeout(function() {
                    if (!queueManager.isPageUnloading) {
                        queueManager.initEventSource();
                    }
                }, 1000); // Use 1 second delay as suggested by server
            });
            
            // Set up error handler
            this.eventSource.onerror = function(event) {
                // Don't log errors if the page is being unloaded
                if (queueManager.isPageUnloading) {
                    return;
                }
                
                // Check if the error is due to the page being closed/refreshed
                if (event && event.target && event.target.readyState === EventSource.CLOSED) {
                    P.logger.debug('EventSource connection closed');
                } else {
                    P.logger.error('EventSource error');
                }
                
                queueManager.closeEventSource();
                
                // Try to reconnect after a delay
                setTimeout(function() {
                    // Only reconnect if the page is not being unloaded
                    if (!queueManager.isPageUnloading) {
                        queueManager.initEventSource();
                    }
                }, 3000); // Reduced from 5000ms to 3000ms for faster recovery
            };
            // P.logger.info('EventSource initialized');
        },
        closeEventSource: function() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
                
                // Only log if the page is not being unloaded
                if (!this.isPageUnloading) {
                    P.logger.debug('EventSource closed');
                }
            }
        }
    };
    
    // Add queue manager to Par2Protect
    P.queueManager = queueManager;
    
})(window.Par2Protect);