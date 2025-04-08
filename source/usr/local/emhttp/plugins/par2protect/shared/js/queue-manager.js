// Queue Manager for Par2Protect

(function(P) {
    // Ensure P.config exists and initialize necessary properties
    P.config = P.config || {};
    P.config.lastOperationStatus = P.config.lastOperationStatus || {};
    P.config.recentlyCompletedOperations = P.config.recentlyCompletedOperations || [];
    P.config.hasActiveOperations = P.config.hasActiveOperations || false;
    P.protectedListOperations = P.protectedListOperations || ['protect', 'verify', 'repair', 'remove']; // Operations that might require list refresh

    // Queue manager methods
    const queueManager = {
        // Removed cleanupTimerId property

        // Check queue status (Polling fallback - less used with SSE)
        checkQueueStatus: function(callback) {
            if (P.config.hasActiveOperations) { // If SSE is likely active, don't poll
                if (typeof callback === 'function') { callback({ success: true, data: [] }); }
                return;
            }
            // Fallback AJAX poll if SSE might be inactive
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue',
                method: 'GET',
                timeout: 15000,
                dataType: 'json',
                success: function(response) {
                    if (response && response.success && response.data) {
                        queueManager.checkCompletedOperations(response.data); // Check for completions missed by SSE
                    }
                    if (typeof callback === 'function') { callback(response); }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Queue status poll request failed:', { error });
                    if (typeof callback === 'function') { callback(null); }
                }
            });
        },

        // Check for completed operations (used by polling fallback)
        checkCompletedOperations: function(operations) {
            if (!operations || !operations.length) return;
            operations.forEach(function(op) {
                if (!P.protectedListOperations.includes(op.operation_type)) return;
                const opId = op.id;
                if (!opId) return;
                const lastStatusData = P.config.lastOperationStatus[opId];
                const lastStatus = lastStatusData ? (typeof lastStatusData === 'string' ? lastStatusData : lastStatusData.status) : null;

                if (['completed', 'failed', 'skipped'].includes(op.status) && lastStatus && ['processing', 'pending'].includes(lastStatus)) {
                    P.events.trigger('operation.completed', {
                        id: opId, type: op.operation_type, status: op.status,
                        result: op.result, _source: 'checkCompletedOperations'
                    });
                }
                // Update status (store path if available)
                let path = null;
                if (op.parameters) { try { const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters; path = params.path || null; } catch (e) {} }
                P.config.lastOperationStatus[opId] = { status: op.status, path: path };
                // Cleanup old status tracking
                if (['completed', 'failed', 'cancelled', 'skipped'].includes(op.status)) {
                    const completedTime = op.completed_at ? new Date(op.completed_at * 1000).getTime() : 0;
                    if (completedTime && (Date.now() - completedTime > 300000)) { // 5 mins
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
                data: { operation_type: operationType, parameters: JSON.stringify(parameters) },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        queueManager.showOperationImmediately(operationType, parameters, response.operation_id);
                        if (typeof successCallback === 'function') { successCallback(response); }
                    } else {
                        if (typeof errorCallback === 'function') { errorCallback(response.error || 'Failed to add to queue'); }
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Queue add request failed:', { error });
                    if (typeof errorCallback === 'function') { errorCallback('Failed to add to queue: ' + error); }
                }
            });
        },

        // Show operation immediately in the active operations area
        showOperationImmediately: function(operationType, parameters, operationId) {
            const $operationsContainer = $('#active-operations');
            if (!$operationsContainer.length) { P.logger.error('Operations container not found'); return; }
            let path = 'Unknown path';
            if (parameters && parameters.path) {
                path = parameters.path;
                if (operationId) { P.config.lastOperationStatus[operationId] = { status: 'pending', path: path }; }
            }
            const opTypeDisplay = operationType.charAt(0).toUpperCase() + operationType.slice(1);
            const hasProcessingOps = $operationsContainer.find('.operation-item:contains("Processing")').length > 0;
            const initialStatus = hasProcessingOps ? 'Waiting' : 'Processing';
            const html = `
                <div class="operation-item" id="operation-${operationId}">
                    <div><strong>Task:</strong> ${opTypeDisplay}</div>
                    <div><strong>Path:</strong> ${P.escapeHtml(path)}</div>
                    <div><strong>Progress:</strong> ${initialStatus}</div>
                    <button onclick="Par2Protect.dashboard.cancelOperation('${operationId}')">Cancel</button>
                </div>`;
            if ($operationsContainer.find('.notice').length > 0) { $operationsContainer.html(html); }
            else { $operationsContainer.prepend(html); }
            P.config.hasActiveOperations = true;
        },

        // Update operations display (called by dashboard.js and SSE handlers)
        updateOperationsDisplay: function(activeOperations, queueResponse) {
            const $operationsContainer = $('#active-operations');
            if (!$operationsContainer.length) { return; }

            // Clean up expired recently completed operations from the internal array
            const now = Date.now();
            P.config.recentlyCompletedOperations = P.config.recentlyCompletedOperations.filter(op => op.displayUntil > now);

            let html = '';
            const processedOps = new Set();
            const recentOpsMap = new Map(P.config.recentlyCompletedOperations.map(op => [op.id || op.operation_id, op]));

            // Start with active operations
            const combinedOpsToDisplay = [...(activeOperations || [])];

            // Add recent ops only if they are not already represented in activeOperations (based on ID)
            // This prevents duplicates while allowing recent status to override later
            P.config.recentlyCompletedOperations.forEach(recentOp => {
                const recentOpId = recentOp.id || recentOp.operation_id;
                if (!combinedOpsToDisplay.some(activeOp => (activeOp.id || activeOp.operation_id) === recentOpId)) {
                    combinedOpsToDisplay.push(recentOp);
                }
            });

            if (combinedOpsToDisplay.length > 0) {
                combinedOpsToDisplay.forEach(function(op) {
                    const opId = op.id || op.operation_id;
                    if (!opId || processedOps.has(opId)) return;
                    processedOps.add(opId);

                    let path = op.path || 'Unknown path';
                    if (path === 'Unknown path' && op.parameters) { try { const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters; path = params.path || path; } catch (e) {} }
                    if (path === 'Unknown path' && P.config.lastOperationStatus[opId] && P.config.lastOperationStatus[opId].path) { path = P.config.lastOperationStatus[opId].path; }

                    let progressDisplay = 'Unknown'; let statusClass = '';
                    // Prioritize status from the recently completed map if available for this opId
                    const recentOpData = recentOpsMap.get(opId);
                    const currentStatus = recentOpData ? recentOpData.status.toLowerCase() : (op.status ? op.status.toLowerCase() : 'unknown');

                    if (currentStatus === 'processing') { progressDisplay = op.progress !== null && op.progress !== undefined ? `Processing (${op.progress}%)` : 'Processing'; }
                    else if (currentStatus === 'completed') { progressDisplay = 'Processed'; statusClass = 'success'; }
                    else if (currentStatus === 'failed') { progressDisplay = 'Failed'; statusClass = 'error'; }
                    else if (currentStatus === 'cancelled') { progressDisplay = 'Cancelled'; statusClass = 'warning'; }
                    else if (currentStatus === 'skipped') { progressDisplay = 'Skipped'; statusClass = 'warning'; }
                    else if (currentStatus === 'pending') { progressDisplay = 'Waiting'; }

                    const opType = op.operation_type || op.type || 'Task';
                    const opTypeDisplay = opType.charAt(0).toUpperCase() + opType.slice(1);
                    let buttonHtml = '';
                    // Show cancel button only for pending/processing states
                    if (currentStatus === 'processing' || currentStatus === 'pending') { buttonHtml = `<button onclick="Par2Protect.dashboard.cancelOperation('${opId}')">Cancel</button>`; }

                    html += `
                        <div class="operation-item ${statusClass}" id="operation-${opId}">
                            <div><strong>Task:</strong> ${opTypeDisplay}</div>
                            <div><strong>Path:</strong> ${P.escapeHtml(path)}</div>
                            <div><strong>Progress:</strong> ${progressDisplay}</div>
                            ${buttonHtml}
                        </div>`;
                });
                $operationsContainer.html(html);
            } else {
                $operationsContainer.html('<div class="notice">No active operations</div>');
            }
            // Update global flag based ONLY on truly active operations from the input list
            P.config.hasActiveOperations = (activeOperations || []).some(op => ['processing', 'pending'].includes(op.status));
        },

        // --- SSE Handling ---
        eventSource: null,
        eventSourceRetries: 0,
        maxEventSourceRetries: 5,
        eventSourceRetryDelay: 5000,
        lastEventTimestamp: null,
        sseHealthCheckTimer: null,

        initEventSource: function() {
            P.logger.debug("queue-manager.js: initEventSource - Initializing SSE connection");
            if (this.eventSource && this.eventSource.readyState !== EventSource.CLOSED) { 
                P.logger.debug('SSE connection already open.'); return; 
            }
            try {
                this.eventSource = new EventSource('/plugins/par2protect/api/v1/index.php?endpoint=events');
                this.lastEventTimestamp = Date.now();
                this.setupEventSourceListeners();
                this.startSSEHealthCheck();
                this.eventSourceRetries = 0;
                P.logger.debug('SSE connection established.');
            } catch (e) { 
                P.logger.error('Failed to create EventSource:', { error: e }); this.handleSSEError(); 
            }
        },

        setupEventSourceListeners: function() {
            P.logger.debug("queue-manager.js: setupEventSourceListeners - Setting up SSE listeners");
            if (!this.eventSource) return;
            this.eventSource.onopen = function() { 
                P.logger.debug('SSE connection opened.'); this.lastEventTimestamp = Date.now(); this.eventSourceRetries = 0; 
            }.bind(this);
            this.eventSource.onerror = function(e) { 
                P.logger.error('SSE connection error:', { error: e }); this.handleSSEError(); 
            }.bind(this);
            this.eventSource.addEventListener('keepalive', this.handleKeepAlive.bind(this)); // Added specific keepalive handler
            this.eventSource.addEventListener('queue.update', this.handleQueueUpdate.bind(this));
            this.eventSource.addEventListener('operation.progress', this.handleOperationProgress.bind(this));
            this.eventSource.addEventListener('operation.completed', this.handleOperationCompleted.bind(this));
            this.eventSource.addEventListener('reconnect', this.handleReconnect.bind(this)); // Add listener for server-sent reconnect
        },

        handleKeepAlive: function(event) {
            this.lastEventTimestamp = Date.now();
            // P.logger.debug('SSE keepalive event received'); // Optional: uncomment for debugging
        },

        handleQueueUpdate: function(event) {
            this.lastEventTimestamp = Date.now();
            P.logger.debug('Received queue.update event from SSE');
            try {
                const data = JSON.parse(event.data);
                P.config.queueStatus = data;
                if (P.dashboard && P.dashboard.updateOperationsDisplay) {
                    const activeOps = data.queue ? data.queue.filter(op => ['processing', 'pending', 'skipped'].includes(op.status)) : [];
                    P.logger.debug('Calling updateOperationsDisplay from handleQueueUpdate', { activeOpsCount: activeOps.length });
                    P.dashboard.updateOperationsDisplay(activeOps, { success: true, data: data.queue });
                }
            } catch (e) { P.logger.error('Error parsing queue.update SSE data:', { error: e, data: event.data }); }
        },

        handleOperationProgress: function(event) {
            this.lastEventTimestamp = Date.now();
            try {
                const data = JSON.parse(event.data);
                const opId = data.id;
                const progress = data.progress;
                const $opItem = $(`#operation-${opId}`);
                if ($opItem.length) { $opItem.find('div:contains("Progress")').text(`Processing (${progress}%)`); }
            } catch (e) { P.logger.error('Error parsing operation.progress SSE data:', { error: e, data: event.data }); }
        },

        handleOperationCompleted: function(event) {
            this.lastEventTimestamp = Date.now();
            P.logger.debug('Received operation.completed event from SSE', { data: event.data });
            try {
                const data = JSON.parse(event.data);
                const opId = data.id;
                if (!opId) { P.logger.warning('operation.completed event missing ID', { data }); return; }

                let path = 'Unknown path';
                if (P.config.lastOperationStatus[opId] && P.config.lastOperationStatus[opId].path) { path = P.config.lastOperationStatus[opId].path; }
                else if (data.path) { path = data.path; }

                // Only add to recently completed if it actually finished recently
                // Use completed_at from data if available (it's a Unix timestamp from backend)
                const completedTimestamp = data.completed_at ? (data.completed_at * 1000) : Date.now(); // Convert s to ms
                const displayUntil = completedTimestamp + 30000; // 30 seconds after completion time

                let addedToRecent = false;
                if (displayUntil > Date.now()) {
                    P.logger.debug(`Adding op ${opId} to recentlyCompletedOperations, display until ${new Date(displayUntil).toISOString()}`);
                    P.config.recentlyCompletedOperations.push({
                        id: opId, operation_type: data.type, status: data.status,
                        result: data.result, path: path, displayUntil: displayUntil
                    });
                    addedToRecent = true;
                } else {
                     P.logger.debug(`Skipping add of op ${opId} to recentlyCompletedOperations, already expired.`);
                }

                // Remove from lastOperationStatus tracking immediately
                delete P.config.lastOperationStatus[opId];

                // Update the display immediately to show the completed status (if added) or remove if expired
                // The display will be updated more accurately by the next queue.update event or poll.
                // if (P.dashboard && P.dashboard.updateOperationsDisplay) {
                //     P.dashboard.updateOperationsDisplay([], null); // Removed this problematic immediate update
                // }
                P.events.trigger('operation.completed', data); // Trigger event for other listeners

                // Set removal timer only if item was actually added for display
                if (addedToRecent) {
                    setTimeout(() => {
                        P.logger.debug(`Running cleanup timer for completed operation ID: ${opId}`);
                        // Remove item from the recently completed array
                        P.config.recentlyCompletedOperations = P.config.recentlyCompletedOperations.filter(op => op.id !== opId);
                        // Remove item from the DOM
                        const $opElement = $(`#operation-${opId}`);
                        if ($opElement.length) { $opElement.remove(); P.logger.debug(`Removed DOM element for operation ID: ${opId}`); }
                        // Check if the active operations container is now empty
                        const $operationsContainer = $('#active-operations');
                        if ($operationsContainer.length && $operationsContainer.find('.operation-item').length === 0) {
                            P.logger.debug('Active operations list is empty after cleanup, showing notice.');
                            $operationsContainer.html('<div class="notice">No active operations</div>');
                            P.config.hasActiveOperations = false;
                        }
                    }, 31000); // 31 seconds
                }

            } catch (e) { P.logger.error('Error parsing operation.completed SSE data:', { error: e, data: event.data }); }
        },
handleReconnect: function(event) {
    P.logger.debug('Received reconnect event from server. Re-establishing connection.');
    this.closeEventSource(); // Close the old connection cleanly
    // Use a minimal delay to allow the browser to fully close the old connection before opening a new one
    setTimeout(() => {
        this.initEventSource(); // Start a new connection immediately
    }, 100);
}, // Add comma here

handleSSEError: function() {
            this.closeEventSource();
            if (this.eventSourceRetries < this.maxEventSourceRetries) {
                this.eventSourceRetries++;
                P.logger.warning(`SSE connection failed. Retrying in ${this.eventSourceRetryDelay / 1000}s... (Attempt ${this.eventSourceRetries}/${this.maxEventSourceRetries})`);
                setTimeout(() => { this.initEventSource(); }, this.eventSourceRetryDelay);
            } else { P.logger.error(`SSE connection failed after ${this.maxEventSourceRetries} retries. Stopping attempts.`); }
        },

        startSSEHealthCheck: function() {
            if (this.sseHealthCheckTimer) { clearInterval(this.sseHealthCheckTimer); }
            this.sseHealthCheckTimer = setInterval(() => {
                if (this.lastEventTimestamp && (Date.now() - this.lastEventTimestamp > 65000)) {
                    P.logger.info('SSE connection health check failed - no recent events. Reconnecting.');
                    this.handleSSEError();
                }
            }, 30000);
        },

        closeEventSource: function() {
            if (this.eventSource) { this.eventSource.close(); this.eventSource = null; P.logger.debug('SSE connection closed'); }
            if (this.sseHealthCheckTimer) { clearInterval(this.sseHealthCheckTimer); this.sseHealthCheckTimer = null; }
        }
    };

    // Expose queue manager
    P.queueManager = queueManager;

    // Initialize SSE on page load if the dashboard object exists
    $(document).ready(function() {
        if (P.dashboard) { // Check if dashboard context exists
            P.queueManager.initEventSource();
        }
    });

    // Add listener to clean up SSE connection on page unload
    window.addEventListener('beforeunload', function() {
        P.logger.debug('Page unloading, closing SSE connection.');
        P.queueManager.closeEventSource();
    });

})(window.Par2Protect = window.Par2Protect || {});