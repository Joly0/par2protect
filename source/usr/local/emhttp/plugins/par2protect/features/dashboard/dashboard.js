// Dashboard Functionality

(function(P) {
    // Debug logging is now disabled in production

    // Store original startVerification before defining dashboard object
    const originalStartVerification = function() {
        // This function is now only used for initial status check
        // and for manual refreshes, not for polling

        // First, ensure any existing timer is cleared
        this.stopStatusUpdates(); // 'this' will refer to dashboard object when called via .call()

        // Only update once
        if (!P.config.isLoading) {
            this.updateStatus(false); // 'this' will refer to dashboard object when called via .call()
        }
    };

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
            // console.log('Adjusting height for state:', state, 'itemCount:', itemCount, 'advancedVisible:', advancedVisible, 'hasProtectedPaths:', hasProtectedPaths);

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
            P.logger.info('Initializing dashboard...'); // Re-enable info log for init

            // Explicitly clear recently completed on init
            if (P.config && P.config.recentlyCompletedOperations) {
                P.logger.debug('Clearing recentlyCompletedOperations on dashboard init.');
                P.config.recentlyCompletedOperations = [];
            } else {
                 P.logger.warning('P.config.recentlyCompletedOperations not found on init.');
                 // Ensure it exists if it didn't
                 if (!P.config) P.config = {};
                 P.config.recentlyCompletedOperations = [];
            }


            try {
                // Initial status check - show loading for initial load
                this.updateStatus(true);

                // Setup event listeners
                this.setupEventListeners();

                // We'll only start status updates if needed based on the initial status check
                // The updateStatus function will trigger startStatusUpdates if active operations are found

                // P.logger.info('Dashboard initialization complete');
            } catch (e) {
                P.logger.error('Failed to initialize dashboard:', { error: e });
                throw e;
            }
        },

        // Start status updates
        // New startVerification logic (integrating override)
        startVerification: function(target, force = false, id = null) {
            // For 'all' target, show options dialog
            if (target === 'all') {
                $('#error-display').hide();
                // 'this' correctly refers to the dashboard object here
                this.showVerificationOptionsDialog(target, force, id);
                return;
            }
            // For other targets, call the original implementation
            // Use .call(this, ...) to maintain the correct 'this' context
            originalStartVerification.call(this, target, force, id);
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
            // Debug logging is now disabled in production

            if (P.config.isLoading) {
                return;
            }

            // Rate limiting for automatic updates
            if (!showLoading) {
                const now = new Date().getTime();
                if (!this.lastStatusUpdateTime) {
                    this.lastStatusUpdateTime = 0;
                }

                // If this is an automatic update and it's too soon after the last update, skip it
                // Increase the minimum time between automatic updates from 300ms to 3000ms (3 seconds)
                if ((now - this.lastStatusUpdateTime < 3000) && !showLoading) {
                    P.logger.debug('Skipping status update - too soon after previous update', {
                        'last_update': this.lastStatusUpdateTime,
                        'now': now,
                        'diff_ms': now - this.lastStatusUpdateTime
                    });
                    return;
                }
                this.lastStatusUpdateTime = now;
            }

            // Only show loading overlay for manual updates, not automatic ones
            if (showLoading) {
                P.setLoading(true);
            }

            // Track request start time for performance monitoring
            const requestStartTime = performance.now();

            // Make a single API call that includes both queue and system status
            // This reduces the number of separate requests and database connections
            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=status',
                method: 'GET',
                timeout: 5000,
                dataType: 'json',
                // Add component identifier
                data: {
                    _component: 'dashboard',
                    _caller: 'updateStatus',
                    _manual: showLoading ? 'true' : 'false', // Indicate if this is a manual update
                    include_queue: 'true', // Request queue status in the same call
                    include_protection: 'true' // Request protection data in the same call
                },
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

                        // Extract queue response from the combined response
                        const queueResponse = response.data.queue || { success: true, data: [] };

                        // Update active operations using the queue data from the combined response
                        dashboard.updateOperationsDisplay(response.data.active_operations, queueResponse);

                        // If protection data is included, update the protected files list
                        if (response.data.protected_items && typeof P.list !== 'undefined' && P.list.updateProtectedList) {
                            P.logger.debug("Using protection data from status response", {
                                'items_count': response.data.protected_items.length
                            });
                            P.list.updateProtectedList(response.data.protected_items);
                        }

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
                    // Debug logging is now disabled in production

                    // Force hide spinner with direct DOM manipulation as a fallback
                    $('.loading-overlay').remove();

                    // Only remove loading overlay if it was shown
                    if (showLoading) {
                        P.setLoading(false);

                        // Double-check that loading state is cleared
                        setTimeout(function() {
                            if ($('.loading-overlay').length > 0) {
                                $('.loading-overlay').remove();
                            }
                        }, 500);
                    }
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
                            displayUntil: new Date().getTime() + (op.status === 'skipped' ? 60000 : 30000) // Show completed operations for 30 seconds
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
                    // Escape details for data attribute
                    const escapedDetails = activity.details ? P.escapeHtml(activity.details) : '';
                    // Add data-details attribute and trigger class, remove title attribute
                    const detailsIcon = activity.details
                        ? `<i class="fa fa-info-circle activity-details-trigger" data-details="${escapedDetails}" style="cursor:pointer;"></i>`
                        : '-';

                    $tbody.append(`
                        <tr>
                            <td>${activity.time || '-'}</td>
                            <td>${activity.action || '-'}</td>
                            <td>${activity.path === null ? 'N/A' : P.escapeHtml(activity.path) || '-'}</td>
                            <td>${activity.status || '-'}</td>
                            <td>${detailsIcon}</td>
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
            const self = this; // Reference to dashboard object for callbacks

            // Click handler for activity details icon
            $('#activity-log').on('click', '.activity-details-trigger', function(e) {
                e.preventDefault();
                const details = $(this).data('details');
                if (details) {
                    // Use SweetAlert to show details, allow HTML
                    swal({
                        title: "Activity Details",
                        text: `<div style="max-height: 300px; overflow-y: auto; text-align: left;"><pre style="white-space: pre-wrap; word-wrap: break-word;">${details}</pre></div>`,
                        html: true,
                        customClass: 'wide-swal' // Optional: Use wider class if needed
                    });
                }
            });

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
        }, // <-- Add comma here

        // === Integrated from fix-queue-button.js ===
        // Kill a stuck operation
        killStuckOperation: function(operationId) {
            if (P.config.isLoading) {
                P.logger.debug('Kill operation skipped - already loading');
                return;
            }

            P.logger.debug('Killing stuck operation:', { operationId });
            P.setLoading(true);

            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=queue/kill',
                method: 'POST',
                data: {
                    operation_id: operationId
                },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    P.logger.debug('Kill operation response:', { response });

                    if (response.success) {
                        swal({
                            title: 'Success',
                            text: response.message || 'Stuck operation killed successfully',
                            type: 'success'
                        });

                        // Update status to reflect changes
                        if (P.dashboard) {
                            P.dashboard.updateStatus(true);
                        }
                    } else {
                        swal({
                            title: 'Error',
                            text: response.error || 'Failed to kill stuck operation',
                            type: 'error'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Kill operation request failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });

                    swal({
                        title: 'Error',
                        text: 'Failed to kill stuck operation: ' + error,
                        type: 'error'
                    });
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        }, // <-- Add comma here

        // === Integrated from verification-options.js ===
        // Show verification options dialog
        showVerificationOptionsDialog: function(target, force = false, id = null) {
            // Reset form
            $('#dashboard-verify-metadata-checkbox').prop('checked', false);
            $('#dashboard-auto-restore-metadata-checkbox').prop('checked', false);
            $('#dashboard-auto-restore-group').hide();

            // Store verification parameters for later use
            this.verificationTarget = target;
            this.verificationForce = force;
            this.verificationId = id;

            // Show dialog
            $('#dashboard-verification-options-dialog').show();
        }, // <-- Add comma here

        // Execute verification with options
        executeVerification: function(verifyMetadata = false, autoRestoreMetadata = false) {
            const target = this.verificationTarget;
            const force = this.verificationForce;
            const id = this.verificationId;

            if (P.config.isLoading) return;

            P.setLoading(true);

            // Special case for 'all' - get all protected items and verify each one
            if (target === 'all') {

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

                            // Process each item in parallel
                            items.forEach(function(item) {
                                const itemPath = item.path;
                                const itemId = item.id;

                                // Add verification task for this item
                                P.queueManager.addToQueue(
                                    'verify',
                                    {
                                        path: itemPath,
                                        id: itemId,
                                        force: true, // Always force verification for "Verify All" button
                                        verify_metadata: verifyMetadata,
                                        auto_restore_metadata: autoRestoreMetadata
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

                                            // P.dashboard.startStatusUpdates(); // Original line - keep?
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

                                            // P.dashboard.startStatusUpdates(); // Original line - keep?
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
                            response: xhr.responseText
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
                P.queueManager.addToQueue(
                    'verify',
                    {
                        path: target,
                        id: id,
                        force: force,
                        verify_metadata: verifyMetadata,
                        auto_restore_metadata: autoRestoreMetadata
                    },
                    function(response) {

                        swal({
                            title: 'Verification Started',
                            text: 'Verification task has been added to the queue',
                            type: 'info'
                        });

                        // P.dashboard.startStatusUpdates(); // Original line - keep?
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
        }, // <-- ADD comma after executeVerification

        // Add startVerification method (integrating override logic)
        startVerification: function(target, force = false, id = null) {
            // For 'all' target, show options dialog
            if (target === 'all') {
                $('#error-display').hide();
                // 'this' correctly refers to the dashboard object here
                this.showVerificationOptionsDialog(target, force, id); // Assumes showVerificationOptionsDialog is also added to this object
                return;
            }

            // For other targets (single path/id), directly add to queue
            // (Mimicking the logic found in the 'executeVerification' method for non-'all' case)
            // Ensure P and P.queueManager exist
             const logFunc = (typeof P !== 'undefined' && P.logger) ? P.logger.error : console.error;
             if (typeof P === 'undefined' || typeof P.queueManager === 'undefined') {
                 logFunc("QueueManager is not available to start verification.");
                 swal('Error', 'Cannot start verification. QueueManager not found.', 'error');
                 return;
             }

            P.setLoading(true); // Show loading for single verification too
            P.queueManager.addToQueue(
                'verify',
                { path: target, id: id, force: force }, // Pass necessary params
                function(response) {
                    swal('Verification Started', 'Verification task added to queue.', 'info');
                    P.setLoading(false);
                },
                function(error) {
                    swal('Error', 'Failed to start verification: ' + error, 'error');
                    P.setLoading(false);
                }
            );
        } // <-- Closing brace for startVerification method

    }; // End of dashboard object definition

    // Handle direct operation response
    P.handleDirectOperationResponse = function(response) {

        // Check if the response has the refresh_list flag
        if (response && response.success && response.data && response.data.refresh_list) {
            P.logger.debug('Response has refresh_list flag, but not triggering additional events');

            // We no longer trigger additional events here to prevent cascading refreshes
            // This prevents the loop of: event -> refresh -> response with refresh_list -> event -> refresh...

            // Instead, we'll just log that we received a response with the refresh_list flag
            const operationType = response.data.operation_type || 'unknown';
            P.logger.debug('Received response with refresh_list flag', {
                operation_type: operationType,
                source: '_caller' in response.data ? response.data._caller : 'unknown'
            });
        }
    };

    // Add dashboard methods to Par2Protect
    P.dashboard = dashboard;

    // === Override logic integrated directly into the dashboard object definition above ===

    // Add global function for filetree picker to adjust content height
    P.adjustContentForFiletree = function(isVisible) {
        if (P.dashboard) {
            // Update filetree state flag
            P.dashboard.isFiletreeOpen = isVisible;

            // Adjust content height
            P.dashboard.adjustContentHeight(isVisible ? 'filetree' : 'dialog');
        }
    };
    // === Override logic moved up ===

    // === Override startVerification (from verification-options.js) ===
    if (P.dashboard && typeof P.dashboard.startVerification === 'function') { // Ensure dashboard and method exist
        const originalStartVerification = P.dashboard.startVerification;
        P.dashboard.startVerification = function(target, force = false, id = null) {
            // For 'all' target, we need to prevent the error message from appearing
            if (target === 'all') {
                // Hide any existing error display
                $('#error-display').hide();

                // Show verification options dialog
                // Use 'this' which refers to P.dashboard
                this.showVerificationOptionsDialog(target, force, id);
                return;
            }

            // For other targets, use the original implementation
            // Use 'this' which refers to P.dashboard
            originalStartVerification.call(this, target, force, id);
        };
    } else {
         P.logger.error("Could not override P.dashboard.startVerification. It might not exist yet.");
    }


    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.par2-dashboard')) {
            // Debounce function to prevent multiple calls in quick succession
            if (!P.debounce) {
                P.debounce = function(func, wait) {
                    let timeout;
                    return function() {
                        const context = this;
                        const args = arguments;
                        clearTimeout(timeout);
                        timeout = setTimeout(function() {
                            func.apply(context, args);
                        }, wait);
                    };
                };
            }

            // Create debounced version of updateStatus if not already created
            if (!P.dashboard.debouncedUpdateStatus) {
                P.dashboard.debouncedUpdateStatus = P.debounce(function(showLoading) {
                    // Debug logging is now disabled in production

                    // Force hide spinner with direct DOM manipulation as a fallback
                    if ($('.loading-overlay').length > 0) {
                        $('.loading-overlay').remove();
                    }

                    P.dashboard.updateStatus(showLoading);
                }, 3000); // Increase debounce time to 3 seconds to prevent operations from disappearing too quickly
            }

            // Initialize event listeners for operation completion
            // Use a more robust check to prevent duplicate event registration
            if (P.events && !P.dashboard.eventsInitialized) {
                // Debug logging is now disabled in production

                // Store the event handler as a named function to make it easier to debug
                P.dashboard.operationCompletedHandler = function(data) {
                    // Debug logging is now disabled in production

                    // Skip update if this event is from SSE reconnection or direct operation
                    if (data._during_reconnection) {
                        return;
                    }

                    // Always update status when operations complete
                    if (P.dashboard) {
                        // Only show loading indicator for non-SSE events
                        const showLoading = data._source !== 'sse';
                        P.dashboard.debouncedUpdateStatus(showLoading);
                    }
                };

                // Register the event handler
                P.events.on('operation.completed', P.dashboard.operationCompletedHandler);

                // Mark dashboard events as initialized to prevent duplicate registration
                P.dashboard.eventsInitialized = true;
            }

            // === Add event listeners for verification dialog (from verification-options.js) ===
            if (document.querySelector('#dashboard-verification-options-dialog')) {
                // Add event listeners for the dialog
                $('#dashboard-verify-metadata-checkbox').on('change', function() {
                    $('#dashboard-auto-restore-group').toggle(this.checked);
                });

                $('#dashboard-verification-options-dialog .cancel-btn').on('click', function() {
                    $('#dashboard-verification-options-dialog').hide();
                });

                $('#dashboard-verification-options-form').on('submit', function(e) {
                    e.preventDefault();

                    // Get options
                    const verifyMetadata = $('#dashboard-verify-metadata-checkbox').is(':checked');
                    const autoRestoreMetadata = $('#dashboard-auto-restore-metadata-checkbox').is(':checked');

                    // Hide dialog
                    $('#dashboard-verification-options-dialog').hide();

                    // Start verification with options
                    // Ensure P.dashboard exists before calling
                    if (P.dashboard) {
                         P.dashboard.executeVerification(verifyMetadata, autoRestoreMetadata);
                    }
                });
            }
            // === End verification dialog listeners ===


            P.dashboard.initDashboard();
        }
    });

})(window.Par2Protect);