// Dashboard Functionality

(function(P) {
    // Dashboard methods
    const dashboard = {
        // Store folder settings
        folderSettings: {},
        
            // Function to adjust content height based on state
            adjustContentHeight: function(state) {
                const $content = $('.content');
                const viewportHeight = window.innerHeight;
                const baseDialogHeight = 818.4; // Fixed height as suggested by user
                const filetreeAddition = 300; // Additional height for filetree (max height of filetree-picker)
                const itemsAddition = 30; // Additional height per item
                const listWithItemsAddition = 150; // Fixed addition when list has items (increased to account for max height of protectedPaths-list)
                const advancedAddition = 200; // Additional height for advanced settings (increased by 50px)
                const combinedAddition = 50; // Extra height when both filetree and advanced settings are open
                
                // Check if advanced settings are visible
                const advancedVisible = $('#advanced-settings-panel').is(':visible');
                
                // Get number of selected items
                const itemCount = $('#selected-folders-body tr').not('.empty-row').length;
                
                // Check if protectedPaths-list has items
                const hasProtectedPaths = $('#protectedPaths-list .path-item').length > 0;
                
                // Log for debugging
                console.log('Adjusting height for state:', state, 'itemCount:', itemCount, 'advancedVisible:', advancedVisible, 'hasProtectedPaths:', hasProtectedPaths);
                
                switch(state) {
                    case 'normal':
                        // Reset to default height
                        $content.css('min-height', '');
                        break;
                    case 'dialog':
                        // Set height for dialog open with adjustments for items and advanced settings
                        let height = baseDialogHeight;
                        
                        // Add height for selected items or protected paths
                        if (itemCount > 0 || hasProtectedPaths) {
                            height += listWithItemsAddition;
                        }
                        
                        // Add height for advanced settings
                        if (advancedVisible) {
                            height += advancedAddition;
                        }
                        
                        $content.css('min-height', height + 'px');
                        break;
                    case 'filetree':
                        // Set height for filetree open with adjustments for items and advanced settings
                        let ftHeight = baseDialogHeight + filetreeAddition;
                        
                        // Add height for selected items or protected paths
                        if (itemCount > 0 || hasProtectedPaths) {
                            ftHeight += listWithItemsAddition;
                        }
                        
                        // Add height for advanced settings
                        if (advancedVisible) {
                            ftHeight += advancedAddition;
                            
                            // Add extra height when both filetree and advanced settings are open
                            ftHeight += combinedAddition;
                        }
                        
                        $content.css('min-height', ftHeight + 'px');
                        break;
                }
            },
            
        // Initialize dashboard
        initDashboard: function() {
            if (P.config.isInitialized) {
                return;
            }

            // P.logger.info('Initializing dashboard...');
            
            try {
                // Initialize the SSE connection
                if (P.queueManager && typeof P.queueManager.initEventSource === 'function') {
                    // P.logger.info('Initializing EventSource for real-time updates');
                    P.queueManager.initEventSource();
                } else {
                    P.logger.error('Queue manager or initEventSource function not available');
                }
                
                // Initial status check - show loading for initial load
                this.updateStatus(true);
                
                // Setup event listeners
                this.setupEventListeners();
                
                // We'll only start status updates if needed based on the initial status check
                // The updateStatus function will trigger startStatusUpdates if active operations are found
                
                P.config.isInitialized = true;
                // P.logger.info('Dashboard initialization complete');
            } catch (e) {
                P.logger.error('Failed to initialize dashboard:', { error: e });
                throw e;
            }
        },

        // Start status updates
        startStatusUpdates: function() {
            // This function is now only used for initial status check
            // and for manual refreshes, not for polling
            
            // First, ensure any existing timer is cleared
            this.stopStatusUpdates();
            
            // Only update once
            if (!P.config.isLoading) {
                this.updateStatus(false);
            }
        },
        
        // Check if updates should be running
        shouldRunUpdates: function() {
            // This function is kept for compatibility with existing code
            // but is no longer needed since we're using SSE now instead of polling
            return false;
        },

        // Stop status updates
        stopStatusUpdates: function() {
            // This function is kept for compatibility with existing code
            // but doesn't do anything since we're using SSE now instead of polling
            P.config.statusCheckTimer = null;
        },

        // Update status information
        updateStatus: function(showLoading = false) {
            if (P.config.isLoading) {
                return;
            }
            
            // Only show loading overlay for manual updates, not automatic ones
            if (showLoading) {
                P.setLoading(true);
            }
            
            // Track request start time for performance monitoring
            const requestStartTime = performance.now();
            
            // Get queue status first
            this.getQueueStatus(function(queueResponse) {
                // Then get system status
                dashboard.getSystemStatus(queueResponse, showLoading);
            });
        },
        
        // Get queue status
        getQueueStatus: function(callback) {
            // Use queue manager to get queue status
            P.queueManager.checkQueueStatus(function(response) {
                // Simply pass the response to the callback
                // No need to start polling updates since we're using SSE now
                callback(response);
            });
        },
        
        // Get system status
        getSystemStatus: function(queueResponse, showLoading = false) {
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=status',
                method: 'GET',
                timeout: 5000,
                dataType: 'json',
                success: function(response) {
                    
                    // Check if the response has the refresh_list flag
                    if (response && response.success && P.handleDirectOperationResponse) {
                        P.handleDirectOperationResponse(response);
                    }
                    
                    if (!response) {
                        P.logger.error('Empty response from status.php');
                        $('#error-display').text('Error: Empty response from server').show();
                        if (showLoading) P.setLoading(false);
                        return;
                    }

                    if (response.success && response.data) {
                        // Update protection stats
                        if (response.data.stats) {
                            const stats = response.data.stats;
                            $('#protected-files').text(stats.total_files || '0');
                            $('#protected-size').text(stats.total_size || '0 B');
                            $('#last-verification').text(stats.last_verification || 'Never');
                            
                            const health = stats.health || 'unknown';
                            $('#protection-health .health-indicator')
                                .text(health.charAt(0).toUpperCase() + health.slice(1))
                                .removeClass()
                                .addClass('health-indicator ' + health);
                        }

                        // Update activity log
                        if (response.data.recent_activity) {
                            dashboard.updateActivityLog(response.data.recent_activity);
                        }
                        
                        // Update active operations
                        dashboard.updateOperationsDisplay(response.data.active_operations, queueResponse);
                        
                        // Hide any error messages
                        $('#error-display').hide();
                    } else {
                        P.logger.error('Invalid status response:', { response });
                        $('#error-display').text('Error: Invalid response from server').show();
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Status update failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    
                    // Show error but don't stop updates - they might succeed next time
                    $('#error-display').text('Failed to update status: ' + error).show();
                    
                    // No need to adjust polling frequency since we're using SSE now
                },
                complete: function() {
                    // Only remove loading overlay if it was shown
                    if (showLoading) P.setLoading(false);
                }
            });
        },
        
        // Update operations display
        updateOperationsDisplay: function(activeOperations, queueResponse) {
            // If we don't have any active operations or queue response, just clear the display
            if ((!activeOperations || activeOperations.length === 0) &&
                (!queueResponse || !queueResponse.success || !queueResponse.data || queueResponse.data.length === 0)) {
                
                // Check if we have any recently completed operations to display
                if (P.config.recentlyCompletedOperations && P.config.recentlyCompletedOperations.length > 0) {
                    // Use queue manager's updateOperationsDisplay method with recently completed operations
                    P.queueManager.updateOperationsDisplay([], null);
                } else {
                    // No operations to display
                    $('#active-operations').html('<div class="notice">No active operations</div>');
                }
                
                // Update the active operations flag
                P.config.hasActiveOperations = false;
                
                return;
            }
            
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
            
            // Check if there are active operations from the status endpoint
            const hasActiveOps = (activeOperations && activeOperations.length > 0);
            
            // Check if there are pending or processing operations in the queue
            let hasPendingOps = false;
            let queueActiveOperations = [];
            
            if (queueResponse && queueResponse.success && queueResponse.data) {
                // Find any pending or processing operations in the queue
                queueResponse.data.forEach(op => {
                    if (op.status === 'pending' || op.status === 'processing' || op.status === 'skipped') {
                        hasPendingOps = true;
                        
                        // Add to active operations if not already present
                        if (!activeOperations || !activeOperations.some(activeOp =>
                            activeOp.id === op.id ||
                            (activeOp.operation_type === op.operation_type &&
                             activeOp.parameters?.path === op.parameters?.path)
                        )) {
                            queueActiveOperations.push(op);
                        }
                    }
                });
            }
            
            // Update the active operations flag
            P.config.hasActiveOperations = hasActiveOps || hasPendingOps;
            
            // No need to start or stop updates based on active operations
            // since we're using SSE now instead of polling
            
            // Combine active operations from both sources
            const combinedActiveOperations = activeOperations ? [...activeOperations] : [];
            if (queueActiveOperations.length > 0) {
                combinedActiveOperations.push(...queueActiveOperations);
            }
            
            // Use queue manager's updateOperationsDisplay method with combined operations
            P.queueManager.updateOperationsDisplay(combinedActiveOperations, queueResponse);
            
            // Check if there are any completed operations that need to be processed
            if (queueResponse && queueResponse.success && queueResponse.data) {
                // Process each operation to check for status changes
                queueResponse.data.forEach(function(op) {
                    // Skip if not a relevant operation type
                    if (!P.protectedListOperations || !P.protectedListOperations.includes(op.operation_type)) return;
                    
                    // Get the operation ID
                    const opId = op.id;
                    
                    // Check if we've seen this operation before
                    const lastStatusData = P.config.lastOperationStatus[opId];
                    const lastStatus = lastStatusData ? (typeof lastStatusData === 'string' ? lastStatusData : lastStatusData.status) : null;
                    
                    // If operation is now completed or failed but was previously processing or pending
                    if ((op.status === 'completed' || op.status === 'failed' || op.status === 'skipped') &&
                        lastStatus && (lastStatus === 'processing' || lastStatus === 'pending')) {
                        
                        // Get path from lastStatusData if available
                        if (lastStatusData && typeof lastStatusData === 'object' && lastStatusData.path) {
                            op.path = lastStatusData.path;
                        }
                        
                        // Extract path from parameters for display
                        let path = 'Unknown path';
                        if (op.parameters) {
                            try {
                                const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters;
                                path = params.path || path;
                            } catch (e) {
                                P.logger.error('Error parsing parameters:', { error: e });
                            }
                        }
                        
                        // Add to recently completed operations
                        const completedOp = {
                            id: opId,
                            operation_type: op.operation_type,
                            status: op.status,
                            result: op.result,
                            parameters: op.parameters,
                            path: path, // Store the extracted path directly
                            completedAt: new Date().getTime(),
                            // Display skipped operations for longer (60 seconds) to ensure visibility
                            displayUntil: new Date().getTime() + (op.status === 'skipped' ? 60000 : 15000)
                        };
                        
                        // Add to recently completed operations
                        P.config.recentlyCompletedOperations.push(completedOp);
                        
                        // Trigger operation completed event
                        if (P.events) {
                            P.events.trigger('operation.completed', {
                                id: opId,
                                type: op.operation_type,
                                status: op.status,
                                result: op.result
                            });
                        }
                    }
                    
                    // Extract path if not already present in the operation
                    if (!op.path && op.parameters) {
                        try {
                            const params = typeof op.parameters === 'string' ? JSON.parse(op.parameters) : op.parameters;
                            if (params.path) {
                                op.path = params.path;
                            }
                        } catch (e) {
                            P.logger.error('Error parsing parameters:', { error: e });
                        }
                    }
                    
                    // Update last known status with both status and path
                    P.config.lastOperationStatus[opId] = {
                        status: op.status,
                        path: op.path || (P.config.lastOperationStatus[opId] && typeof P.config.lastOperationStatus[opId] === 'object' ?
                                         P.config.lastOperationStatus[opId].path : null)
                    };
                });
            }
        },

        // Update status display
        updateStatusDisplay: function(data) {
            
            if (!data || !data.stats) {
                P.logger.warning('No data or stats available for status display');
                data = {
                    stats: {
                        total_files: 0,
                        total_size: '0 B',
                        last_verification: 'Never',
                        health: 'unknown'
                    }
                };
            }
            
            const stats = data.stats;
            $('#protected-files').text(stats.total_files || '0');
            $('#protected-size').text(stats.total_size || '0 B');
            $('#last-verification').text(stats.last_verification || 'Never');
            
            const health = stats.health || 'unknown';
            $('#protection-health .health-indicator')
                .text(health.charAt(0).toUpperCase() + health.slice(1))
                .removeClass()
                .addClass('health-indicator ' + health);

            // Update active operations - use the queue manager's updateOperationsDisplay method
            // to ensure consistent display format
            if (data.active_operations && data.active_operations.length > 0) {
                P.queueManager.updateOperationsDisplay(data.active_operations, null);
            } else {
                $('#active-operations').html('<div class="notice">No active operations</div>');
            }
        },

        // Update activity log
        updateActivityLog: function(activities) {
            
            const $tbody = $('#activity-log');
            // Clear existing rows
            $tbody.empty();
            
            if (!activities || !activities.length) {
                $tbody.append(`
                    <tr>
                        <td colspan="5" class="notice">No recent activity</td>
                    </tr>
                `);
            } else {
                activities.forEach(activity => {
                    $tbody.append(`
                        <tr>
                            <td>${activity.time || '-'}</td>
                            <td>${activity.action || '-'}</td>
                            <td>${activity.path === null ? 'N/A' : activity.path || '-'}</td>
                            <td>${activity.status || '-'}</td>
                            <td>${activity.details ? `<i class="fa fa-info-circle" title="${activity.details}"></i>` : '-'}</td>
                        </tr>
                    `);
                });
            }
        },

        // Show protect dialog
        showProtectDialog: function() {
            // Reset folder settings
            this.folderSettings = {};
            
                // Set dialog state flags
                this.isDialogOpen = true;
                this.isFiletreeOpen = false;
                
            // Populate file types
            this.populateFileTypes();
            
            // Clear selected folders table
            this.updateSelectedFoldersTable();
            
                // Adjust content height for dialog
                this.adjustContentHeight('dialog');
                
            $('#protect-dialog').show();
        },

        // Close dialog
        closeDialog: function() {
            $('#protect-dialog').hide();
                
                // Reset dialog state flags
                this.isDialogOpen = false;
                this.isFiletreeOpen = false;
                
                // Reset content height
                this.adjustContentHeight('normal');
        },

        // Populate file type checkboxes
        populateFileTypes: function() {
            let html = '';
            
            // Get custom extensions from server settings if available
            let customExtensions = {};
            if (P.config.serverSettings && 
                P.config.serverSettings.file_types &&
                P.config.serverSettings.file_types.custom_extensions) {
                customExtensions = P.config.serverSettings.file_types.custom_extensions;
            }
            
            Object.entries(P.config.fileCategories).forEach(([key, category]) => {
                // Merge default extensions with custom extensions
                let allExtensions = [...category.extensions];
                if (customExtensions && customExtensions[key] && Array.isArray(customExtensions[key])) {
                    allExtensions = [...allExtensions, ...customExtensions[key]];
                }
                
                // Create checkbox with all extensions
                html += `
                    <label>
                        <input type="checkbox" name="file_types[]" value="${key}" data-extensions="${allExtensions.join(',')}">
                        ${category.description}
                    </label>
                `;
            });
            $('#file-types').html(html);
        },

        // Update mode options
        updateModeOptions: function(mode) {
            // Show file type options only when "file" mode is selected
            $('#file-type-group').toggle(mode === 'file');            
        },

        // Setup event listeners
        setupEventListeners: function() {
            // Protection form submission
            $('#protect-form').on('submit', function(e) {
                e.preventDefault();
            });
            
            // Advanced settings toggle
            $('#advanced-settings-toggle').on('change', function() {
                $('#advanced-settings-panel').toggle(this.checked);
                
                // Adjust content height when advanced settings are toggled
                if (dashboard.isDialogOpen) {
                    dashboard.adjustContentHeight(dashboard.isFiletreeOpen ? 'filetree' : 'dialog');
                }
            });
            
            // Add to List button
            $('#add-to-list-btn').on('click', function() {
                dashboard.addFolderToList();
            });
            
            // Start Protection button
            $('#start-protection-btn').on('click', function() {
                dashboard.startProtection();
            });
            
            // Redundancy slider value update
            $('#redundancy-slider').on('input change', function() {
                $('.redundancy-value').text($(this).val());
            });
            
            // Handle mode change
            $('#protection-mode').on('change', function() {
                dashboard.updateModeOptions(this.value);
            });
            
            // Initialize with default mode
            this.updateModeOptions($('#protection-mode').val());
            
            // We'll set the redundancy slider value when settings are loaded
        },
        
        // Add folder to the selected folders list
        addFolderToList: function() {
            const paths = $('#protectedPaths').val();
                const hadItems = Object.keys(this.folderSettings).length > 0;
                
            if (!paths || !paths.trim()) {
                swal('Error', 'Please select at least one folder to protect', 'error');
                return;
            }
            
            // Get settings from form
            const mode = $('#protection-mode').val();
            const redundancy = $('#redundancy-slider').val();
            
            // Get selected file types
            const selectedTypes = [];
            const selectedCategories = [];
            $('input[name="file_types[]"]:checked').each(function() {
                selectedCategories.push($(this).val());
                const extensions = $(this).data('extensions').split(',');
                selectedTypes.push(...extensions);
            });
            
            // Get advanced settings if enabled
            const hasAdvanced = $('#advanced-settings-toggle').is(':checked');
            const advanced = hasAdvanced ? {
                blockCount: $('#block-count').val() || null,
                blockSize: $('#block-size').val() || null,
                targetSize: $('#target-size').val() || null
            } : null;
            
            // Add each path to the folder settings
            const pathList = paths.split('\n').filter(Boolean);
            pathList.forEach(path => {
                this.folderSettings[path] = {
                    mode: mode,
                    redundancy: redundancy,
                    fileTypes: mode === 'file' ? selectedCategories : [],
                    advanced: advanced
                };
            });
            
            // Update the table
            this.updateSelectedFoldersTable();
            
            // Clear the file tree selection
            $('#protectedPaths').val('');
            
            // Update the paths list
            const $list = $('#protectedPaths-list');
            if (typeof updatePathsList === 'function') {
                updatePathsList($('#protectedPaths'), $list);
            } else {
                $list.html('<div class="empty-list-message">No paths selected. Click here to select paths.</div>');
            }
            
            // Uncheck all checkboxes in the file tree
            if (typeof window.uncheckAllFileTreeCheckboxes === 'function') {
                window.uncheckAllFileTreeCheckboxes();
            }
                
                // Force height adjustment if items were added
                if (!hadItems && Object.keys(this.folderSettings).length > 0) {
                    // Force a small delay to ensure the DOM has updated
                    setTimeout(() => {
                        this.adjustContentHeight(this.isFiletreeOpen ? 'filetree' : 'dialog');
                    }, 50);
                }
        },
        
        // Update the selected folders table
        updateSelectedFoldersTable: function() {
            const $tbody = $('#selected-folders-body');
            const hadItems = !$tbody.find('.empty-row').length;
            $tbody.empty();
            
                // Get current state
                const isFiletreeVisible = this.isFiletreeOpen;
                
            const paths = Object.keys(this.folderSettings);
            const hasItems = paths.length > 0;
            
            if (paths.length === 0) {
                $tbody.html('<tr class="empty-row"><td colspan="6">No folders selected yet</td></tr>');
                    
                    // Adjust height if dialog is open
                    if (this.isDialogOpen) {
                        this.adjustContentHeight(isFiletreeVisible ? 'filetree' : 'dialog');
                    }
                return;
            }
            
            paths.forEach(path => {
                const settings = this.folderSettings[path];
                $tbody.append(this.createFolderRow(path, settings));
            });
                
                // Adjust height if dialog is open
                if (this.isDialogOpen) {
                    // Force a small delay to ensure the DOM has updated
                    setTimeout(() => {
                    this.adjustContentHeight(isFiletreeVisible ? 'filetree' : 'dialog');
                    }, 50);
                }
        },
        
        // Create a row for the selected folders table
        createFolderRow: function(path, settings) {
            return `
                <tr data-path="${path}">
                    <td>${path}</td>
                    <td>${settings.mode === 'directory' ? 'Directory' : 'Individual Files'}</td>
                    <td>${settings.mode === 'file' ? settings.fileTypes.join(', ') : 'All'}</td>
                    <td>${settings.redundancy}%</td>
                    <td>${settings.advanced ? 'Yes' : 'No'}</td>
                    <td>
                        <button type="button" class="btn-cancel remove-folder" onclick="Par2Protect.dashboard.removeFolder('${path}')">
                            Remove
                        </button>
                    </td>
                </tr>
            `;
        },
        
        // Remove a folder from the selected folders list
        removeFolder: function(path) {
            delete this.folderSettings[path];
            this.updateSelectedFoldersTable(); // This will now adjust the height as needed
        },
        
        // Start protection for all selected folders
        startProtection: function() {
            const paths = Object.keys(this.folderSettings);
            if (paths.length === 0) {
                swal('Error', 'Please add at least one folder to the list', 'error');
                return;
            }
            
            P.setLoading(true);
            
            // Process each folder with its settings
            let processedCount = 0;
            let successCount = 0;
            let errorCount = 0;
            
            paths.forEach(path => {
                const settings = this.folderSettings[path];
                
                // Get file types if in file mode
                const selectedTypes = [];
                const selectedCategories = settings.fileTypes || [];
                
                if (settings.mode === 'file' && selectedCategories.length > 0) {
                    selectedCategories.forEach(category => {
                        const $checkbox = $(`input[name="file_types[]"][value="${category}"]`);
                        if ($checkbox.length) {
                            const extensions = $checkbox.data('extensions').split(',');
                            selectedTypes.push(...extensions);
                        }
                    });
                }
                
                const parameters = {
                    path: path,
                    mode: settings.mode === 'file' ? 'Individual Files' : 'directory',
                    redundancy: settings.redundancy,
                    file_types: selectedTypes,
                    file_categories: selectedCategories
                };
                
                // Add advanced parameters if present
                if (settings.advanced) {
                    if (settings.advanced.blockCount) parameters.block_count = settings.advanced.blockCount;
                    if (settings.advanced.blockSize) parameters.block_size = settings.advanced.blockSize;
                    if (settings.advanced.targetSize) parameters.target_size = settings.advanced.targetSize;
                    
                    // Also add the advanced settings as a nested object for compatibility
                    parameters.advanced_settings = {
                        block_count: settings.advanced.blockCount || null,
                        block_size: settings.advanced.blockSize || null,
                        target_size: settings.advanced.targetSize || null
                    };
                    
                    // Log the advanced settings for debugging
                    P.logger.debug('Adding advanced settings to protection parameters:', {
                        block_count: settings.advanced.blockCount || null,
                        block_size: settings.advanced.blockSize || null,
                        target_size: settings.advanced.targetSize || null
                    });
                }
                
                // Use queue manager to add protection to queue
                P.queueManager.addToQueue(
                    'protect',
                    parameters,
                    function(response) {
                        processedCount++;
                        successCount++;
                        checkCompletion();
                    },
                    function(error) {
                        processedCount++;
                        errorCount++;
                        P.logger.error('Failed to add protection to queue:', { path, error });
                        checkCompletion();
                    }
                );
            });
            
            // Check if all folders have been processed
            function checkCompletion() {
                if (processedCount === paths.length) {
                    dashboard.closeDialog();
                    // No need to start status updates since we're using SSE now
                    
                    if (errorCount === 0) {
                        swal({
                            title: 'Protection Started',
                            text: `Protection tasks for ${successCount} folder(s) have been added to the queue`,
                            type: 'success'
                        });
                    } else {
                        swal({
                            title: 'Protection Started with Errors',
                            text: `Successfully added ${successCount} folder(s) to the queue, ${errorCount} failed`,
                            type: 'warning'
                        });
                    }
                    
                    P.setLoading(false);
                }
            }
        },

        // Cancel an operation
        cancelOperation: function(pid) {
            if (!pid) {
                return;
            }
            
            if (P.config.isLoading) {
                P.logger.debug('Cancel operation skipped - already loading');
                return;
            }
            
            P.logger.debug('Cancelling operation with PID:', { pid });
            P.setLoading(true);
            
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue&id=' + pid,
                method: 'POST',
                data: { _method: 'DELETE' },
                dataType: 'json',
                success: function(response) {
                    
                    // Check if the response has the refresh_list flag
                    if (response.success && P.handleDirectOperationResponse) {
                        P.handleDirectOperationResponse(response);
                    }
                    
                    if (response.success) {
                        swal('Success', 'Operation cancelled successfully', 'success');
                        // Update status to reflect the cancellation - show loading for manual update
                        // but only if we don't have active operations
                        if (!P.config.hasActiveOperations) {
                            dashboard.updateStatus(true);
                        }
                    } else {
                        swal('Error', response.error || 'Failed to cancel operation', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Cancel operation failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    swal('Error', 'Failed to cancel operation: ' + error, 'error');
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        },
        
        startVerification: function(target, force = false, id = null) {
            // Check if we're using the "Verify All" button
            const isVerifyAll = target === 'all';
            
            if (P.config.isLoading) return;
            
            P.setLoading(true);
            
            // Special case for 'all' - get all protected items and verify each one
            if (isVerifyAll) {
                // Hide any existing error messages
                $('#error-display').hide();
                
                // First get all protected items
                $.ajax({
                    url: '/plugins/par2protect/api/v1/index.php?endpoint=protection',
                    method: 'GET',
                    dataType: 'json',
                    timeout: 30000, // 30 second timeout
                    success: function(response) {
                        
                        // Check if the response has the refresh_list flag
                        if (response.success && P.handleDirectOperationResponse) {
                            P.handleDirectOperationResponse(response);
                        }
                        
                        if (response.success && response.data && response.data.length > 0) {
                            const items = response.data;
                            
                            // Add verification task for each item
                            let addedCount = 0;
                            let failedCount = 0;
                            
                            // Track how many operations we've added
                            let operationsAdded = 0;
                            let operationsFailed = 0;
                            
                            // Process each item in parallel (similar to how Force verify selected works)
                            items.forEach(function(item) {
                                const itemPath = item.path;
                                const itemId = item.id;
                                
                                // Add verification task for this item
                                P.queueManager.addToQueue(
                                    'verify',
                                    {
                                        path: itemPath,
                                        id: itemId,
                                        force: true // Always force verification for "Verify All" button
                                    },
                                    function(response) {
                                        operationsAdded++;
                                        
                                        // Check if all operations have been processed
                                        if (operationsAdded + operationsFailed === items.length) {
                                            // All operations have been processed
                                            const message = 'Added ' + operationsAdded + ' verification tasks to queue' +
                                                          (operationsFailed > 0 ? ', ' + operationsFailed + ' failed' : '');
                                            
                                            swal({
                                                title: 'Verification Started',
                                                text: message,
                                                type: 'info'
                                            });
                                            
                                            addNotice(message);
                                            // No need to start status updates since we're using SSE now
                                            P.setLoading(false);
                                        }
                                    },
                                    function(error) {
                                        P.logger.error('Failed to add verification to queue for item:', { itemPath, error });
                                        operationsFailed++;
                                        
                                        // Check if all operations have been processed
                                        if (operationsAdded + operationsFailed === items.length) {
                                            // All operations have been processed
                                            const message = 'Added ' + operationsAdded + ' verification tasks to queue' +
                                                          (operationsFailed > 0 ? ', ' + operationsFailed + ' failed' : '');
                                            
                                            swal({
                                                title: 'Verification Started',
                                                text: message,
                                                type: 'info'
                                            });
                                            
                                            addNotice(message);
                                            // No need to start status updates since we're using SSE now
                                            P.setLoading(false);
                                        }
                                    }
                                );
                            });
                        } else {
                            swal({
                                title: 'No Items',
                                text: 'No protected items found to verify',
                                type: 'warning'
                            });
                            P.setLoading(false);
                        }
                    },
                    error: function(xhr, status, error) {
                        P.logger.error('Failed to get protected items:', {
                            status: status,
                            error: error,
                            response: xhr.responseText,
                            state: xhr.state(),
                            statusCode: xhr.status,
                            statusText: xhr.statusText
                        });
                        
                        let errorMessage = 'Failed to get protected items: ' + error;
                        
                        // Try to extract more detailed error information
                        try {
                            if (xhr.responseText) {
                                const response = JSON.parse(xhr.responseText);
                                if (response.error) {
                                    errorMessage = response.error;
                                }
                            }
                        } catch (e) {
                            P.logger.error('Error parsing error response:', { error: e });
                        }
                        
                        swal({
                            title: 'Error',
                            text: errorMessage,
                            type: 'error'
                        });
                        P.setLoading(false);
                    }
                });
            } else {
                // Regular case - verify a single item
                // If this is being called with target='all' directly (not through the "Verify All" button),
                // we need to prevent the error message from appearing
                if (isVerifyAll) {
                    P.setLoading(false);
                    return;
                }
                
                P.queueManager.addToQueue(
                    'verify',
                    {
                        path: target,
                        id: id,
                        force: force
                    },
                    function(response) {
                        
                        swal({
                            title: 'Verification Started',
                            text: 'Verification task has been added to the queue',
                            type: 'info'
                        });
                        
                        // No need to start status updates since we're using SSE now
                        P.setLoading(false);
                    },
                    function(error) {
                        P.logger.error('Failed to add verification to queue:', { error });
                        
                        swal({
                            title: 'Error',
                            text: 'Failed to start verification: ' + error,
                            type: 'error'
                        });
                        
                        P.setLoading(false);
                    }
                );
            }
        }
    };

    // Handle direct operation response
    P.handleDirectOperationResponse = function(response) {
        
        // Check if the response has the refresh_list flag
        if (response && response.success && response.data && response.data.refresh_list) {
            P.logger.debug('Response has refresh_list flag, triggering operation completed event');
            
            // Get operation type from the response
            const operationType = response.data.operation_type || 'unknown';
            
            // Trigger the operation completed event
            P.triggerOperationCompleted(
                operationType,
                'completed',
                response.data
            );
        }
    };

    // Add dashboard methods to Par2Protect
    P.dashboard = dashboard;

    // Add global function for filetree picker to adjust content height
    P.adjustContentForFiletree = function(isVisible) {
        if (P.dashboard) {
            // Update filetree state flag
            P.dashboard.isFiletreeOpen = isVisible;
            
            // Adjust content height
            P.dashboard.adjustContentHeight(isVisible ? 'filetree' : 'dialog');
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.par2-dashboard')) {
            // Initialize event listeners for operation completion
            if (P.events && !P.events.initialized) {
                // Listen for operation completion events
                P.events.on('operation.completed', function(data) {
                    
                    // Refresh dashboard status - don't show loading for automatic updates
                    // but only if we don't have active operations
                    if (P.dashboard && !P.config.hasActiveOperations) {
                        P.dashboard.updateStatus(false);
                    }
                });
                
                P.events.initialized = true;
            }
            
            P.dashboard.initDashboard();
        }
    });

})(window.Par2Protect);